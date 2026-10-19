<?php
 // finance/process-email-queue.php

ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/worker-error.log');
error_reporting(E_ALL);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../DAO/HookEventDAO.php';
require_once __DIR__ . '/../DAO/BankNotificationDAO.php';
require_once __DIR__ . '/BankNotificationParser.php';

/**
 * Recursively extracts message body text from Gmail payload parts.
 */
function getGmailMessageBody($payload) {
    if ($payload->getBody() && $payload->getBody()->getData()) {
        return base64_decode(strtr($payload->getBody()->getData(), '-_', '+/'));
    }

    $parts = $payload->getParts();
    if (!empty($parts)) {
        foreach ($parts as $part) {
            if (in_array($part->getMimeType(), ['text/plain', 'text/html'])) {
                $data = $part->getBody()->getData();
                if ($data) {
                    return base64_decode(strtr($data, '-_', '+/'));
                }
            }
            if ($part->getParts()) {
                $nestedBody = getGmailMessageBody($part);
                if (!empty($nestedBody)) {
                    return $nestedBody;
                }
            }
        }
    }

    return '';
}

// 1. Initialize Google API Client using stored token.json
$tokenPath = __DIR__ . '/../auth/token.json';
$credentialsPath = __DIR__ . '/../auth/credentials.json';

if (!file_exists($tokenPath) || !file_exists($credentialsPath)) {
    error_log("Worker Error: OAuth credentials or token.json missing.");
    die("Error: OAuth configuration or token missing.\n");
}

$client = new Google\Client();
$client->setAuthConfig($credentialsPath);
$accessToken = json_decode(file_get_contents($tokenPath), true);
$client->setAccessToken($accessToken);

// Handle token refresh automatically
if ($client->isAccessTokenExpired()) {
    if ($client->getRefreshToken()) {
        $newToken = $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());
        $updatedToken = array_merge($accessToken, $newToken);
        file_put_contents($tokenPath, json_encode($updatedToken));
    } else {
        error_log("Worker Error: Refresh token missing in token.json.");
        die("Error: Refresh token missing. Need manual re-authorization.\n");
    }
}

$service = new Google\Service\Gmail($client);

// 2. Instantiate DAO classes (Connection handled internally)
$hookDao = new HookEventDAO();
$bankDao = new BankNotificationDAO();

// 3. Retrieve pending queue items using DAO
$pendingItems = $hookDao->getPendingQueueItems(10);

foreach ($pendingItems as $row) {
    $queueId = (int)$row['id'];
    $historyId = $row['history_id'];

    // Mark status as processing via DAO
    $hookDao->updateQueueStatus($queueId, 'processing');

    try {
        // Fetch history changes since historyId
        try {
            $historyResponse = $service->users_history->listUsersHistory('me', ['startHistoryId' => $historyId]);
            $histories = $historyResponse->getHistory();
        } catch (Google\Service\Exception $e) {
            // Handle expired/invalid History IDs gracefully
            if ($e->getCode() === 404) {
                $hookDao->updateQueueStatus($queueId, 'failed', 'History ID expired or not found');
                continue;
            }
            throw $e;
        }

        if (empty($histories)) {
            $hookDao->updateQueueStatus($queueId, 'completed');
            continue;
        }

        foreach ($histories as $history) {
            $messagesAdded = $history->getMessagesAdded();
            if (empty($messagesAdded)) {
                continue;
            }

            foreach ($messagesAdded as $msgItem) {
                $msgId = $msgItem->getMessage()->getId();
                $message = $service->users_messages->get('me', $msgId, ['format' => 'full']);

                // Parse headers (From & Subject)
                $headers = $message->getPayload()->getHeaders();
                $from = '';
                $subject = '';

                foreach ($headers as $header) {
                    if (strtolower($header->getName()) === 'from') {
                        $from = $header->getValue();
                    }
                    if (strtolower($header->getName()) === 'subject') {
                        $subject = $header->getValue();
                    }
                }

                // Filter for financial notification senders or subjects
                $isBankEmail = (
                    preg_match('/(bbva|banorte|santander|citibanamex|hsbc|nu\.com\.mx)/i', $from) ||
                    preg_match('/(notificacion|transferencia|retiro|compra|cargo)/i', $subject)
                );

                if (!$isBankEmail) {
                    continue; // Skip non-bank messages
                }

                // Extract full message body (handles multipart and nested structures)
                $body = getGmailMessageBody($message->getPayload());

                // Parse details via BankNotificationParser
                $parsedData = BankNotificationParser::parse($from, $subject, $body);

                if ($parsedData) {
                    $parsedData['gmail_message_id'] = $msgId;
                    $parsedData['raw_body'] = $body;

                    // Insert or update transaction row in MySQL via DAO
                    $bankDao->insertNotification($parsedData);
                }
            }
        }

        // Mark queue item completed via DAO
        $hookDao->updateQueueStatus($queueId, 'completed');

    } catch (Exception $e) {
        error_log("Worker Error [Queue ID $queueId]: " . $e->getMessage());
        $hookDao->updateQueueStatus($queueId, 'failed', $e->getMessage());
    }
}
?>