<?php

use GuzzleHttp\Exception\GuzzleException;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use RetailCrm\Api\Enum\ByIdentifier;
use RetailCrm\Api\Factory\SimpleClientFactory;
use RetailCrm\Api\Interfaces\ApiExceptionInterface;
use RetailCrm\Api\Model\Entity\Orders\Order;
use RetailCrm\Api\Model\Filter\Orders\OrderFilter;
use RetailCrm\Api\Model\Request\Orders\OrdersEditRequest;
use RetailCrm\Api\Model\Request\Orders\OrdersRequest;

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config.php';

const CALENDLY_URL = 'https://api.calendly.com/';
const WEBHOOK_LOCK = '_webhook';

global $logger;
$logger = new Logger('Log');
$handler = new RotatingFileHandler(__DIR__ . '/log/log.log', 30,  Logger::DEBUG);

$id = uniqid();
$output = "%datetime% > $id > %level_name% > %message% %context% %extra%\n";
$formatter = new LineFormatter($output, 'd-M-Y H:i:s', false, true);

$handler->setFormatter($formatter);
$logger->pushHandler($handler);

function getCrmClient() {
    return SimpleClientFactory::createClient(CRM_URL, CRM_KEY);
}

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

function findOrderByCrmUrl(string $url) {
    global $logger;

    $request = new OrdersRequest();
    $request->filter = new OrderFilter();
    $request->filter->customFields = [
        'crm' => $url,
    ];

    try {
        $response = getCrmClient()->orders->list($request);
    } catch (ApiExceptionInterface $exception) {
        $logger->error(sprintf(
            'Error from RetailCRM API (status code: %d): %s',
            $exception->getStatusCode(),
            $exception->getMessage()
        ));

        if (count($exception->getErrorResponse()->errors) > 0) {
            $logger->error(PHP_EOL . 'Errors: ' . implode(', ', $exception->getErrorResponse()->errors));
        }

        exit;
    }

    return current($response->orders);
}

function updateDateAndTime(int $orderId, \DateTime $date) {
    global $logger;

    $order = new Order();
    $order->customFields = [
        DATE_CUSTOM_FIELD => $date->format('Y-m-d'),
        TIME_CUSTOM_FIELD => $date->format('H:i'),
    ];

    $request        = new OrdersEditRequest();
    $request->by    = ByIdentifier::ID;
    $request->site  = SITE;
    $request->order = $order;

    try {
        $response = getCrmClient()->orders->edit($orderId, $request);
    } catch (ApiExceptionInterface $exception) {
        $logger->error(sprintf(
            'Error from RetailCRM API (status code: %d): %s',
            $exception->getStatusCode(),
            $exception->getMessage()
        ));

        if (count($exception->getErrorResponse()->errors) > 0) {
            $logger->error(PHP_EOL . 'Errors: ' . implode(', ', $exception->getErrorResponse()->errors));
        }

        exit;
    }

    return $response->order;
}
