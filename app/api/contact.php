<?php
/**
 * Contact API - CRUD operations for contacts
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
            $filters = [
                'group_id' => isset($_GET['group_id']) ? (int)$_GET['group_id'] : null,
                'search' => $_GET['search'] ?? null,
                'tag' => $_GET['tag'] ?? null,
                'limit' => (int)($_GET['limit'] ?? 100),
                'offset' => (int)($_GET['offset'] ?? 0)
            ];
            $contacts = $contactService->getAllContacts(array_filter($filters, fn($v) => $v !== null));
            echo json_encode(['success' => true, 'data' => $contacts]);
            break;

        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            $contact = $contactService->getContactById($id);
            if ($contact) {
                $contact['tags_array'] = json_decode($contact['tags'] ?? '[]', true);
                echo json_encode(['success' => true, 'data' => $contact]);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Contact not found']);
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

            $tags = $_POST['tags'] ?? [];
            if (is_string($tags)) {
                $tags = array_filter(array_map('trim', explode(',', $tags)));
            }

            $data = [
                'phone' => trim($_POST['phone'] ?? ''),
                'name' => trim($_POST['name'] ?? ''),
                'email' => trim($_POST['email'] ?? ''),
                'company' => trim($_POST['company'] ?? ''),
                'notes' => trim($_POST['notes'] ?? ''),
                'tags' => $tags,
                'group_id' => !empty($_POST['group_id']) ? (int)$_POST['group_id'] : null
            ];

            if (empty($data['phone'])) {
                echo json_encode(['success' => false, 'error' => 'Phone number is required']);
                break;
            }

            $result = $contactService->createContact($data);
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
                echo json_encode(['success' => false, 'error' => 'Contact ID required']);
                break;
            }

            $tags = $_POST['tags'] ?? [];
            if (is_string($tags)) {
                $tags = array_filter(array_map('trim', explode(',', $tags)));
            }

            $data = [
                'phone' => trim($_POST['phone'] ?? ''),
                'name' => trim($_POST['name'] ?? ''),
                'email' => trim($_POST['email'] ?? ''),
                'company' => trim($_POST['company'] ?? ''),
                'notes' => trim($_POST['notes'] ?? ''),
                'tags' => $tags,
                'group_id' => !empty($_POST['group_id']) ? (int)$_POST['group_id'] : null
            ];

            $result = $contactService->updateContact($id, $data);
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
                echo json_encode(['success' => false, 'error' => 'Contact ID required']);
                break;
            }

            $result = $contactService->deleteContact($id);
            echo json_encode(['success' => $result]);
            break;

        case 'delete_batch':
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

            $ids = $_POST['ids'] ?? [];
            if (is_string($ids)) {
                $ids = json_decode($ids, true) ?? [];
            }
            $ids = array_map('intval', $ids);

            if (empty($ids)) {
                echo json_encode(['success' => false, 'error' => 'No IDs provided']);
                break;
            }

            $deleted = $contactService->deleteContacts($ids);
            echo json_encode(['success' => true, 'deleted' => $deleted]);
            break;

        case 'import':
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

            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'error' => 'File upload failed']);
                break;
            }

            $filepath = $_FILES['file']['tmp_name'];
            $contacts = $this->parseCSV($filepath);
            
            if (!$contacts['success']) {
                echo json_encode($contacts);
                break;
            }

            $result = $contactService->importContacts($contacts['data']);
            echo json_encode(['success' => true, 'data' => $result]);
            break;

        case 'stats':
            $count = $contactService->getContactCount();
            $groups = $contactService->getGroupStats();
            echo json_encode([
                'success' => true, 
                'data' => [
                    'total_contacts' => $count,
                    'total_groups' => $groups['total_groups']
                ]
            ]);
            break;

        case 'by_group':
            $groupId = (int)($_GET['group_id'] ?? 0);
            $contacts = $contactService->getContactsByGroup($groupId);
            echo json_encode(['success' => true, 'data' => $contacts]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

function parseCSV(string $filepath): array
{
    $contacts = [];
    $handle = fopen($filepath, 'r');
    if (!$handle) return ['success' => false, 'error' => 'Cannot open file'];

    $headers = fgetcsv($handle);
    if (!$headers) {
        fclose($handle);
        return ['success' => false, 'error' => 'Empty CSV'];
    }

    $headers = array_map('strtolower', array_map('trim', $headers));
    $phoneIdx = array_search('phone', $headers);
    $nameIdx = array_search('name', $headers);
    $emailIdx = array_search('email', $headers);
    $companyIdx = array_search('company', $headers);
    $notesIdx = array_search('notes', $headers);
    $tagsIdx = array_search('tags', $headers);
    $groupIdx = array_search('group_id', $headers);

    while (($row = fgetcsv($handle)) !== false) {
        if (empty(array_filter($row))) continue;

        $phone = preg_replace('/[^0-9+]/', '', $row[$phoneIdx] ?? '');
        if (empty($phone)) continue;

        $contact = ['phone' => $phone];

        if ($nameIdx !== false) $contact['name'] = $row[$nameIdx] ?? '';
        if ($emailIdx !== false) $contact['email'] = $row[$emailIdx] ?? '';
        if ($companyIdx !== false) $contact['company'] = $row[$companyIdx] ?? '';
        if ($notesIdx !== false) $contact['notes'] = $row[$notesIdx] ?? '';
        if ($tagsIdx !== false) {
            $tags = $row[$tagsIdx] ?? '';
            $contact['tags'] = array_filter(array_map('trim', explode(',', $tags)));
        }
        if ($groupIdx !== false) $contact['group_id'] = (int)$row[$groupIdx] ?: null;

        $contacts[] = $contact;
    }

    fclose($handle);
    return ['success' => true, 'data' => $contacts];
}
