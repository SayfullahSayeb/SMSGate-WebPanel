<?php
/**
 * Refresh API - Refresh message delivery statuses
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

if (!$smsService->isConfigured()) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'SMS Gateway not configured']);
    exit;
}

$db = Database::getInstance();
$updated = 0;
$failed = 0;

$stmt = $db->prepare("
    SELECT id, external_id 
    FROM sent_messages 
    WHERE status = 'sent' 
    AND (delivered_at IS NULL OR delivered_at = '')
    AND sent_at > datetime('now', '-7 days')
    ORDER BY sent_at DESC
    LIMIT 100
");
$stmt->execute();
$messages = $stmt->fetchAll();

foreach ($messages as $msg) {
    if (empty($msg['external_id'])) {
        continue;
    }

    $state = $smsService->getMessageState($msg['external_id']);
    
    if ($state !== null) {
        $stateValue = $state->State()->Value();
        
        if ($stateValue === 'DELIVERED') {
            $updateStmt = $db->prepare("
                UPDATE sent_messages 
                SET status = 'delivered', delivered_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $updateStmt->execute([$msg['id']]);
            $updated++;
        } elseif ($stateValue === 'FAILED') {
            $updateStmt = $db->prepare("
                UPDATE sent_messages 
                SET status = 'failed', error_message = 'Delivery failed', updated_at = CURRENT_TIMESTAMP 
                WHERE id = ?
            ");
            $updateStmt->execute([$msg['id']]);
            $failed++;
        }
    }
}

$stmt = $db->prepare("SELECT COUNT(*) FROM sent_messages WHERE status = 'sent' AND (delivered_at IS NULL OR delivered_at = '') AND sent_at > datetime('now', '-7 days')");
$stmt->execute();
$stillPending = (int)$stmt->fetchColumn();

echo json_encode([
    'success' => true,
    'message' => "Updated $updated delivered, $failed failed. $stillPending still pending.",
    'updated' => $updated,
    'failed' => $failed,
    'still_pending' => $stillPending,
]);
