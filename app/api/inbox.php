<?php
/**
 * Inbox API - Handle received messages
 */

declare(strict_types=1);
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

try {
    $action = $_GET['action'] ?? '';
    
    $auth = new Auth();
    if (!$auth->isLoggedIn()) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Unauthorized']);
        exit;
    }
    
    $db = Database::getInstance();
    
    if ($action === 'get') {
        $id = (int)($_GET['id'] ?? 0);
        
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID required']);
            exit;
        }
        
        $stmt = $db->prepare("SELECT * FROM received_messages WHERE id = ?");
        $stmt->execute([$id]);
        $message = $stmt->fetch();
        
        if (!$message) {
            echo json_encode(['success' => false, 'error' => 'Message not found']);
            exit;
        }
        
        echo json_encode(['success' => true, 'data' => $message]);
        exit;
    }
    
    if ($action === 'mark_read') {
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        
        if (!$id) {
            echo json_encode(['success' => false, 'error' => 'ID required']);
            exit;
        }
        
        $stmt = $db->prepare("UPDATE received_messages SET is_read = 1 WHERE id = ?");
        $stmt->execute([$id]);
        
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($action === 'mark_all_read') {
        $stmt = $db->prepare("UPDATE received_messages SET is_read = 1 WHERE is_read = 0");
        $stmt->execute();
        
        echo json_encode(['success' => true, 'count' => $stmt->rowCount()]);
        exit;
    }
    
    if ($action === 'list') {
        $limit = (int)($_GET['limit'] ?? 50);
        $offset = (int)($_GET['offset'] ?? 0);
        
        $stmt = $db->prepare("SELECT * FROM received_messages ORDER BY received_at DESC LIMIT ? OFFSET ?");
        $stmt->execute([$limit, $offset]);
        $messages = $stmt->fetchAll();
        
        echo json_encode(['success' => true, 'data' => $messages]);
        exit;
    }
    
    if ($action === 'stats') {
        $stmt = $db->prepare("SELECT COUNT(*) FROM received_messages");
        $stmt->execute();
        $total = (int)$stmt->fetchColumn();
        
        $stmt = $db->prepare("SELECT COUNT(*) FROM received_messages WHERE is_read = 0");
        $stmt->execute();
        $unread = (int)$stmt->fetchColumn();
        
        echo json_encode(['success' => true, 'total' => $total, 'unread' => $unread]);
        exit;
    }
    
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
