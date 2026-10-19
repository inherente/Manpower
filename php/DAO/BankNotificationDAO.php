<?php
// DAO/BankNotificationDAO.php

require_once __DIR__ . '/HookEventDAO.php';

class BankNotificationDAO extends HookEventDAO {

    public function insertNotification(array $data): bool {
        $this->ensureConnection();

        $rawBody = isset($data['raw_body']) ? substr($data['raw_body'], 0, 65535) : '';

        $sql = "INSERT INTO bank_notification (
                    gmail_message_id, 
                    authorization_code,
                    bank_name, 
                    transaction_type, 
                    amount, 
                    currency, 
                    merchant_or_payee, 
                    operation_desc,
                    channel,
                    account_last_four, 
                    transaction_timestamp, 
                    raw_body
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    amount = VALUES(amount),
                    updated_at = CURRENT_TIMESTAMP";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            error_log("Prepare failed in insertNotification: " . $this->conn->error);
            return false;
        }

        $stmt->bind_param(
            "ssssdsssssss",
            $data['gmail_message_id'],
            $data['authorization_code'],
            $data['bank_name'],
            $data['transaction_type'],
            $data['amount'],
            $data['currency'],
            $data['merchant_or_payee'],
            $data['operation_desc'],
            $data['channel'],
            $data['account_last_four'],
            $data['transaction_timestamp'],
            $rawBody
        );

        $success = $stmt->execute();
        $stmt->close();

        return $success;
    }
}
?>