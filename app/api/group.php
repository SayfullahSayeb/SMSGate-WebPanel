<?php
/**
 * Group API - CRUD operations for contact groups
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

$contactService = new ContactService();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            $groups = $contactService->getAllGroups();
            echo json_encode(['success' => true, 'data' => $groups]);
            break;

        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            $group = $contactService->getGroupById($id);
            if ($group) {
                $contacts = $contactService->getContactsByGroup($id);
                $group['contacts'] = $contacts;
                echo json_encode(['success' => true, 'data' => $group]);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Group not found']);
            }
            break;

        case 'create':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                break;
            }

            if (!CSRF::validateToken($_POST['csrf_token'] ?? null)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Invalid token']);
                break;
            }

            $data = [
                'name' => trim($_POST['name'] ?? ''),
                'description' => trim($_POST['description'] ?? ''),
                'color' => trim($_POST['color'] ?? '#6B7280')
            ];

            if (empty($data['name'])) {
                echo json_encode(['success' => false, 'error' => 'Group name is required']);
                break;
            }

            $result = $contactService->createGroup($data);
            echo json_encode($result);
            break;

        case 'update':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                break;
            }

            if (!CSRF::validateToken($_POST['csrf_token'] ?? null)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Invalid token']);
                break;
            }

            $id = (int)($_POST['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'Group ID required']);
                break;
            }

            $data = [
                'name' => trim($_POST['name'] ?? ''),
                'description' => trim($_POST['description'] ?? ''),
                'color' => trim($_POST['color'] ?? '#6B7280')
            ];

            if (empty($data['name'])) {
                echo json_encode(['success' => false, 'error' => 'Group name is required']);
                break;
            }

            $result = $contactService->updateGroup($id, $data);
            echo json_encode($result);
            break;

        case 'delete':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                break;
            }

            if (!CSRF::validateToken($_POST['csrf_token'] ?? null)) {
                http_response_code(403);
                echo json_encode(['success' => false, 'error' => 'Invalid token']);
                break;
            }

            $id = (int)($_POST['id'] ?? 0);
            if (!$id) {
                echo json_encode(['success' => false, 'error' => 'Group ID required']);
                break;
            }

            $result = $contactService->deleteGroup($id);
            echo json_encode(['success' => $result]);
            break;

        case 'contacts':
            $groupId = (int)($_GET['group_id'] ?? 0);
            $contacts = $contactService->getContactsByGroup($groupId);
            echo json_encode(['success' => true, 'data' => $contacts]);
            break;

        case 'stats':
            $stats = $contactService->getGroupStats();
            echo json_encode(['success' => true, 'data' => $stats]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
