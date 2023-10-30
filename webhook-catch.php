<?php

require __DIR__ . '/functions.php';
global $logger, $crmClient;

try {
    $request = json_decode(file_get_contents('php://input'), true);
} catch (\Exception $e) {
    $logger->error($e->getMessage());

    exit;
}

$url = $request['payload']['tracking']['utm_source'] ?? null;
$date = $request['payload']['scheduled_event']['start_time'] ?? null;
$timezone = $request['payload']['timezone'] ?? null;

$logger->debug('Webhook data: ' . json_encode($request));

if (!$url) {
    $logger->error('Url is empty');

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

$logger->debug('CRM: ' . $url);
$order = findOrderByCrmUrl('https://' . $url);

if (!$order) {
    $logger->error('Order not found');

    exit;
}

$logger->debug('Order found: ' . $order->id);


$dateTime = new \DateTime($date);
$logger->debug('UTC tmzone date: ' . $dateTime->format('Y-m-d H:i:s P'));
$dateTime = $dateTime->setTimezone(new \DateTimeZone($timezone));
$logger->debug('Customer`s date: ' . $dateTime->format('Y-m-d H:i:s P'));

$result = updateDateAndTime($order->id, $dateTime);

if (!$result) {
    $logger->error('Error when updating order: ' . $order->id);

    exit;
}

$logger->info('Successfully updated order: ' . $result->id);
