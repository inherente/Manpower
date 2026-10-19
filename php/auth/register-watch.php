<?php
// 1. Configure error settings immediately at the top
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/watch.log'); // Writes errors to local log
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';

$client = new Google\Client();
$client->setAuthConfig('../credentials.json');
$client->addScope(Google\Service\Gmail::GMAIL_READONLY);
$client->setAccessType('offline');
$client->setPrompt('select_account consent');

$tokenPath = __DIR__ . '/token.json';

if (file_exists($tokenPath)) {
    $accessToken = json_decode(file_get_contents($tokenPath), true);
    $client->setAccessToken($accessToken);
}

if ($client->isAccessTokenExpired()) {
    if ($client->getRefreshToken()) {
        $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
    } else {
        // Run once from CLI to authenticate interactively
        if (php_sapi_name() === 'cli') {
            $authUrl = $client->createAuthUrl();
            printf("Open this link in your browser:\n%s\n", $authUrl);
            print "Enter verification code: ";
            $authCode = trim(fgets(STDIN));

            $accessToken = $client->fetchAccessTokenWithAuthCode($authCode);
            $client->setAccessToken($accessToken);
            file_put_contents($tokenPath, json_encode($client->getAccessToken()));
        } else {
            die("Token expired. Run from CLI to re-authenticate.");
        }
    }
}

$service = new Google\Service\Gmail($client);
$watchRequest = new Google\Service\Gmail\WatchRequest();

// Your GCP Pub/Sub Topic ID
$watchRequest->setTopicName('projects/YOUR_GCP_PROJECT_ID/topics/YOUR_PUBSUB_TOPIC');
$watchRequest->setLabelIds(['INBOX']);
$watchRequest->setLabelFilterBehavior('INCLUDE');

try {
    $response = $service->users->watch('me', $watchRequest);
    echo json_encode([
        'status'     => 'success',
        'historyId'  => $response->getHistoryId(),
        'expiration' => date('Y-m-d H:i:s UTC', $response->getExpiration() / 1000)
    ], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo "Watch registration failed: " . $e->getMessage();
}
?>