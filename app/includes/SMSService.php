<?php
/**
 * SMS Service - Wrapper for AndroidSmsGateway Client
 */

declare(strict_types=1);

use AndroidSmsGateway\Client;
use AndroidSmsGateway\Domain\MessageBuilder;
use AndroidSmsGateway\Domain\MessageState;

class SMSService
{
    private ?Client $client = null;
    private Database $db;
    private array $settings;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->loadSettings();
    }

    private function loadSettings(): void
    {
        $stmt = $this->db->prepare("SELECT setting_key, setting_value FROM settings");
        $stmt->execute();
        $rows = $stmt->fetchAll();
        
        $this->settings = [];
        foreach ($rows as $row) {
            $this->settings[$row['setting_key']] = $row['setting_value'];
        }
    }

    public function getSetting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }

    public function updateSetting(string $key, mixed $value): bool
    {
        $stmt = $this->db->prepare("INSERT OR REPLACE INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)");
        $result = $stmt->execute([$key, (string)$value]);
        
        if ($result) {
            $this->settings[$key] = $value;
        }
        
        return $result;
    }

    public function getClient(): ?Client
    {
        $apiLogin = $this->getSetting('api_login');
        $apiPassword = $this->getSetting('api_password');
        $apiUrl = $this->getSetting('api_url', Client::DEFAULT_URL);

        if (empty($apiLogin) || empty($apiPassword)) {
            return null;
        }

        if ($this->client === null) {
            try {
                $finalUrl = $this->resolveApiUrl($apiUrl);
                $this->client = new Client($apiLogin, $apiPassword, $finalUrl);
            } catch (Exception $e) {
                error_log('SMS Service Client Error: ' . $e->getMessage());
                return null;
            }
        }

        return $this->client;
    }

    private function getActiveClient(): ?Client
    {
        return $this->getClient();
    }

    private function resolveApiUrl(string $url): string
    {
        $url = trim($url);
        
        if (str_starts_with($url, 'http://')) {
            return $url;
        }
        
        if (str_starts_with($url, 'https://')) {
            if (!str_contains($url, '/3rdparty/v1')) {
                $url = rtrim($url, '/') . '/3rdparty/v1';
            }
            return $url;
        }
        
        return 'https://' . ltrim($url, '/');
    }

    public function isConfigured(): bool
    {
        $apiLogin = $this->getSetting('api_login');
        $apiPassword = $this->getSetting('api_password');
        
        return !empty($apiLogin) && !empty($apiPassword);
    }

    public function healthCheck(): array
    {
        $client = $this->getClient();
        
        if ($client === null) {
            return ['status' => 'error', 'message' => 'API not configured'];
        }

        try {
            $health = $client->HealthCheck();
            return ['status' => 'ok', 'data' => $health];
        } catch (Exception $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    private function makeApiRequest(string $method, string $path, ?array $data = null): mixed
    {
        $apiLogin = $this->getSetting('api_login');
        $apiPassword = $this->getSetting('api_password');
        $apiUrl = $this->getSetting('api_url', Client::DEFAULT_URL);
        
        $finalUrl = $this->resolveApiUrl($apiUrl) . $path;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $finalUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Basic ' . base64_encode($apiLogin . ':' . $apiPassword),
            'Content-Type: application/json',
        ]);
        
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 400) {
            throw new Exception('API request failed with code: ' . $httpCode);
        }
        
        return json_decode($response);
    }

    public function sendSingleSMS(
        string $phoneNumber,
        string $message,
        string $simSelection = 'auto',
        int $priority = 5,
        ?string $deviceId = null
    ): array {
        $phoneNumber = $this->normalizePhoneNumber($phoneNumber);
        
        if (!$this->validatePhoneNumber($phoneNumber)) {
            return [
                'success' => false,
                'error' => 'Invalid phone number format',
            ];
        }

        $client = $this->getClientForDevice($deviceId);
        
        if ($client === null) {
            return [
                'success' => false,
                'error' => 'SMS Gateway not configured. Add a device in Devices page.',
            ];
        }

        try {
            $simNumber = $this->parseSimSelection($simSelection);
            
            $messageBuilder = new MessageBuilder($message, [$phoneNumber]);
            $messageBuilder->setPriority($priority);
            
            if ($simNumber !== null) {
                $messageBuilder->setSimNumber($simNumber);
            }

            $messageObj = $messageBuilder->build();
            $state = $client->SendMessage($messageObj);

            $this->recordSentMessage(null, $phoneNumber, $message, $simSelection, $priority, $state->ID(), 'sent', null, $deviceId);

            return [
                'success' => true,
                'external_id' => $state->ID(),
                'status' => $state->State()->Value(),
            ];
        } catch (Exception $e) {
            error_log('Send SMS Error: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    public function getMessageState(string $externalId): ?MessageState
    {
        $client = $this->getClient();
        
        if ($client === null) {
            return null;
        }

        try {
            return $client->GetMessageState($externalId);
        } catch (Exception $e) {
            error_log('Get Message State Error: ' . $e->getMessage());
            return null;
        }
    }

    public function queueMessage(
        string $phoneNumber,
        string $message,
        string $simSelection = 'auto',
        int $priority = 5,
        ?string $scheduledAt = null,
        ?string $deviceId = null
    ): array {
        $phoneNumber = $this->normalizePhoneNumber($phoneNumber);
        
        if (!$this->validatePhoneNumber($phoneNumber)) {
            return [
                'success' => false,
                'error' => 'Invalid phone number format',
            ];
        }

        $stmt = $this->db->prepare("
            INSERT INTO sms_queue (phone_number, message, sim_selection, priority, status, scheduled_at, device_id)
            VALUES (?, ?, ?, ?, 'pending', ?, ?)
        ");
        
        $result = $stmt->execute([
            $phoneNumber,
            $message,
            $simSelection,
            $priority,
            $scheduledAt,
            $deviceId,
        ]);

        if ($result) {
            return [
                'success' => true,
                'queue_id' => $this->db->lastInsertId(),
            ];
        }

        return [
            'success' => false,
            'error' => 'Failed to queue message',
        ];
    }

    public function getQueueMessages(int $limit = 100, string $status = null): array
    {
        if ($status === 'scheduled') {
            $stmt = $this->db->prepare("
                SELECT * FROM sms_queue 
                WHERE scheduled_at IS NOT NULL AND scheduled_at > datetime('now') AND status = 'pending'
                ORDER BY scheduled_at ASC, priority DESC, created_at ASC 
                LIMIT ?
            ");
            $stmt->execute([$limit]);
        } elseif ($status !== null) {
            $stmt = $this->db->prepare("
                SELECT * FROM sms_queue 
                WHERE status = ? 
                ORDER BY priority DESC, created_at ASC 
                LIMIT ?
            ");
            $stmt->execute([$status, $limit]);
        } else {
            $stmt = $this->db->prepare("
                SELECT * FROM sms_queue 
                ORDER BY priority DESC, created_at ASC 
                LIMIT ?
            ");
            $stmt->execute([$limit]);
        }
        
        return $stmt->fetchAll();
    }

    public function getPendingQueueCount(): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM sms_queue WHERE status = 'pending'");
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    public function getTotalSentToday(): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM sent_messages 
            WHERE date(sent_at) = date('now')
        ");
        $stmt->execute();
        return (int)$stmt->fetchColumn();
    }

    public function updateQueueMessageStatus(int $queueId, string $status, ?string $externalId = null, ?string $error = null): bool
    {
        $sql = "UPDATE sms_queue SET status = ?, updated_at = CURRENT_TIMESTAMP";
        $params = [$status];

        if ($externalId !== null) {
            $sql .= ", external_id = ?";
            $params[] = $externalId;
        }

        if ($error !== null) {
            $sql .= ", error_message = ?";
            $params[] = $error;
        }

        if ($status === 'sent') {
            $sql .= ", sent_at = CURRENT_TIMESTAMP";
        }

        $sql .= " WHERE id = ?";
        $params[] = $queueId;

        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function deleteQueueMessage(int $queueId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM sms_queue WHERE id = ?");
        return $stmt->execute([$queueId]);
    }

    public function retryQueueMessage(int $queueId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE sms_queue 
            SET status = 'pending', attempts = 0, error_message = NULL, updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        return $stmt->execute([$queueId]);
    }

    private function recordSentMessage(
        ?int $queueId,
        string $phoneNumber,
        string $message,
        string $simSelection,
        int $priority,
        string $externalId,
        string $status,
        ?string $error = null,
        ?string $deviceId = null
    ): void {
        $stmt = $this->db->prepare("
            INSERT INTO sent_messages (queue_id, phone_number, message, sim_selection, priority, external_id, status, error_message, device_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$queueId, $phoneNumber, $message, $simSelection, $priority, $externalId, $status, $error, $deviceId]);
    }

    private function normalizePhoneNumber(string $phone): string
    {
        return preg_replace('/[^0-9+]/', '', $phone);
    }

    private function validatePhoneNumber(string $phone): bool
    {
        $cleaned = $this->normalizePhoneNumber($phone);
        return preg_match('/^\+?[1-9]\d{7,14}$/', $cleaned) === 1;
    }

    private function parseSimSelection(string $simSelection): ?int
    {
        return match ($simSelection) {
            '1' => 1,
            '2' => 2,
            'auto', '' => null,
            default => null,
        };
    }

    public function getStats(): array
    {
        return [
            'pending_queue' => $this->getPendingQueueCount(),
            'sent_today' => $this->getTotalSentToday(),
            'configured' => $this->isConfigured(),
        ];
    }

    public function getDevices(): array
    {
        $stmt = $this->db->prepare("SELECT * FROM devices ORDER BY friendly_name ASC, name ASC");
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getClientForDevice(?string $deviceId): ?Client
    {
        if (empty($deviceId)) {
            return $this->getClient();
        }

        $deviceService = new DeviceService($this->db);
        return $deviceService->getDeviceClient($deviceId);
    }
}
