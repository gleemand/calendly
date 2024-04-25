<?php

use GuzzleHttp\Exception\GuzzleException;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use RetailCrm\Api\Enum\Customers\CustomerType;
use RetailCrm\Api\Exception\Client\BuilderException;
use RetailCrm\Api\Factory\SimpleClientFactory;
use RetailCrm\Api\Interfaces\ApiExceptionInterface;
use RetailCrm\Api\Interfaces\ClientExceptionInterface;
use RetailCrm\Api\Model\Entity\Customers\Customer;
use RetailCrm\Api\Model\Entity\Customers\CustomerPhone;
use RetailCrm\Api\Model\Entity\Orders\Order;
use RetailCrm\Api\Model\Entity\Orders\SerializedRelationCustomer;
use RetailCrm\Api\Model\Filter\Customers\CustomerFilter;
use RetailCrm\Api\Model\Request\Customers\CustomersCreateRequest;
use RetailCrm\Api\Model\Request\Customers\CustomersRequest;
use RetailCrm\Api\Model\Request\Orders\OrdersCreateRequest;
use RetailCrm\Api\Model\Request\Users\UsersRequest;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config.php';

const CALENDLY_URL = 'https://api.calendly.com/';
const AMPLITUDE_URL = 'https://api2.amplitude.com/2/httpapi';
const WEBHOOK_LOCK = '_webhook';

global $logger, $baseClient;
$logger = new Logger('Log');
$handler = new RotatingFileHandler(__DIR__ . '/log/log.log', 30, Logger::DEBUG);

try {
    $baseClient = SimpleClientFactory::createClient(CRM_URL, CRM_KEY);
} catch (BuilderException $exception) {
    $logger->error(
        sprintf('Error from RetailCRM API: %s',
            $exception->getMessage())
    );
}

$id = uniqid();
$output = "%datetime% > $id > %level_name% > %message% %context% %extra%\n";
$formatter = new LineFormatter($output, 'd-M-Y H:i:s', false, true);

$handler->setFormatter($formatter);
$logger->pushHandler($handler);

function getHttpClient() {
    return new GuzzleHttp\Client(['base_uri' => CALENDLY_URL]);
}

function webhookSubscribe() {
    global $logger;

    $user = getCurrentUserData();

    try {
        $response = json_decode(getHttpClient()->request('POST', 'webhook_subscriptions', [
            'json' => [
                'url' => WEBHOOK_URL,
                'events' => [
                    'invitee.created',
                ],
                'user' => $user['uri'] ?? null,
                'organization' => $user['current_organization'] ?? null,
                'scope' => 'organization',
            ],
            'headers' => [
                'Authorization' => 'Bearer ' . CALENDLY_TOKEN,
            ],
        ])->getBody()->__toString(), true);

        return $response['resource'];
    } catch (\Exception|GuzzleException $e) {
        $logger->error($e->getMessage());

        exit;
    }
}

function getCurrentUserData() {
    global $logger;

    try {
        $response = json_decode(getHttpClient()->request('GET', 'users/me', [
            'headers' => [
                'Authorization' => 'Bearer ' . CALENDLY_TOKEN,
            ],
        ])->getBody()->__toString(), true);

        return $response['resource'];
    } catch (\Exception|GuzzleException $e) {
        $logger->error($e->getMessage());

        exit;
    }
}

/**
 * @return array<string, mixed>
 */
function getPayloadData($payload): array
{
    $data = [];

    if ($value = $payload['email']) {
        $data['email'] = $value;
    }

    if ($value = $payload['name']) {
        $data['name'] = $value;
    }

    $q_and_a = $payload['questions_and_answers'];

    if ($q_and_a) {
        if ($value = $q_and_a[0]) {
            $data['phone'] = $value['answer'];
        }

        if ($value = $q_and_a[1]) {
            $data[SCORING_CUSTOM_FIELD] = $value['answer'];
        }

        if ($value = $q_and_a[2]) {
            $data['customerComment'] = $value['answer'];
        }
    }

    $scheduled_event = $payload['scheduled_event'];

    if ($scheduled_event && $value = $scheduled_event['event_memberships'][0]) {
        $data['meetingHost'] = $value['user_email'];
    }

    return $data;
}

function getCustomerByEmail(string $email): ?int
{
    global $baseClient, $logger;

    $request = new CustomersRequest();
    $request->filter = new CustomerFilter();
    $request->filter->email = $email;

    try {
        $response = $baseClient->customers->list($request);
    } catch (ApiExceptionInterface $exception) {
        $logger->error(
            sprintf(
                'Get customer error from RetailCRM API (status code: %d): %s',
                $exception->getStatusCode(),
                $exception->getMessage()
            )
        );

        if (count($exception->getErrorResponse()->errors) > 0) {
            $logger->error(PHP_EOL . 'Errors: ' . implode(', ', $exception->getErrorResponse()->errors));
        }
    } catch (ClientExceptionInterface $exception) {
        $logger->error(
            sprintf('Get customer error from RetailCRM API: %s',
                $exception->getMessage())
        );
    }

    if (isset($response)) {
        foreach ($response->customers as $customer) {
            if ($customer->email === $email) {
                $logger->debug('Customer found');

                return $customer->id;
            }
        }
    }
    $logger->debug('Customer not found');

    return null;
}

