<?php
// TOP OF FILE - ABSOLUTELY NO OUTPUT BEFORE THIS
$custom_path = $_SERVER['DOCUMENT_ROOT'] . '/../php-session';
if (!file_exists($custom_path)) mkdir($custom_path, 0700);
ini_set('session.save_path', $custom_path);
ini_set('session.cookie_secure', 1);
session_start();

require $_SERVER['DOCUMENT_ROOT'] . '/vendor/autoload.php';
error_log("callbacked");

// Triple verification
if (empty($_GET['code'])) {
    die("Missing authorization code");
}

$client = new Google\Client();
$client->setAuthConfig($_SERVER['DOCUMENT_ROOT'] . '/credentials.json');
$client->setRedirectUri('https://' . $_SERVER['HTTP_HOST'] . '/auth/oauth2callback.php');

try {
    $token = $client->fetchAccessTokenWithAuthCode($_GET['code']);
    
    // Store in three ways for redundancy
    $_SESSION['google_token'] = $token;
    file_put_contents('google_token.json', json_encode($token));
    file_put_contents($custom_path . '/google_token_' . session_id(), serialize($token));
    
    session_write_close();
    header('Location: /Sandbox/script.php');
    exit;
} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>