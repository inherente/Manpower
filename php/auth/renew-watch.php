<?php
// /auth/renew-watch.php
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/renew-watch.log');
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';

$tokenPath = __DIR__ . '/token.json';
$credentialsPath = __DIR__ . '/credentials.json';

if (!file_exists($tokenPath) || !file_exists($credentialsPath)) {
    error_log("Watch Renewal Failed: token.json or credentials.json missing.");
    exit;
}

$client = new Google\Client();
$client->setAuthConfig($credentialsPath);

$accessToken = json_decode(file_get_contents($tokenPath), true);
$client->setAccessToken($accessToken);

// Refresh access token if expired

if ($client->isAccessTokenExpired()) {
    $refreshToken = $client->getRefreshToken();
    
    if (!$refreshToken) {
        error_log("OAuth Error: No refresh token present in token.json. Manual re-authorization required.");
        exit("Error: Re-authorization required.");
    }

    $newToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);

    if (isset($newToken['error'])) {
        error_log("OAuth Refresh Failed: " . ($newToken['error_description'] ?? $newToken['error']));
        // Delete invalid token.json so the system knows re-auth is needed
        unlink($tokenPath);
        exit("Error: Refresh token revoked or expired. Please run auth/authorize.php again.");
    }

    $updatedToken = array_merge($accessToken, $newToken);
    file_put_contents($tokenPath, json_encode($updatedToken));
}

// Call watch() again to extend expiration by another 7 days
$service = new Google\Service\Gmail($client);

$watchRequest = new Google\Service\Gmail\WatchRequest();
$watchRequest->setTopicName('projects/project-48f64fdf-9ec0-4d41-bcb/topics/gmail-bank-alarm');
$watchRequest->setLabelIds(['INBOX']);
$watchRequest->setLabelFilterBehavior('INCLUDE');

try {
    $response = $service->users->watch('me', $watchRequest);
    $newExpiration = date('Y-m-d H:i:s UTC', $response->getExpiration() / 1000);
    error_log("!Watch successfully renewed! New expiration: " . $newExpiration);
} catch (Exception $e) {
    error_log("Watch Renewal API Exception: " . $e->getMessage());
}