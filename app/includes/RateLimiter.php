<?php
/**
 * Rate Limiter - SMS rate limiting and throttling
 */

declare(strict_types=1);

class RateLimiter
{
    private Database $db;
    private SMSService $smsService;
    
    private const WINDOW_MINUTE = 60;
    private const WINDOW_HOUR = 3600;
    private const WINDOW_DAY = 86400;

    public function __construct(?Database $db = null, ?SMSService $smsService = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->smsService = $smsService ?? new SMSService($this->db);
    }

    public function checkLimit(string $action = 'send_sms'): array
    {
        $ip = $this->getClientIP();
        $now = time();
        
        $perMinute = (int)$this->smsService->getSetting('rate_per_minute', DEFAULT_RATE_LIMIT_PER_MINUTE);
        $perHour = (int)$this->smsService->getSetting('rate_per_hour', DEFAULT_RATE_LIMIT_PER_HOUR);
        $perDay = (int)$this->smsService->getSetting('rate_per_day', DEFAULT_RATE_LIMIT_PER_DAY);

        // Clean old entries first
        $this->cleanupOldEntries();

        // Check per-minute limit
        $minuteCount = $this->getCountForWindow($ip, $action, $now - self::WINDOW_MINUTE);
        if ($minuteCount >= $perMinute) {
            return [
                'allowed' => false,
                'reason' => 'minute_limit',
                'limit' => $perMinute,
                'current' => $minuteCount,
                'retry_after' => self::WINDOW_MINUTE - ($now % self::WINDOW_MINUTE),
            ];
        }

        // Check per-hour limit
        $hourCount = $this->getCountForWindow($ip, $action, $now - self::WINDOW_HOUR);
        if ($hourCount >= $perHour) {
            return [
                'allowed' => false,
                'reason' => 'hour_limit',
                'limit' => $perHour,
                'current' => $hourCount,
                'retry_after' => self::WINDOW_HOUR - ($now % self::WINDOW_HOUR),
            ];
        }

        // Check per-day limit
        $dayCount = $this->getCountForWindow($ip, $action, $now - self::WINDOW_DAY);
        if ($dayCount >= $perDay) {
            return [
                'allowed' => false,
                'reason' => 'day_limit',
                'limit' => $perDay,
                'current' => $dayCount,
                'retry_after' => self::WINDOW_DAY - ($now % self::WINDOW_DAY),
            ];
        }

        // Record this attempt
        $this->recordAttempt($ip, $action);

        return [
            'allowed' => true,
            'limits' => [
                'minute' => ['current' => $minuteCount + 1, 'limit' => $perMinute],
                'hour' => ['current' => $hourCount + 1, 'limit' => $perHour],
                'day' => ['current' => $dayCount + 1, 'limit' => $perDay],
            ],
        ];
    }

    public function getRateStatus(): array
    {
        $ip = $this->getClientIP();
        $now = time();
        
        $perMinute = (int)$this->smsService->getSetting('rate_per_minute', DEFAULT_RATE_LIMIT_PER_MINUTE);
        $perHour = (int)$this->smsService->getSetting('rate_per_hour', DEFAULT_RATE_LIMIT_PER_HOUR);
        $perDay = (int)$this->smsService->getSetting('rate_per_day', DEFAULT_RATE_LIMIT_PER_DAY);

        $minuteCount = $this->getCountForWindow($ip, 'send_sms', $now - self::WINDOW_MINUTE);
        $hourCount = $this->getCountForWindow($ip, 'send_sms', $now - self::WINDOW_HOUR);
        $dayCount = $this->getCountForWindow($ip, 'send_sms', $now - self::WINDOW_DAY);

        return [
            'minute' => [
                'current' => $minuteCount,
                'limit' => $perMinute,
                'percentage' => round(($minuteCount / $perMinute) * 100, 1),
            ],
            'hour' => [
                'current' => $hourCount,
                'limit' => $perHour,
                'percentage' => round(($hourCount / $perHour) * 100, 1),
            ],
            'day' => [
                'current' => $dayCount,
                'limit' => $perDay,
                'percentage' => round(($dayCount / $perDay) * 100, 1),
            ],
        ];
    }

    public function getDelayBetweenSMS(): int
    {
        return (int)$this->smsService->getSetting('delay_between_sms', DEFAULT_DELAY_BETWEEN_SMS);
    }

    public function shouldWarnUser(array $rateStatus): array
    {
        $warnings = [];
        
        foreach ($rateStatus as $period => $data) {
            if ($data['percentage'] >= 90) {
                $warnings[] = [
                    'period' => $period,
                    'level' => 'critical',
                    'message' => "You have used {$data['current']}/{$data['limit']} SMS ({$data['percentage']}%) for the {$period}. Limit nearly reached!",
                ];
            } elseif ($data['percentage'] >= 75) {
                $warnings[] = [
                    'period' => $period,
                    'level' => 'warning',
                    'message' => "You have used {$data['current']}/{$data['limit']} SMS ({$data['percentage']}%) for the {$period}. Consider slowing down.",
                ];
            }
        }
        
        return $warnings;
    }

    private function getCountForWindow(string $ip, string $action, int $windowStart): int
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(count), 0) 
            FROM rate_limits 
            WHERE ip_address = ? AND action = ? AND window_start > datetime(?, 'unixepoch')
        ");
        $stmt->execute([$ip, $action, $windowStart]);
        return (int)$stmt->fetchColumn();
    }

    private function recordAttempt(string $ip, string $action): void
    {
        $windowStart = date('Y-m-d H:i:s', time() - (time() % self::WINDOW_MINUTE));
        
        // Try to update existing record
        $stmt = $this->db->prepare("
            UPDATE rate_limits 
            SET count = count + 1 
            WHERE ip_address = ? AND action = ? AND window_start = ?
        ");
        $stmt->execute([$ip, $action, $windowStart]);
        
        // If no row was updated, insert new record
        if ($stmt->rowCount() === 0) {
            $stmt = $this->db->prepare("
                INSERT INTO rate_limits (ip_address, action, count, window_start)
                VALUES (?, ?, 1, ?)
            ");
            $stmt->execute([$ip, $action, $windowStart]);
        }
    }

    private function cleanupOldEntries(): void
    {
        $cutoff = date('Y-m-d H:i:s', time() - self::WINDOW_DAY);
        $stmt = $this->db->prepare("DELETE FROM rate_limits WHERE window_start < ?");
        $stmt->execute([$cutoff]);
    }

    private function getClientIP(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
    }
}
