<?php
/**
 * Queue Processor CLI Script
 * 
 * Usage:
 *   php process_queue.php              # Process single batch
 *   php process_queue.php --continuous # Run continuously
 *   php process_queue.php --daemon     # Run as daemon
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

$options = getopt('', ['continuous', 'daemon', 'help', 'batch::']);

if (isset($options['help'])) {
    echo <<<HELP
SMS Gateway Queue Processor

Usage:
  php process_queue.php [options]

Options:
  --continuous    Process messages continuously
  --daemon        Run as background daemon
  --batch=N       Process N messages per batch (default: 10)
  --help          Show this help message

Examples:
  php process_queue.php
  php process_queue.php --batch=20 --continuous

HELP;
    exit(0);
}

$batchSize = (int)($options['batch'] ?? 10);
$continuous = isset($options['continuous']);
$daemon = isset($options['daemon']);

$processor = new QueueProcessor();

if ($daemon) {
    echo "[*] Starting queue processor in daemon mode...\n";
    echo "[*] Batch size: {$batchSize}\n";
    echo "[*] Press Ctrl+C to stop\n\n";
    
    $running = true;
    
    if (function_exists('pcntl_signal')) {
        pcntl_signal(SIGINT, function() use (&$running) {
            echo "\n[*] Received SIGINT, shutting down...\n";
            $running = false;
        });
        pcntl_signal(SIGTERM, function() use (&$running) {
            echo "\n[*] Received SIGTERM, shutting down...\n";
            $running = false;
        });
    }
    
    $interval = 5;
    
    while ($running) {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
        
        $processed = $processor->processBatch($batchSize);
        $stats = $processor->getQueueStats();
        
        $time = date('Y-m-d H:i:s');
        echo "[{$time}] Processed: {$processed}, Pending: {$stats['pending']}, Sent: {$stats['sent']}, Failed: {$stats['failed']}\n";
        
        if ($processed > 0) {
            sleep($interval);
        } else {
            sleep($interval * 2);
        }
    }
    
    echo "[*] Queue processor stopped.\n";
} elseif ($continuous) {
    echo "[*] Starting queue processor in continuous mode...\n";
    echo "[*] Batch size: {$batchSize}\n";
    echo "[*] Press Ctrl+C to stop\n\n";
    
    $processor->process($batchSize, true);
} else {
    echo "[*] Processing single batch...\n";
    $processed = $processor->processBatch($batchSize);
    $stats = $processor->getQueueStats();
    
    echo "[*] Processed: {$processed} messages\n";
    echo "[*] Queue Stats:\n";
    echo "    Pending: {$stats['pending']}\n";
    echo "    Sent: {$stats['sent']}\n";
    echo "    Failed: {$stats['failed']}\n";
    echo "    Rate Limited: {$stats['rate_limited']}\n";
    echo "    Total: {$stats['total']}\n";
}
