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
        // 1. Small throttle to protect per-second rate limits
        usleep(250000); // 250ms pause

        try {
            $historyResponse = $service->users_history->listUsersHistory('me', ['startHistoryId' => $historyId]);
            $histories = $historyResponse->getHistory();
        } catch (Google\Service\Exception $e) {
            if ($e->getCode() === 404) {
                $hookDao->updateQueueStatus($queueId, 'failed', 'History ID expired or not found');
                continue;
            }
            if ($e->getCode() === 403 && strpos($e->getMessage(), 'quota') !== false) {
                // Rate limit hit: Revert back to 'pending' so next cron run can retry
                $hookDao->updateQueueStatus($queueId, 'pending', 'Rate limit hit, deferred to next run');
                break; // Stop loop for this run to let quota bucket reset
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

                // 2. Efficient Metadata Fetch for Filtering
                $metadata = $service->users_messages->get('me', $msgId, [
                    'format' => 'metadata',
                    'metadataHeaders' => ['From', 'Subject']
                ]);

                $headers = $metadata->getPayload()->getHeaders();
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

                // 3. Filter non-bank messages BEFORE fetching heavy full message body
                $isBankEmail = (
                    preg_match('/(bbva|banorte|scotiabank|citibanamex|hsbc|nu\.com\.mx)/i', $from) ||
                    preg_match('/(notificacion|transferencia|retiro|compra|cargo)/i', $subject)
                );

                if (!$isBankEmail) {
                    continue; // Skip without fetching full body
                }

                // 4. Fetch Full Body ONLY for confirmed bank emails
                $fullMessage = $service->users_messages->get('me', $msgId, ['format' => 'full']);
                $body = getGmailMessageBody($fullMessage->getPayload());

                $parsedData = BankNotificationParser::parse($from, $subject, $body);

                if ($parsedData) {
                    $parsedData['gmail_message_id'] = $msgId;
                    $parsedData['raw_body'] = $body;

                    $bankDao->insertNotification($parsedData);
                }
            }
        }

        $hookDao->updateQueueStatus($queueId, 'completed');

    } catch (Google\Service\Exception $e) {
        if ($e->getCode() === 403 && strpos($e->getMessage(), 'quota') !== false) {
            // Revert status to 'pending' to retry on next cron cycle
            $hookDao->updateQueueStatus($queueId, 'pending', 'Rate limit hit during iteration');
            break;
        }
        error_log("Worker API Error [Queue ID $queueId]: " . $e->getMessage());
        $hookDao->updateQueueStatus($queueId, 'failed', $e->getMessage());
    } catch (Exception $e) {
        error_log("Worker Error [Queue ID $queueId]: " . $e->getMessage());
        $hookDao->updateQueueStatus($queueId, 'failed', $e->getMessage());
    }
}
?>