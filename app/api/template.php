<?php
/**
 * Template API - CRUD operations for message templates
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

$templateService = new TemplateService();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            $filters = [
                'category' => $_GET['category'] ?? null,
                'search' => $_GET['search'] ?? null,
                'limit' => $_GET['limit'] ?? 100
            ];
            $templates = $templateService->getAll(array_filter($filters));
            echo json_encode(['success' => true, 'data' => $templates]);
            break;

        case 'get':
            $id = (int)($_GET['id'] ?? 0);
            $template = $templateService->getById($id);
            if ($template) {
                $template['variables_array'] = json_decode($template['variables'] ?? '[]', true);
                echo json_encode(['success' => true, 'data' => $template]);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Template not found']);
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
                'subject' => trim($_POST['subject'] ?? ''),
                'content' => trim($_POST['content'] ?? ''),
                'category' => trim($_POST['category'] ?? 'general')
            ];

            if (empty($data['name']) || empty($data['content'])) {
                echo json_encode(['success' => false, 'error' => 'Name and content are required']);
                break;
            }

            $result = $templateService->create($data);
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
                echo json_encode(['success' => false, 'error' => 'Template ID required']);
                break;
            }

            $data = [
                'name' => trim($_POST['name'] ?? ''),
                'subject' => trim($_POST['subject'] ?? ''),
                'content' => trim($_POST['content'] ?? ''),
                'category' => trim($_POST['category'] ?? 'general')
            ];

            if (empty($data['name']) || empty($data['content'])) {
                echo json_encode(['success' => false, 'error' => 'Name and content are required']);
                break;
            }

            $result = $templateService->update($id, $data);
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
                echo json_encode(['success' => false, 'error' => 'Template ID required']);
                break;
            }

            $result = $templateService->delete($id);
            echo json_encode(['success' => $result]);
            break;

        case 'preview':
            $content = $_POST['content'] ?? '';
            $variables = $_POST['variables'] ?? [];
            
            if (is_string($variables)) {
                $variables = json_decode($variables, true) ?? [];
            }
            
            $preview = $templateService->preview($content, $variables);
            echo json_encode(['success' => true, 'preview' => $preview]);
            break;

        case 'variables':
            $content = $_POST['content'] ?? '';
            $variables = $templateService->extractVariables($content);
            echo json_encode(['success' => true, 'variables' => $variables]);
            break;

        case 'categories':
            $categories = $templateService->getCategories();
            echo json_encode(['success' => true, 'data' => $categories]);
            break;

        case 'stats':
            $stats = $templateService->getStats();
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
