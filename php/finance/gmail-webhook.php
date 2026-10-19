<?php
// 1. Configure error settings immediately at the top
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/webhook.log'); // Writes errors to local log
error_reporting(E_ALL);

// 2. Include DAO class with corrected path
require_once __DIR__ . '/../DAO/HookEventDAO.php';

// 3. Immediately send 200 OK
http_response_code(200);

$rawInput = file_get_contents('php://input');
$data = json_decode($rawInput, true);

if (isset($data['message']['data']) && isset($data['message']['messageId'])) {
    $messageId = $data['message']['messageId'];
    $publishTime = $data['message']['publishTime'] ?? null;
    
    // Decode inner base64 payload
    $decodedData = json_decode(base64_decode($data['message']['data']), true);
    
    $userEmail = $decodedData['emailAddress'] ?? null;
    $historyId = $decodedData['historyId'] ?? null;

    if ($userEmail && $historyId) {
        $dao = new HookEventDAO();
        $dao->insertWebhookQueue($messageId, $userEmail, $historyId, $publishTime, $rawInput);
    }
}

header('Content-Type: application/json');
echo json_encode(['status' => 'success']);