<?php
/**
 * SMS Gateway Panel - Configuration
 * Lightweight PHP panel for SMS-Gate.app
 */

declare(strict_types=1);

define('APP_NAME', 'SMS Gateway Panel');
define('APP_VERSION', '1.0.0');

// Base paths
define('BASE_PATH', __DIR__);
define('INCLUDES_PATH', BASE_PATH . '/includes');
define('TEMPLATES_PATH', BASE_PATH . '/templates');
define('DATA_PATH', BASE_PATH . '/data');
define('VENDOR_PATH', dirname(BASE_PATH) . '/vendor');

// Database
define('DB_FILE', DATA_PATH . '/sms_panel.db');

// Session configuration
define('SESSION_NAME', 'sms_panel_session');
define('SESSION_LIFETIME', 3600 * 24); // 24 hours

// CSRF Token name
define('CSRF_TOKEN_NAME', 'csrf_token');

// Rate limiting defaults
define('DEFAULT_RATE_LIMIT_PER_MINUTE', 10);
define('DEFAULT_RATE_LIMIT_PER_HOUR', 100);
define('DEFAULT_RATE_LIMIT_PER_DAY', 500);
define('DEFAULT_DELAY_BETWEEN_SMS', 1); // seconds

// Timezone - Bangladesh (Dhaka)
date_default_timezone_set('Asia/Dhaka');

// Error reporting (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Create data directory if not exists
if (!is_dir(DATA_PATH)) {
    mkdir(DATA_PATH, 0755, true);
}

/**
 * Load composer's autoloader
 */
require_once VENDOR_PATH . '/autoload.php';

/**
 * Load all includes in specific order
 */
$includes = [
    'CSRF.php',
    'Database.php',
    'Auth.php',
    'RateLimiter.php',
    'SMSService.php',
    'DeviceService.php',
    'WebhookProcessor.php',
    'QueueProcessor.php',
    'ContactService.php',
    'TemplateService.php',
    'BulkSender.php',
];

foreach ($includes as $file) {
    $path = INCLUDES_PATH . '/' . $file;
    if (file_exists($path)) {
        require_once $path;
    }
}
