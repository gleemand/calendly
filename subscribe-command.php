<?php

require __DIR__ . '/functions.php';
global $logger;

if (file_exists(WEBHOOK_LOCK)) {
    $logger->warning('Already subscribed');

    exit;
}

try {
    $subscription = webhookSubscribe();
} catch (\Exception $e) {
    $logger->error($e->getMessage());

    exit;
}

file_put_contents(WEBHOOK_LOCK, $subscription['uri']);
$logger->info('Successfully subscribed! Webhook: ' . $subscription['uri']);
