<?php
class HookEventDAO {
    protected $servername;
    protected $username;
    protected $password;
    protected $dbname;
    protected $conn = null;

    function constructBefore() {
        $config = parse_ini_file('to-do.ini');
        $this->servername = $config['servername'];
        $this->username   = $config['user'];
        $this->password   = $config['password'];
        $this->dbname     = $config['dbname'];

        // Initialize MySQLi connection
        $this->conn = new mysqli($this->servername, $this->username, $this->password, $this->dbname);

        if ($this->conn->connect_error) {
            error_log("Database connection failed: " . $this->conn->connect_error);
        }
    }

    /**
     * Insert raw Pub/Sub webhook payload into gmail_webhook_queue table.
     * Uses prepared statements to prevent SQL injection and ON DUPLICATE KEY UPDATE for idempotency.
     *
     * @param string $pubsubMessageId Unique Pub/Sub message ID
     * @param string $userEmail Email address extracted from base64 data
     * @param int|string $historyId Gmail history ID
     * @param string|null $publishTime ISO timestamp or formatted DATETIME
     * @param string $rawPayload Complete raw JSON input
     * @return bool
     */
    public function insertWebhookQueue(string $pubsubMessageId, string $userEmail, $historyId, ?string $publishTime, string $rawPayload): bool {
        if (!$this->conn || $this->conn->connect_error) {
            return false;
        }

        $sql = "INSERT INTO gmail_webhook_queue 
                    (pubsub_message_id, user_email, history_id, publish_time, raw_payload) 
                VALUES 
                    (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                    attempts = attempts + 1";

        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            error_log("Prepare failed: " . $this->conn->error);
            return false;
        }

        // Format publishTime to MySQL DATETIME format (YYYY-MM-DD HH:MM:SS) if provided
        $formattedPublishTime = null;
        if (!empty($publishTime)) {
            $formattedPublishTime = date('Y-m-d H:i:s', strtotime($publishTime));
        } else {
            $formattedPublishTime = date('Y-m-d H:i:s');
        }

        // Bind parameters: s = string, i = integer
        // 'ssiss' -> string, string, integer (history_id), string, string
        $stmt->bind_param("ssiss", $pubsubMessageId, $userEmail, $historyId, $formattedPublishTime, $rawPayload);

        $success = $stmt->execute();
        
        if (!$success) {
            error_log("Execute failed: " . $stmt->error);
        }

        $stmt->close();
        return $success;
    }

    function __destruct() {
        if ($this->conn && !$this->conn->connect_error) {
            $this->conn->close();
        }
    }


    public function __construct() {
        $this->connect();
    }

    /**
     * Centralized database connection logic reading from to-do.ini
     */
    protected function connect(): mysqli {
        if ($this->conn instanceof mysqli && $this->conn->ping()) {
            return $this->conn;
        }

        $config = parse_ini_file(__DIR__ . '/to-do.ini');
        $host = $config['dbhost'] ?? $config['servername'] ?? 'localhost';
        
        $this->conn = new mysqli($host, $config['user'], $config['password'], $config['dbname']);

        if ($this->conn->connect_error) {
            error_log("HookEventDAO Connection Error: " . $this->conn->connect_error);
            throw new Exception("Database connection failed: " . $this->conn->connect_error);
        }

        // Set character set to utf8mb4 for emoji / HTML bank email body support
        $this->conn->set_charset("utf8mb4");

        return $this->conn;
    }



    /**
     * Ensures the MySQL connection is alive before performing DB operations.
     */
    protected function ensureConnection(): void {
        if (!$this->conn || !$this->conn->ping()) {
            $this->connect();
        }
    }

    /**
     * Retrieves pending items from gmail_webhook_queue.
     */
    public function getPendingQueueItems(int $limit = 5): array {
        $this->ensureConnection();

        $sql = "SELECT id, history_id, status FROM gmail_webhook_queue WHERE status = 'pending' ORDER BY id ASC LIMIT ?";
        
        $stmt = $this->conn->prepare($sql);
        if (!$stmt) {
            error_log("Prepare failed in getPendingQueueItems: " . $this->conn->error);
            return [];
        }

        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }

        $stmt->close();
        return $items;
    }

    /**
     * Updates the processing status of a queue record.
     */
    public function updateQueueStatus(int $queueId, string $status, ?string $errorMessage = null): bool {
        $this->ensureConnection();

        if ($status === 'completed') {
            $sql = "UPDATE gmail_webhook_queue SET status = ?, processed_at = NOW(), error_message = NULL WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("si", $status, $queueId);
        } else {
            $sql = "UPDATE gmail_webhook_queue SET status = ?, error_message = ? WHERE id = ?";
            $stmt = $this->conn->prepare($sql);
            $stmt->bind_param("ssi", $status, $errorMessage, $queueId);
        }

        $success = $stmt->execute();
        $stmt->close();
        return $success;
    }


    /**
     * Getter for active connection if needed elsewhere.
     */
    public function getConnection(): mysqli {
        $this->ensureConnection();
        return $this->conn;
    }

}

?>