<?php

require __DIR__ . '/functions.php';
global $logger, $crmClient;

try {
    $request = json_decode(file_get_contents('php://input'), true);
} catch (\Exception $e) {
    $logger->error($e->getMessage());

    exit;
}

$data = getPayloadData($request['payload']);
$date = $request['payload']['scheduled_event']['start_time'] ?? null;
$timezone = $request['payload']['timezone'] ?? null;

$logger->debug('Webhook data: ' . json_encode($request));

if (!$data['email']) {
    $logger->error('Email is empty');

    exit;
}

if (!$date) {
    $logger->error('Date is empty');

    exit;
}

if (!$timezone) {
    $logger->error('Timezone is empty');

    exit;
}

$customer = getCustomerByEmail($data['email']);

if (!$customer) {
    $customer = createNewCustomer($data);
}

if (!$customer) {
    $logger->error('Customer is empty');

    exit;
}

$dateTime = new \DateTime($date);
$logger->debug('UTC timezone date: ' . $dateTime->format('Y-m-d H:i:s P'));
$dateTime = $dateTime->setTimezone(new \DateTimeZone($timezone));
$logger->debug('Customer`s date: ' . $dateTime->format('Y-m-d H:i:s P'));

$order = createNewOrder($customer, $data, $dateTime);

if (!$order) {
    $logger->error('Can not create order');

    exit;
}

$logger->info('Successfully created order: ' . $order);

if ($customer) {
    $logger->debug('UserId: ' . $customer);

    $result = logEvent('Successful demo', $customer);

    if (!$result || !isset($result['code']) || $result['code'] !== 200) {
        $logger->error('Amplitude API error ', ['result' => $result]);

        exit;
    }

    $logger->info('Successfully sent event to amplitude: ' . $customer);
}
