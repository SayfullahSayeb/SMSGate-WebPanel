<?php
/**
 * History API - Message history and logs
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

$db = Database::getInstance();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 20)));
            $offset = ($page - 1) * $perPage;

            $where = ['1=1'];
            $params = [];

            if (!empty($_GET['status'])) {
                $where[] = "status = ?";
                $params[] = $_GET['status'];
            }

            if (!empty($_GET['search'])) {
                $where[] = "(phone_number LIKE ? OR message LIKE ? OR external_id LIKE ?)";
                $search = '%' . $_GET['search'] . '%';
                $params[] = $search;
                $params[] = $search;
                $params[] = $search;
            }

            if (!empty($_GET['date_from'])) {
                $where[] = "sent_at >= ?";
                $params[] = $_GET['date_from'] . ' 00:00:00';
            }

            if (!empty($_GET['date_to'])) {
                $where[] = "sent_at <= ?";
                $params[] = $_GET['date_to'] . ' 23:59:59';
            }

            $whereClause = implode(' AND ', $where);

            $stmt = $db->prepare("SELECT COUNT(*) FROM sent_messages WHERE $whereClause");
            $stmt->execute($params);
            $total = (int)$stmt->fetchColumn();

            $sql = "SELECT * FROM sent_messages WHERE $whereClause ORDER BY sent_at DESC LIMIT ? OFFSET ?";
            $params[] = $perPage;
            $params[] = $offset;

            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $messages = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'data' => $messages,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'total_pages' => ceil($total / $perPage)
                ]
            ]);
            break;

        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            $stmt = $db->prepare("SELECT * FROM sent_messages WHERE id = ?");
            $stmt->execute([$id]);
            $message = $stmt->fetch();
            
            if ($message) {
                echo json_encode(['success' => true, 'data' => $message]);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Message not found']);
            }
            break;

        case 'stats':
            $today = date('Y-m-d');
            $stmt = $db->prepare("
                SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
                    SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                    SUM(CASE WHEN DATE(sent_at) = ? THEN 1 ELSE 0 END) as today_total
                FROM sent_messages
            ");
            $stmt->execute([$today]);
            $stats = $stmt->fetch();

            $stmt = $db->prepare("
                SELECT DATE(sent_at) as date, COUNT(*) as count 
                FROM sent_messages 
                WHERE sent_at >= datetime('now', '-7 days')
                GROUP BY DATE(sent_at)
                ORDER BY date ASC
            ");
            $stmt->execute();
            $dailyStats = $stmt->fetchAll();

            echo json_encode([
                'success' => true,
                'data' => [
                    'total' => (int)($stats['total'] ?? 0),
                    'sent' => (int)($stats['sent'] ?? 0),
                    'delivered' => (int)($stats['delivered'] ?? 0),
                    'failed' => (int)($stats['failed'] ?? 0),
                    'today' => (int)($stats['today_total'] ?? 0),
                    'daily' => $dailyStats
                ]
            ]);
            break;

        case 'export':
            $where = ['1=1'];
            $params = [];

            if (!empty($_GET['status'])) {
                $where[] = "status = ?";
                $params[] = $_GET['status'];
            }

            if (!empty($_GET['date_from'])) {
                $where[] = "sent_at >= ?";
                $params[] = $_GET['date_from'] . ' 00:00:00';
            }

            if (!empty($_GET['date_to'])) {
                $where[] = "sent_at <= ?";
                $params[] = $_GET['date_to'] . ' 23:59:59';
            }

            $whereClause = implode(' AND ', $where);
            $stmt = $db->prepare("SELECT * FROM sent_messages WHERE $whereClause ORDER BY sent_at DESC");
            $stmt->execute($params);
            $messages = $stmt->fetchAll();

            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="sms_history_' . date('Ymd_His') . '.csv"');
            
            $output = fopen('php://output', 'w');
            fputcsv($output, ['ID', 'Phone', 'Message', 'SIM', 'Priority', 'Status', 'External ID', 'Sent At']);
            
            foreach ($messages as $msg) {
                fputcsv($output, [
                    $msg['id'],
                    $msg['phone_number'],
                    $msg['message'],
                    $msg['sim_selection'],
                    $msg['priority'],
                    $msg['status'],
                    $msg['external_id'],
                    $msg['sent_at']
                ]);
            }
            
            fclose($output);
            exit;

        case 'statuses':
            echo json_encode([
                'success' => true,
                'data' => ['pending', 'sent', 'delivered', 'failed', 'cancelled']
            ]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
