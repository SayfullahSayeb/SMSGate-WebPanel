<?php
/**
 * Queue Processor - Background SMS queue processor
 */

declare(strict_types=1);

class QueueProcessor
{
    private Database $db;
    private SMSService $smsService;
    private RateLimiter $rateLimiter;
    private bool $running = false;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->smsService = new SMSService($this->db);
        $this->rateLimiter = new RateLimiter($this->db, $this->smsService);
    }

    public function process(int $batchSize = 10, bool $continuous = false, int $interval = 5): void
    {
        $this->running = true;
        
        do {
            $processed = $this->processBatch($batchSize);
            
            if ($continuous && $processed > 0) {
                sleep($interval);
            }
        } while ($continuous && $this->running);
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function processBatch(int $limit = 10): int
    {
        $processed = 0;
        $delay = $this->rateLimiter->getDelayBetweenSMS();

        // Get pending messages that are scheduled or ready
        $stmt = $this->db->prepare("
            SELECT * FROM sms_queue 
            WHERE status = 'pending' 
            AND (scheduled_at IS NULL OR scheduled_at <= datetime('now'))
            ORDER BY priority DESC, created_at ASC 
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $messages = $stmt->fetchAll();

        foreach ($messages as $message) {
            if (!$this->running) {
                break;
            }

            $result = $this->processMessage($message);
            
            if ($result) {
                $processed++;
            }

            // Apply delay between SMS
            if ($delay > 0) {
                usleep($delay * 1000000);
            }
        }

        return $processed;
    }

    private function processMessage(array $queueEntry): bool
    {
        $queueId = (int)$queueEntry['id'];
        
        // Check rate limits before sending
        $rateCheck = $this->rateLimiter->checkLimit('send_sms');
        
        if (!$rateCheck['allowed']) {
            $this->updateQueueStatus($queueId, 'rate_limited', null, "Rate limited: {$rateCheck['reason']}");
            return false;
        }

        // Update attempts
        $stmt = $this->db->prepare("
            UPDATE sms_queue 
            SET attempts = attempts + 1, updated_at = CURRENT_TIMESTAMP 
            WHERE id = ?
        ");
        $stmt->execute([$queueId]);

        // Send the SMS
        $result = $this->smsService->sendSingleSMS(
            $queueEntry['phone_number'],
            $queueEntry['message'],
            $queueEntry['sim_selection'],
            (int)$queueEntry['priority']
        );

        if ($result['success']) {
            $this->updateQueueStatus(
                $queueId,
                'sent',
                $result['external_id'] ?? null,
                null
            );
            
            // Record in history
            $this->recordSentMessage($queueEntry, $result['external_id'] ?? '', 'sent', null);
            
            return true;
        } else {
            $attempts = (int)$queueEntry['attempts'] + 1;
            $maxAttempts = (int)$queueEntry['max_attempts'];
            
            if ($attempts >= $maxAttempts) {
                $this->updateQueueStatus($queueId, 'failed', null, $result['error'] ?? 'Unknown error');
                $this->recordSentMessage($queueEntry, '', 'failed', $result['error'] ?? 'Unknown error');
            } else {
                $this->updateQueueStatus($queueId, 'pending', null, $result['error'] ?? 'Unknown error');
            }
            
            return false;
        }
    }

    private function updateQueueStatus(int $queueId, string $status, ?string $externalId, ?string $error): void
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
        $stmt->execute($params);
    }

    private function recordSentMessage(array $queueEntry, string $externalId, string $status, ?string $error = null): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO sent_messages (queue_id, phone_number, message, sim_selection, priority, external_id, status, error_message)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $queueEntry['id'],
            $queueEntry['phone_number'],
            $queueEntry['message'],
            $queueEntry['sim_selection'],
            $queueEntry['priority'],
            $externalId,
            $status,
            $error,
        ]);
    }

    public function getQueueStats(): array
    {
        $stats = [
            'pending' => 0,
            'sent' => 0,
            'failed' => 0,
            'rate_limited' => 0,
            'total' => 0,
        ];

        $stmt = $this->db->query("SELECT status, COUNT(*) as count FROM sms_queue GROUP BY status");
        while ($row = $stmt->fetch()) {
            $status = $row['status'];
            if (isset($stats[$status])) {
                $stats[$status] = (int)$row['count'];
            }
            $stats['total'] += (int)$row['count'];
        }

        return $stats;
    }

    public function retryFailed(int $queueId): bool
    {
        $stmt = $this->db->prepare("
            UPDATE sms_queue 
            SET status = 'pending', attempts = 0, error_message = NULL, updated_at = CURRENT_TIMESTAMP 
            WHERE id = ? AND status = 'failed'
        ");
        return $stmt->execute([$queueId]);
    }

    public function clearProcessed(int $daysOld = 7): int
    {
        $cutoff = date('Y-m-d H:i:s', strtotime("-{$daysOld} days"));
        
        $stmt = $this->db->prepare("
            DELETE FROM sms_queue 
            WHERE status IN ('sent', 'failed') AND updated_at < ?
        ");
        $stmt->execute([$cutoff]);
        
        return $stmt->rowCount();
    }
}