function createNewCustomer(array $data): ?int
{
    global $baseClient, $logger;

    $request = new CustomersCreateRequest();
    $request->site = SITE;
    $request->customer = new Customer();

    $request->customer->firstName = $data['name'];
    $request->customer->email = $data['email'];
    $request->customer->phones[] = new CustomerPhone($data['phone']);

    try {
        $response = $baseClient->customers->create($request);
    } catch (ApiExceptionInterface $exception) {
        $logger->error(
            sprintf(
                'Create customer error from RetailCRM API (status code: %d): %s',
                $exception->getStatusCode(),
                $exception->getMessage()
            )
        );

        if (count($exception->getErrorResponse()->errors) > 0) {
            $logger->error(PHP_EOL . 'Errors: ' . implode(', ', $exception->getErrorResponse()->errors));
        }
    } catch (ClientExceptionInterface $exception) {
        $logger->error(
            sprintf('Create customer error from RetailCRM API: %s',
                $exception->getMessage())
        );
    }

    if (isset($response) && $response->success) {
        $logger->debug('Customer created');

        return $response->id;
    }

    return null;
}

function createNewOrder(int $customerId, array $data, \DateTime $date): ?int
{
    global $baseClient, $logger;

    $request = new OrdersCreateRequest();
    $request->site = SITE;
    $request->order = new Order();

    $request->order->orderType = ORDER_TYPE;
    $request->order->orderMethod = ORDER_METHOD;
    $request->order->customer = SerializedRelationCustomer::withIdAndType($customerId, CustomerType::CUSTOMER);
    $request->order->email = $data['email'];
    $request->order->phone = $data['phone'];
    $request->order->firstName = $data['name'];
    $request->order->customerComment = $data['customerComment'];

    $request->order->customFields = [
        DATE_CUSTOM_FIELD => $date->format('Y-m-d'),
        TIME_CUSTOM_FIELD => $date->format('H:i'),
        FLAG_CUSTOM_FIELD => true,
        SCORING_CUSTOM_FIELD => SCORING_CUSTOM_FIELD_DICTIONARY[$data[SCORING_CUSTOM_FIELD]],
    ];

    if ($managerId = getManagerIdByEmail($data['meetingHost'])) {
        $request->order->managerId = $managerId;
    }

    try {
        $response = $baseClient->orders->create($request);
    } catch (ApiExceptionInterface $exception) {
        $logger->error(
            sprintf(
                'Create order error from RetailCRM API (status code: %d): %s',
                $exception->getStatusCode(),
                $exception->getMessage()
            )
        );

        if (count($exception->getErrorResponse()->errors) > 0) {
            $logger->error(PHP_EOL . 'Errors: ' . implode(', ', $exception->getErrorResponse()->errors));
        }
    } catch (ClientExceptionInterface $exception) {
        $logger->error(
            sprintf('Create order error from RetailCRM API: %s',
                $exception->getMessage())
        );
    }

    if (isset($response) && $response->success) {
        $logger->debug('Order created');

        return $response->id;
    }

    return null;
}

function getManagerIdByEmail($email): ?int
{
    global $baseClient, $logger;
    if ($managersFile = file_get_contents(__DIR__ . '/data/managers.json')) {
        $managers = json_decode($managersFile, true);
    }

    if (isset($managers[$email])) {
        return $managers[$email];
    }

    $request = new UsersRequest();
    $request->limit = 100;

    try {
        $response = $baseClient->users->list($request);
    } catch (ApiExceptionInterface|ClientExceptionInterface $exception) {
        $logger->error(
            sprintf('Get manager error from RetailCRM API: %s',
                $exception->getMessage())
        );
    }

    $data = [];
    if (isset($response)) {
        foreach ($response->users as $user) {
            $data[$user->email] = $user->id;
        }

        file_put_contents(__DIR__ . '/data/managers.json', json_encode($data));
    }

    return $data[$email] ?? null;
}

function logEvent($eventType, $userId)
{
    global $logger;

    $client = new GuzzleHttp\Client();

    try {
        return json_decode(
            $client->request('POST', AMPLITUDE_URL, [
                'json' => [
                    'api_key' => AMPLITUDE_KEY,
                    'events'  => [
                        [
                            'user_id'    => $userId,
                            'event_type' => $eventType,
                        ],
                    ],
                ],
            ])->getBody()->__toString(), true
        );
    } catch (GuzzleException $e) {
        $logger->error('Error in Amplitude API: ' . $e);

        return null;
    }
}
