<?php
require_once 'vendor/autoload.php';

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Rede\Environment;
use Rede\Store;

$filiation = getenv('REDE_PV') ?: 'test_pv';
$token = getenv('REDE_TOKEN') ?: 'test_token';

echo "Testing OAuth2 with:\n";
echo "  PV: $filiation\n";
echo "  Token: " . substr($token, 0, 5) . "...\n\n";

$logger = new Logger('OAuth Test');
$logger->pushHandler(new StreamHandler('php://stdout', Level::Debug));

$store = new Store($filiation, $token, Environment::sandbox());
$logger->info('Store created for sandbox environment');
$logger->info('OAuth Token URL: ' . $store->getEnvironment()->getOAuthTokenUrl());

// Try to manually request a bearer token by creating a minimal service
$curl = curl_init($store->getEnvironment()->getOAuthTokenUrl());

if (!$curl) {
    echo "Failed to initialize curl\n";
    exit(1);
}

$basic = base64_encode(sprintf('%s:%s', $store->getFiliation(), $store->getToken()));
$logger->info("Basic Auth: " . substr($basic, 0, 20) . "...");

$headers = [
    'Accept: application/json',
    'Content-Type: application/x-www-form-urlencoded',
    'Authorization: Basic ' . $basic,
];

curl_setopt($curl, CURLOPT_POST, true);
curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query(['grant_type' => 'client_credentials']));
curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
curl_setopt($curl, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_2);
curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);

$logger->info("Sending OAuth2 token request...\n");
$response = curl_exec($curl);
$httpInfo = curl_getinfo($curl);

$logger->info("OAuth Response Status: " . ($httpInfo['http_code'] ?? 'n/a'));
$logger->info("OAuth Response Body:\n" . ($response ?: 'empty'));

if (curl_errno($curl)) {
    $logger->error("Curl error: " . curl_error($curl));
}

curl_close($curl);
