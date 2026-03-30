<?php
/**
 * Devices API - Device management endpoints
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

$deviceService = new DeviceService();
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'list':
            $devices = $deviceService->getAllDevices();
            $stats = $deviceService->getDeviceStats();
            echo json_encode([
                'success' => true,
                'data' => $devices,
                'stats' => $stats
            ]);
            break;

        case 'refresh':
            $result = $deviceService->refreshDevices();
            echo json_encode($result);
            break;

        case 'update_name':
            $deviceId = $_POST['device_id'] ?? '';
            $friendlyName = $_POST['friendly_name'] ?? '';

            if (empty($deviceId) || empty($friendlyName)) {
                echo json_encode(['success' => false, 'error' => 'Device ID and name required']);
                break;
            }

            $result = $deviceService->updateFriendlyName($deviceId, $friendlyName);
            echo json_encode($result);
            break;

        case 'delete':
            $deviceId = $_POST['device_id'] ?? '';

            if (empty($deviceId)) {
                echo json_encode(['success' => false, 'error' => 'Device ID required']);
                break;
            }

            $result = $deviceService->deleteDevice($deviceId);
            echo json_encode($result);
            break;

        case 'stats':
            $stats = $deviceService->getDeviceStats();
            echo json_encode(['success' => true, 'data' => $stats]);
            break;

        case 'webhook_logs':
            $db = Database::getInstance();
            $stmt = $db->prepare("SELECT * FROM webhook_logs ORDER BY created_at DESC LIMIT 20");
            $stmt->execute();
            $logs = $stmt->fetchAll();
            echo json_encode(['success' => true, 'data' => $logs]);
            break;

        case 'add':
            $deviceId = $_POST['device_id'] ?? '';
            $friendlyName = $_POST['friendly_name'] ?? '';
            $apiUrl = $_POST['api_url'] ?? '';
            $apiLogin = $_POST['api_login'] ?? '';
            $apiPassword = $_POST['api_password'] ?? '';
            $webhookUrl = $_POST['webhook_url'] ?? '';

            if (empty($deviceId) || empty($apiUrl) || empty($apiLogin) || empty($apiPassword)) {
                echo json_encode(['success' => false, 'error' => 'Device ID, API URL, Login and Password are required']);
                break;
            }

            $result = $deviceService->addDevice($deviceId, $friendlyName ?: $deviceId, $apiUrl, $apiLogin, $apiPassword, $webhookUrl);
            echo json_encode($result);
            break;

        case 'update_device':
            $deviceId = $_POST['device_id'] ?? '';
            $friendlyName = $_POST['friendly_name'] ?? '';
            $apiUrl = $_POST['api_url'] ?? '';
            $apiLogin = $_POST['api_login'] ?? '';
            $apiPassword = $_POST['api_password'] ?? null;
            $webhookUrl = $_POST['webhook_url'] ?? '';

            if (empty($deviceId) || empty($friendlyName)) {
                echo json_encode(['success' => false, 'error' => 'Device ID and name are required']);
                break;
            }

            $result = $deviceService->updateDevice($deviceId, $friendlyName, $apiUrl, $apiLogin, $apiPassword, $webhookUrl);
            echo json_encode($result);
            break;

        case 'test_connection':
            $apiUrl = $_POST['api_url'] ?? '';
            $apiLogin = $_POST['api_login'] ?? '';
            $apiPassword = $_POST['api_password'] ?? '';

            if (empty($apiUrl) || empty($apiLogin)) {
                echo json_encode(['success' => false, 'error' => 'API URL and Username required']);
                break;
            }

            if (empty($apiPassword)) {
                echo json_encode(['success' => false, 'error' => 'Enter password to test']);
                break;
            }

            try {
                $url = rtrim($apiUrl, '/');
                
                if (strpos($url, 'api.sms-gate.app') !== false && strpos($url, '/3rdparty/v1') === false) {
                    $url .= '/3rdparty/v1';
                }
                
                $testUrls = [
                    $url . '/health',
                    $url,
                    $url . '/api/health'
                ];
                
                $success = false;
                $lastError = '';
                
                foreach ($testUrls as $testUrl) {
                    $ch = curl_init();
                    curl_setopt($ch, CURLOPT_URL, $testUrl);
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    curl_setopt($ch, CURLOPT_HEADER, false);
                    curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
                    curl_setopt($ch, CURLOPT_USERPWD, $apiLogin . ':' . $apiPassword);
                    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
                    
                    $response = curl_exec($ch);
                    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $error = curl_error($ch);
                    curl_close($ch);
                    
                    if ($httpCode >= 200 && $httpCode < 400) {
                        $success = true;
                        break;
                    }
                    $lastError = "HTTP $httpCode";
                }
                
                if ($success) {
                    echo json_encode(['success' => true, 'message' => 'Connection successful']);
                } else {
                    echo json_encode(['success' => false, 'error' => 'Could not connect: ' . $lastError]);
                }
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'error' => $e->getMessage()]);
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
