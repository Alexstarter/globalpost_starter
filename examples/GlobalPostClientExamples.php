<?php

require_once __DIR__ . '/../modules/globalpostshipping/src/SDK/GlobalPostClient.php';
require_once __DIR__ . '/../modules/globalpostshipping/src/SDK/CurlHttpTransport.php';
require_once __DIR__ . '/../modules/globalpostshipping/src/SDK/NullLogger.php';

use GlobalPostShipping\SDK\GlobalPostClient;
use GlobalPostShipping\SDK\NullLogger;

$token = 'your-test-token';
$mode = GlobalPostClient::MODE_TEST;

$client = new GlobalPostClient($token, $mode, [
    'timeout' => 10,
    'connect_timeout' => 5,
    'max_retries' => 1,
    'retry_delay' => 0.2,
    'debug' => true,
]);

try {
    $countries = $client->getCountries();
    echo 'Supported countries: ' . implode(', ', $countries) . PHP_EOL;

    $options = $client->getOptions([
        'from_country' => 'UA',
        'to_country' => 'US',
        'weight' => 500,
        'length' => 20,
        'width' => 15,
        'height' => 10,
        'package_type' => 'parcel',
    ]);

    print_r($options);

    $order = $client->createShortOrder([
        'contragent_key' => 'your-contragent-key',
        'international_tariff_id' => 123,
        'order_id' => 'ORDER-42',
        'recipient_country' => 'US',
        'recipient_city' => 'New York',
        'recipient_name' => 'John Doe',
        'recipient_phone' => '+1000111222333',
        'recipient_email' => 'customer@example.com',
    ]);

    print_r($order);

    $label = $client->printLabel('en', 'ORDER-42');
    file_put_contents(__DIR__ . '/label.pdf', $label);

    $invoice = $client->printInvoice('ORDER-42');
    file_put_contents(__DIR__ . '/invoice.pdf', $invoice);
} catch (Exception $exception) {
    echo 'GlobalPost error: ' . $exception->getMessage() . PHP_EOL;
}
