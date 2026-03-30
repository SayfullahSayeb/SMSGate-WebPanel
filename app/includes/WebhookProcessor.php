<?php
/**
 * Webhook Processor - Handle incoming webhooks and SMS callbacks
 */

declare(strict_types=1);

class WebhookProcessor
{
    private Database $db;
    private SMSService $smsService;
    private DeviceService $deviceService;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->smsService = new SMSService($this->db);
        $this->deviceService = new DeviceService($this->db);
    }

    public function processPayload(array $payload, string $eventType = null): array
    {
        $eventType = $eventType ?? ($payload['event'] ?? $payload['type'] ?? 'unknown');
        
        $logId = $this->logWebhook($eventType, $payload);

        $this->updateDeviceFromWebhook($payload);

        try {
            $result = match ($eventType) {
                'message.status.updated', 'message.status', 'status_update', 'sms:status' => $this->handleStatusUpdate($payload),
                'message.received', 'sms.received', 'incoming_sms', 'sms:received' => $this->handleIncomingSMS($payload),
                'message.sent', 'sent', 'sms:sent' => $this->handleMessageSent($payload),
                'message.delivered', 'delivered', 'sms:delivered' => $this->handleMessageDelivered($payload),
                'message.failed', 'failed', 'sms:failed' => $this->handleMessageFailed($payload),
                default => $this->handleUnknownEvent($payload)
            };

            $this->markProcessed($logId, true);
            return $result;

        } catch (Exception $e) {
            $this->markProcessed($logId, false, $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function updateDeviceFromWebhook(array $payload): void
    {
        $deviceInfo = [];
        if (isset($payload['device']) && is_array($payload['device'])) {
            $deviceInfo = $payload['device'];
        } elseif (isset($payload['sender']) && is_array($payload['sender'])) {
            $deviceInfo = $payload['sender'];
        }

        $deviceId = $payload['deviceId']
            ?? $payload['device_id']
            ?? $payload['deviceID']
            ?? $deviceInfo['deviceId']
            ?? $deviceInfo['device_id']
            ?? $deviceInfo['id']
            ?? (is_string($payload['device'] ?? null) ? $payload['device'] : null)
            ?? (is_string($payload['sender'] ?? null) ? $payload['sender'] : null)
            ?? null;
        
        if (empty($deviceId)) {
            return;
        }

        $deviceData = [
            'status' => 'online',
            'last_seen' => date('Y-m-d H:i:s'),
        ];

        $battery = $payload['battery']
            ?? $payload['batteryLevel']
            ?? $payload['battery_level']
            ?? $payload['batterylevel']
            ?? $deviceInfo['battery']
            ?? $deviceInfo['batteryLevel']
            ?? $deviceInfo['battery_level']
            ?? null;
        if ($battery !== null && $battery !== '') {
            $deviceData['battery'] = (int)$battery;
        }

        $model = $payload['deviceModel']
            ?? $payload['device_model']
            ?? $payload['model']
            ?? $payload['phoneModel']
            ?? $deviceInfo['deviceModel']
            ?? $deviceInfo['device_model']
            ?? $deviceInfo['model']
            ?? $deviceInfo['phoneModel']
            ?? null;
        if ($model !== null && $model !== '') {
            $deviceData['model'] = (string)$model;
        }

        $manufacturer = $payload['deviceManufacturer']
            ?? $payload['device_manufacturer']
            ?? $payload['manufacturer']
            ?? $payload['phoneManufacturer']
            ?? $deviceInfo['deviceManufacturer']
            ?? $deviceInfo['device_manufacturer']
            ?? $deviceInfo['manufacturer']
            ?? $deviceInfo['phoneManufacturer']
            ?? null;
        if ($manufacturer !== null && $manufacturer !== '') {
            $deviceData['manufacturer'] = (string)$manufacturer;
        }

        $appVersion = $payload['appVersion']
            ?? $payload['app_version']
            ?? $deviceInfo['appVersion']
            ?? $deviceInfo['app_version']
            ?? $payload['version']
            ?? $deviceInfo['version']
            ?? null;
        if ($appVersion !== null && $appVersion !== '') {
            $deviceData['app_version'] = (string)$appVersion;
        }

        $this->deviceService->updateDeviceStatus((string)$deviceId, $deviceData);
    }

    private function handleStatusUpdate(array $payload): array
    {
        $externalId = $payload['messageId'] 
            ?? $payload['id'] 
            ?? $payload['msgId'] 
            ?? $payload['message_id']
            ?? $payload['msgId']
            ?? $payload['smsId']
            ?? null;
        
        $status = $payload['status'] 
            ?? $payload['state'] 
            ?? $payload['statusName']
            ?? $payload['type']
            ?? $payload['statusName']
            ?? null;
        
        $errorMessage = $payload['error'] 
            ?? $payload['errorMessage'] 
            ?? $payload['error_message'] 
            ?? null;
        
        if (!$externalId) {
            return ['success' => false, 'error' => 'Missing message ID in payload: ' . json_encode($payload)];
        }

        $this->updateMessageStatus($externalId, $status, $errorMessage);
        
        return [
            'success' => true,
            'action' => 'status_updated',
            'message_id' => $externalId,
            'status' => $status
        ];
    }

    private function handleIncomingSMS(array $payload): array
    {
        $phone = $payload['phone'] ?? $payload['from'] ?? $payload['source'] ?? null;
        $message = $payload['message'] ?? $payload['text'] ?? $payload['body'] ?? null;
        $timestamp = $payload['timestamp'] ?? $payload['date'] ?? null;
        $simSlot = $payload['simSlot'] ?? $payload['sim_slot'] ?? null;
        $deviceId = $payload['deviceId'] ?? $payload['device_id'] ?? null;
        
        if (!$phone || !$message) {
            return ['success' => false, 'error' => 'Missing phone or message'];
        }

        $stmt = $this->db->prepare("
            INSERT INTO received_messages (phone_number, message, sim_slot, received_at, device_id)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $phone,
            $message,
            $simSlot,
            $timestamp ? date('Y-m-d H:i:s', strtotime($timestamp)) : date('Y-m-d H:i:s'),
            $deviceId
        ]);

        $result = [
            'success' => true,
            'action' => 'sms_received_saved',
            'phone' => $phone,
            'message' => $message,
            'timestamp' => $timestamp
        ];

        $autoReply = $this->processAutoReply($phone, $message);
        if ($autoReply) {
            $result['auto_reply'] = $autoReply;
        }

        return $result;
    }

    private function processAutoReply(string $phone, string $message): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM auto_reply_rules 
            WHERE enabled = 1 
            ORDER BY priority ASC
        ");
        $stmt->execute();
        $rules = $stmt->fetchAll();

        foreach ($rules as $rule) {
            $keyword = $rule['trigger_keyword'];
            $matchType = $rule['match_type'];
            $caseSensitive = !empty($rule['case_sensitive']);
            $replyMessage = $rule['reply_message'];

            $messageToCheck = $caseSensitive ? $message : strtolower($message);
            $keywordToCheck = $caseSensitive ? $keyword : strtolower($keyword);

            $matched = false;
            switch ($matchType) {
                case 'exact':
                    $matched = $messageToCheck === $keywordToCheck;
                    break;
                case 'contains':
                    $matched = strpos($messageToCheck, $keywordToCheck) !== false;
                    break;
                case 'starts_with':
                    $matched = strpos($messageToCheck, $keywordToCheck) === 0;
                    break;
                case 'regex':
                    $matched = @preg_match('/' . $keywordToCheck . '/', $messageToCheck) === 1;
                    break;
            }

            if (!$matched) continue;

            if ($rule['max_replies_per_day'] > 0) {
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) FROM auto_reply_logs 
                    WHERE rule_id = ? AND DATE(created_at) = DATE('now')
                ");
                $stmt->execute([$rule['id']]);
                $todayCount = (int)$stmt->fetchColumn();

                if ($todayCount >= $rule['max_replies_per_day']) {
                    continue;
                }
            }

            $sendResult = $this->smsService->sendSingleSMS($phone, $replyMessage, 'auto', 5);

            $stmt = $this->db->prepare("
                UPDATE auto_reply_rules SET 
                    trigger_count = trigger_count + 1,
                    last_reply_at = CURRENT_TIMESTAMP,
                    reply_count_today = reply_count_today + 1
                WHERE id = ?
            ");
            $stmt->execute([$rule['id']]);

            $stmt = $this->db->prepare("
                INSERT INTO auto_reply_logs (rule_id, phone_number, received_message, reply_message, sent)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$rule['id'], $phone, $message, $replyMessage, $sendResult['success'] ? 1 : 0]);

            return [
                'success' => $sendResult['success'],
                'rule_id' => $rule['id'],
                'rule_name' => $rule['name'],
                'reply' => $replyMessage,
                'error' => $sendResult['error'] ?? null
            ];
        }

        return null;
    }

    private function handleMessageSent(array $payload): array
    {
        return $this->handleStatusUpdate($payload);
    }

    private function handleMessageDelivered(array $payload): array
    {
        return $this->handleStatusUpdate($payload);
    }

    private function handleMessageFailed(array $payload): array
    {
        return $this->handleStatusUpdate($payload);
    }

    private function handleUnknownEvent(array $payload): array
    {
        return [
            'success' => true,
            'action' => 'logged',
            'event' => 'unknown'
        ];
    }

    private function updateMessageStatus(string $externalId, ?string $status, ?string $errorMessage = null): void
    {
        $status = $this->normalizeStatus($status);
        
        if ($status === 'delivered') {
            $stmt = $this->db->prepare("
                UPDATE sent_messages SET 
                    status = ?,
                    delivered_at = CURRENT_TIMESTAMP,
                    updated_at = CURRENT_TIMESTAMP
                WHERE external_id = ?
            ");
            $stmt->execute([$status, $externalId]);
        } elseif ($status === 'failed') {
            $stmt = $this->db->prepare("
                UPDATE sent_messages SET 
                    status = ?,
                    error_message = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE external_id = ?
            ");
            $stmt->execute([$status, $errorMessage, $externalId]);
        } else {
            $stmt = $this->db->prepare("
                UPDATE sent_messages SET 
                    status = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE external_id = ?
            ");
            $stmt->execute([$status, $externalId]);
        }

        $stmt = $this->db->prepare("
            UPDATE sms_queue SET 
                status = ?,
                error_message = ?,
                updated_at = CURRENT_TIMESTAMP
            WHERE external_id = ?
        ");
        $stmt->execute([$status, $errorMessage, $externalId]);
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        
        $map = [
            'queued' => 'pending',
            'pending' => 'pending',
            'processed' => 'sent',
            'sent' => 'sent',
            'delivered' => 'delivered',
            'failed' => 'failed',
            'error' => 'failed',
            'cancelled' => 'cancelled',
        ];

        return $map[$status] ?? $status;
    }

    public function refreshPendingMessages(): int
    {
        $count = 0;
        
        $stmt = $this->db->prepare("
            SELECT external_id, id 
            FROM sent_messages 
            WHERE status IN ('pending', 'sent') 
            AND external_id IS NOT NULL
            LIMIT 100
        ");
        $stmt->execute();
        $messages = $stmt->fetchAll();
        
        foreach ($messages as $msg) {
            $state = $this->smsService->getMessageState($msg['external_id']);
            
            if ($state !== null) {
                $status = $state->State()->Value();
                $normalizedStatus = $this->normalizeStatus($status);
                
                if ($normalizedStatus !== $msg['status']) {
                    $this->updateMessageStatus($msg['external_id'], $status);
                    $count++;
                }
            }
        }
        
        return $count;
    }

    public function logWebhook(string $eventType, array $payload): int
    {
        $phone = $payload['phone'] ?? $payload['from'] ?? $payload['source'] ?? null;
        $message = $payload['message'] ?? $payload['text'] ?? $payload['body'] ?? null;
        $status = $payload['status'] ?? $payload['state'] ?? null;

        $stmt = $this->db->prepare("
            INSERT INTO webhook_logs (event_type, payload, source_phone, message, status)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $eventType,
            json_encode($payload),
            $phone,
            $message,
            $status
        ]);

        return (int)$this->db->lastInsertId();
    }

    private function markProcessed(int $logId, bool $processed, ?string $error = null): void
    {
        $stmt = $this->db->prepare("
            UPDATE webhook_logs SET 
                processed = ?,
                error_message = ?
            WHERE id = ?
        ");
        $stmt->execute([$processed ? 1 : 0, $error, $logId]);
    }

    public function getLogs(int $limit = 100, array $filters = []): array
    {
        $sql = "SELECT * FROM webhook_logs WHERE 1=1";
        $params = [];

        if (!empty($filters['event_type'])) {
            $sql .= " AND event_type = ?";
            $params[] = $filters['event_type'];
        }

        if (!empty($filters['processed'])) {
            $processed = $filters['processed'] === 'yes' ? 1 : 0;
            $sql .= " AND processed = ?";
            $params[] = $processed;
        }

        if (!empty($filters['search'])) {
            $sql .= " AND (source_phone LIKE ? OR message LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
        }

        if (!empty($filters['date_from'])) {
            $sql .= " AND created_at >= ?";
            $params[] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $sql .= " AND created_at <= ?";
            $params[] = $filters['date_to'];
        }

        $sql .= " ORDER BY created_at DESC LIMIT ?";
        $params[] = $limit;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getLogById(int $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM webhook_logs WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function getStats(): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN processed = 1 THEN 1 ELSE 0 END) as processed,
                SUM(CASE WHEN processed = 0 THEN 1 ELSE 0 END) as pending
            FROM webhook_logs
            WHERE created_at >= datetime('now', '-24 hours')
        ");
        $stmt->execute();
        $today = $stmt->fetch();

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM webhook_logs");
        $stmt->execute();
        $total = $stmt->fetchColumn();

        $stmt = $this->db->prepare("SELECT event_type, COUNT(*) as count FROM webhook_logs GROUP BY event_type ORDER BY count DESC");
        $stmt->execute();
        $byType = $stmt->fetchAll();

        return [
            'total' => (int)$total,
            'today' => (int)($today['total'] ?? 0),
            'processed_today' => (int)($today['processed'] ?? 0),
            'pending_today' => (int)($today['pending'] ?? 0),
            'by_event_type' => $byType
        ];
    }

    public function cleanupOldLogs(int $days = 30): int
    {
        $stmt = $this->db->prepare("
            DELETE FROM webhook_logs 
            WHERE created_at < datetime('now', '-' || ? || ' days')
        ");
        $stmt->execute([$days]);
        return $stmt->rowCount();
    }

    public function retryProcessing(int $logId): array
    {
        $log = $this->getLogById($logId);
        
        if (!$log) {
            return ['success' => false, 'error' => 'Log not found'];
        }

        $payload = json_decode($log['payload'], true);
        
        if (!$payload) {
            return ['success' => false, 'error' => 'Invalid payload'];
        }

        $this->markProcessed($logId, false);

        return $this->processPayload($payload, $log['event_type']);
    }
}
