<?php
/**
 * Queue Management API Endpoint
 */

declare(strict_types=1);
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$auth = new Auth();

if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!CSRF::validateToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit;
}

$smsService = new SMSService();
$action = $_POST['action'] ?? '';

switch ($action) {
    case 'delete':
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid ID']);
            exit;
        }
        $result = $smsService->deleteQueueMessage($id);
        echo json_encode(['success' => $result, 'message' => $result ? 'Deleted' : 'Failed']);
        break;
        
    case 'retry':
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid ID']);
            exit;
        }
        $result = $smsService->retryQueueMessage($id);
        echo json_encode(['success' => $result, 'message' => $result ? 'Queued for retry' : 'Failed']);
        break;
        
    case 'send_now':
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid ID']);
            exit;
        }
        $stmt = Database::getInstance()->prepare("UPDATE sms_queue SET scheduled_at = NULL, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status = 'pending'");
        $result = $stmt->execute([$id]);
        echo json_encode(['success' => $result, 'message' => $result ? 'Will be sent shortly' : 'Failed']);
        break;
        
    case 'process':
        $processor = new QueueProcessor();
        $processed = $processor->processBatch((int)($_POST['batch'] ?? 10));
        echo json_encode([
            'success' => true,
            'processed' => $processed,
            'stats' => $processor->getQueueStats(),
        ]);
        break;
        
    case 'status':
        $processor = new QueueProcessor();
        echo json_encode(['success' => true, 'stats' => $processor->getQueueStats()]);
        break;
        
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action']);
}
