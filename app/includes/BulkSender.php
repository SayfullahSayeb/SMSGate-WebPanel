<?php
/**
 * Bulk Sender - CSV upload and bulk SMS operations
 */

declare(strict_types=1);

class BulkSender
{
    private Database $db;
    private SMSService $smsService;
    private RateLimiter $rateLimiter;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->smsService = new SMSService($this->db);
        $this->rateLimiter = new RateLimiter($this->db);
    }

    public function parseCSV(string $filepath): array
    {
        $contacts = [];
        $errors = [];
        
        if (!file_exists($filepath)) {
            return ['success' => false, 'error' => 'File not found'];
        }

        $handle = fopen($filepath, 'r');
        if ($handle === false) {
            return ['success' => false, 'error' => 'Cannot open file'];
        }

        $headers = fgetcsv($handle);
        if ($headers === false || empty($headers)) {
            fclose($handle);
            return ['success' => false, 'error' => 'Empty CSV file or invalid format'];
        }

        $headers = array_map('strtolower', array_map('trim', $headers));
        
        $phoneIndex = array_search('phone', $headers);
        $messageIndex = array_search('message', $headers);
        
        if ($phoneIndex === false) {
            fclose($handle);
            return ['success' => false, 'error' => 'Missing required column: phone'];
        }
        
        if ($messageIndex === false) {
            fclose($handle);
            return ['success' => false, 'error' => 'Missing required column: message'];
        }

        $simIndex = array_search('sim', $headers);
        $priorityIndex = array_search('priority', $headers);
        $nameIndex = array_search('name', $headers);
        $scheduledIndex = array_search('scheduled_at', $headers);

        $rowNum = 1;
        while (($row = fgetcsv($handle)) !== false) {
            $rowNum++;
            
            if (empty(array_filter($row))) {
                continue;
            }

            $phone = $this->normalizePhone($row[$phoneIndex] ?? '');
            $message = $row[$messageIndex] ?? '';

            if (empty($phone)) {
                $errors[] = "Row $rowNum: Missing phone number";
                continue;
            }

            if (empty($message)) {
                $errors[] = "Row $rowNum: Missing message";
                continue;
            }

            if (!$this->validatePhone($phone)) {
                $errors[] = "Row $rowNum: Invalid phone format ($phone)";
                continue;
            }

            $contact = [
                'phone' => $phone,
                'message' => $message,
                'sim' => ($simIndex !== false) ? ($row[$simIndex] ?: 'auto') : 'auto',
                'priority' => ($priorityIndex !== false) ? ((int)$row[$priorityIndex] ?: 5) : 5,
                'name' => ($nameIndex !== false) ? ($row[$nameIndex] ?? null) : null,
                'scheduled_at' => ($scheduledIndex !== false) ? ($row[$scheduledIndex] ?? null) : null,
            ];

            $contact['message'] = $this->processVariables($contact['message'], $row, $headers);
            
            $contacts[] = $contact;
        }

        fclose($handle);

        return [
            'success' => true,
            'contacts' => $contacts,
            'errors' => $errors,
            'total_rows' => $rowNum - 1,
            'valid_count' => count($contacts)
        ];
    }

    private function processVariables(string $message, array $row, array $headers): string
    {
        if (preg_match_all('/\{\{(\w+)\}\}/', $message, $matches)) {
            foreach ($matches[1] as $var) {
                $varLower = strtolower($var);
                $index = array_search($varLower, $headers);
                if ($index !== false && isset($row[$index]) && !empty($row[$index])) {
                    $message = str_replace('{{' . $var . '}}', $row[$index], $message);
                }
            }
        }
        return $message;
    }

    public function createBatch(string $filename, int $totalRecipients): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO bulk_batches (filename, total_recipients, status)
            VALUES (?, ?, 'pending')
        ");
        $stmt->execute([$filename, $totalRecipients]);
        return (int)$this->db->lastInsertId();
    }

    public function updateBatchStatus(int $batchId, string $status, array $counts = []): void
    {
        $sql = "UPDATE bulk_batches SET status = ?, updated_at = CURRENT_TIMESTAMP";
        $params = [$status];

        if (isset($counts['sent'])) {
            $sql .= ", sent_count = ?";
            $params[] = $counts['sent'];
        }

        if (isset($counts['failed'])) {
            $sql .= ", failed_count = ?";
            $params[] = $counts['failed'];
        }

        if ($status === 'completed') {
            $sql .= ", completed_at = CURRENT_TIMESTAMP";
        }

        $sql .= " WHERE id = ?";
        $params[] = $batchId;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    public function queueBatch(array $contacts, int $batchId = null, bool $sendImmediately = false): array
    {
        $queued = 0;
        $skipped = 0;

        foreach ($contacts as $contact) {
            if (!$this->rateLimiter->checkLimit('sms', 1)) {
                $skipped++;
                continue;
            }

            if ($sendImmediately) {
                $result = $this->smsService->sendSingleSMS(
                    $contact['phone'],
                    $contact['message'],
                    $contact['sim'] ?? 'auto',
                    $contact['priority'] ?? 5
                );
                
                if (!$result['success']) {
                    $this->smsService->queueMessage(
                        $contact['phone'],
                        $contact['message'],
                        $contact['sim'] ?? 'auto',
                        $contact['priority'] ?? 5,
                        $contact['scheduled_at'] ?? null
                    );
                }
            } else {
                $this->smsService->queueMessage(
                    $contact['phone'],
                    $contact['message'],
                    $contact['sim'] ?? 'auto',
                    $contact['priority'] ?? 5,
                    $contact['scheduled_at'] ?? null
                );
            }

            $queued++;
        }

        return [
            'queued' => $queued,
            'skipped' => $skipped,
            'batch_id' => $batchId
        ];
    }

    public function getBatchStatus(int $batchId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM bulk_batches WHERE id = ?");
        $stmt->execute([$batchId]);
        return $stmt->fetch() ?: null;
    }

    public function getAllBatches(int $limit = 20): array
    {
        $stmt = $this->db->prepare("SELECT * FROM bulk_batches ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }

    public function getBatchStats(): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as total_batches,
                SUM(total_recipients) as total_recipients,
                SUM(sent_count) as total_sent,
                SUM(failed_count) as total_failed
            FROM bulk_batches
        ");
        $stmt->execute();
        return $stmt->fetch();
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[^0-9+]/', '', $phone);
    }

    private function validatePhone(string $phone): bool
    {
        $cleaned = $this->normalizePhone($phone);
        return preg_match('/^\+?[1-9]\d{7,14}$/', $cleaned) === 1;
    }

    public function validateCSVFormat(array $headers): array
    {
        $errors = [];
        $headers = array_map('strtolower', array_map('trim', $headers));
        
        if (!in_array('phone', $headers)) {
            $errors[] = 'Missing required column: phone';
        }
        
        if (!in_array('message', $headers)) {
            $errors[] = 'Missing required column: message';
        }

        $validColumns = ['phone', 'message', 'sim', 'priority', 'name', 'scheduled_at'];
        $unknownColumns = array_diff($headers, $validColumns);
        if (!empty($unknownColumns)) {
            $errors[] = 'Unknown columns: ' . implode(', ', $unknownColumns);
        }

        return $errors;
    }

    public function getSampleCSV(): string
    {
        return "phone,message,sim,priority\n+1234567890,Hello {{name}},auto,5\n+0987654321,Your appointment is on {{date}},1,9";
    }

    public function incrementBatchCount(int $batchId, string $type): void
    {
        $column = $type === 'sent' ? 'sent_count' : 'failed_count';
        $stmt = $this->db->prepare("UPDATE bulk_batches SET {$column} = {$column} + 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$batchId]);
    }

    public function completeBatch(int $batchId): void
    {
        $stmt = $this->db->prepare("UPDATE bulk_batches SET status = 'completed', completed_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$batchId]);
    }
}
