<?php
/**
 * Health Check API - Simple endpoint to test if the API is working
 */

declare(strict_types=1);
error_reporting(0);
ini_set('display_errors', 0);

header('Content-Type: application/json');

try {
    require_once __DIR__ . '/../config.php';
    
    $db = Database::getInstance();
    $stmt = $db->prepare("SELECT COUNT(*) FROM settings");
    $stmt->execute();
    $settingsCount = $stmt->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'message' => 'API is working',
        'database' => 'connected',
        'settings_count' => $settingsCount
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
