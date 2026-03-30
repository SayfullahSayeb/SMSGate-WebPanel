<?php
/**
 * SMS Send API Endpoint
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
$rateLimiter = new RateLimiter();

$phone = trim($_POST['phone'] ?? '');
$message = trim($_POST['message'] ?? '');
$sim = $_POST['sim'] ?? 'auto';
$priority = (int)($_POST['priority'] ?? 5);
$queue = isset($_POST['queue']);

if (empty($phone) || empty($message)) {
    echo json_encode(['success' => false, 'error' => 'Phone and message are required']);
    exit;
}

if (!$smsService->isConfigured()) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'SMS Gateway not configured']);
    exit;
}

$rateCheck = $rateLimiter->checkLimit('send_sms');

if (!$rateCheck['allowed']) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error' => "Rate limit exceeded: {$rateCheck['reason']}",
        'rate_limit' => $rateCheck,
    ]);
    exit;
}

if ($queue) {
    $result = $smsService->queueMessage($phone, $message, $sim, $priority);
    echo json_encode([
        'success' => $result['success'],
        'queued' => true,
        'queue_id' => $result['queue_id'] ?? null,
        'message' => $result['success'] ? 'Message added to queue' : $result['error'],
    ]);
} else {
    $result = $smsService->sendSingleSMS($phone, $message, $sim, $priority);
    echo json_encode([
        'success' => $result['success'],
        'queued' => false,
        'external_id' => $result['external_id'] ?? null,
        'status' => $result['status'] ?? null,
        'message' => $result['success'] ? 'Message sent successfully' : $result['error'],
        'error' => $result['success'] ? null : $result['error'],
    ]);
}
