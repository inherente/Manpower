<?php
// 1. Configure error settings FIRST
ini_set('display_errors', 1); // Set to 1 temporarily to see errors directly in browser
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/authorize.log');
error_reporting(E_ALL);

// 2. Now log the entry point
error_log("OAuth Authorize triggered at " . date('Y-m-d H:i:s'));

// 3. Autoload check
$autoloader = __DIR__ . '/../vendor/autoload.php';
if (!file_exists($autoloader)) {
    die("Autoloader not found at: " . realpath(__DIR__ . '/..') . '/vendor/autoload.php');
}
require_once $autoloader;

$credentialsPath = __DIR__ . '/credentials.json';
if (!file_exists($credentialsPath)) {
    die("credentials.json not found inside /auth/ folder!");
}

$client = new Google\Client();
$client->setAuthConfig($credentialsPath);
$client->setRedirectUri('https://api.cerezasanta.com/auth/oauth2callback.php');
$client->addScope(Google\Service\Gmail::GMAIL_READONLY);
$client->setAccessType('offline');
$client->setPrompt('consent');

$authUrl = $client->createAuthUrl();

// Log generated URL for debugging
error_log("Generated Auth URL: " . $authUrl);

header('Location: ' . filter_var($authUrl, FILTER_SANITIZE_URL));
exit;
?>