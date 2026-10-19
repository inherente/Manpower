<?php
// MUST BE FIRST - No whitespace/output before this
ini_set('session.save_path', '/tmp');
session_start();

require $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';

$client = new Google\Client();
$client->setAuthConfig($_SERVER['DOCUMENT_ROOT'] . '/credentials.json');
$client->setAccessType('offline');
$client->setPrompt('consent');
$client->addScope(Google\Service\Calendar::CALENDAR);
$client->setRedirectUri('https://' . $_SERVER['HTTP_HOST'] . '/auth/oauth2callback.php');

// Generate and store state token for CSRF protection
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;
$client->setState($state);

// Store FRESH client instance
$_SESSION['google_client'] = serialize($client);

// Force session write
session_write_close();

header('Location: ' . $client->createAuthUrl());
exit;
?>