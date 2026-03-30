<?php
/**
 * Bulk API - CSV upload and bulk send operations
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

$bulkSender = new BulkSender();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'parse':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                http_response_code(405);
                echo json_encode(['success' => false, 'error' => 'Method not allowed']);
                break;
            }

            if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                echo json_encode(['success' => false, 'error' => 'File upload failed']);
                break;
            }

            if ($_FILES['file']['size'] > 5 * 1024 * 1024) {
                echo json_encode(['success' => false, 'error' => 'File size exceeds 5MB limit']);
                break;
            }

            $result = $bulkSender->parseCSV($_FILES['file']['tmp_name']);
            echo json_encode($result);
            break;

        case 'send':
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

            $contactsJson = $_POST['contacts'] ?? '[]';
            $contacts = json_decode($contactsJson, true);
            
            if (!is_array($contacts) || empty($contacts)) {
                echo json_encode(['success' => false, 'error' => 'No contacts to send']);
                break;
            }

            $sendImmediately = isset($_POST['send_immediately']) && $_POST['send_immediately'] == '1';
            $filename = $_POST['filename'] ?? 'bulk_' . date('Ymd_His');

            $batchId = $bulkSender->createBatch($filename, count($contacts));
            $result = $bulkSender->queueBatch($contacts, $batchId, $sendImmediately);
            
            $bulkSender->updateBatchStatus($batchId, 'completed', [
                'sent' => $result['queued'],
                'failed' => $result['skipped']
            ]);

            echo json_encode([
                'success' => true,
                'batch_id' => $batchId,
                'queued' => $result['queued'],
                'skipped' => $result['skipped']
            ]);
            break;

        case 'status':
            $batchId = (int)($_GET['batch_id'] ?? 0);
            if (!$batchId) {
                echo json_encode(['success' => false, 'error' => 'Batch ID required']);
                break;
            }

            $batch = $bulkSender->getBatchStatus($batchId);
            if ($batch) {
                echo json_encode(['success' => true, 'data' => $batch]);
            } else {
                http_response_code(404);
                echo json_encode(['success' => false, 'error' => 'Batch not found']);
            }
            break;

        case 'batches':
            $batches = $bulkSender->getAllBatches();
            echo json_encode(['success' => true, 'data' => $batches]);
            break;

        case 'stats':
            $stats = $bulkSender->getBatchStats();
            echo json_encode(['success' => true, 'data' => $stats]);
            break;

        case 'sample':
            $sample = $bulkSender->getSampleCSV();
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="bulk_sms_template.csv"');
            echo $sample;
            exit;

        case 'validate_headers':
            $headers = $_POST['headers'] ?? [];
            if (is_string($headers)) {
                $headers = json_decode($headers, true) ?? [];
            }
            $errors = $bulkSender->validateCSVFormat($headers);
            echo json_encode(['success' => empty($errors), 'errors' => $errors]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
