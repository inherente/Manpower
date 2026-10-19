<?php
error_log("OAuth Callback Init. ");

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/oauth2callback.log');
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';

if (empty($_GET['code'])) {
    http_response_code(400);
    die("Missing authorization code from Google redirect.");
}

$client = new Google\Client();
$client->setAuthConfig(__DIR__ . '/credentials.json');
$client->setRedirectUri('https://api.cerezasanta.com/auth/oauth2callback.php');
$client->addScope(Google\Service\Gmail::GMAIL_READONLY);
$client->setAccessType('offline');
$client->setPrompt('consent');

try {
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);

    if (isset($token['error'])) {
        throw new Exception("Google Token Error: " . ($token['error_description'] ?? $token['error']));
    }

    // Save token.json directly inside /auth/
    $tokenPath = __DIR__ . '/token.json';
    file_put_contents($tokenPath, json_encode($token));

    // Register Gmail Watch immediately
    $client->setAccessToken($token);
    $service = new Google\Service\Gmail($client);

    $watchRequest = new Google\Service\Gmail\WatchRequest();
    $watchRequest->setTopicName('projects/project-48f64fdf-9ec0-4d41-bcb/topics/gmail-bank-alarm');
    $watchRequest->setLabelIds(['INBOX']);
    $watchRequest->setLabelFilterBehavior('INCLUDE');

    $watchResponse = $service->users->watch('me', $watchRequest);

    header('Content-Type: application/json');
    echo json_encode([
        'status'     => 'success',
        'message'    => 'token.json saved and watch registered!',
        'historyId'  => $watchResponse->getHistoryId(),
        'expiration' => date('Y-m-d H:i:s UTC', $watchResponse->getExpiration() / 1000)
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    error_log("OAuth Callback Exception: " . $e->getMessage());
    http_response_code(500);
    die("Authentication Error: " . htmlspecialchars($e->getMessage()));
}

?>
