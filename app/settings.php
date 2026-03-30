<?php
/**
 * Settings Page
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';

$auth = new Auth();
$auth->requireLogin();

$smsService = new SMSService();
$pendingCount = $smsService->getPendingQueueCount();
$db = Database::getInstance();

$showDefaultPasswordWarning = false;
$stmt = $db->prepare("SELECT password FROM users WHERE username = 'admin'");
$stmt->execute();
$adminHash = $stmt->fetchColumn();
if ($adminHash && password_verify('admin123', $adminHash)) {
    $showDefaultPasswordWarning = true;
}

$settings = [
    'rate_per_minute' => $smsService->getSetting('rate_per_minute', DEFAULT_RATE_LIMIT_PER_MINUTE),
    'rate_per_hour' => $smsService->getSetting('rate_per_hour', DEFAULT_RATE_LIMIT_PER_HOUR),
    'rate_per_day' => $smsService->getSetting('rate_per_day', DEFAULT_RATE_LIMIT_PER_DAY),
    'delay_between_sms' => $smsService->getSetting('delay_between_sms', DEFAULT_DELAY_BETWEEN_SMS),
    'default_sim' => $smsService->getSetting('default_sim', 'auto'),
    'default_priority' => $smsService->getSetting('default_priority', '5'),
];

$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validateToken($_POST['csrf_token'] ?? null)) {
        $message = 'Invalid request token.';
        $messageType = 'danger';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'save_rate_limits') {
            $smsService->updateSetting('rate_per_minute', (int)($_POST['rate_per_minute'] ?? 10));
            $smsService->updateSetting('rate_per_hour', (int)($_POST['rate_per_hour'] ?? 100));
            $smsService->updateSetting('rate_per_day', (int)($_POST['rate_per_day'] ?? 500));
            $smsService->updateSetting('delay_between_sms', (int)($_POST['delay_between_sms'] ?? 1));
            
            $message = 'Rate limit settings saved successfully.';
            $messageType = 'success';
            
        } elseif ($action === 'save_defaults') {
            $smsService->updateSetting('default_sim', $_POST['default_sim'] ?? 'auto');
            $smsService->updateSetting('default_priority', (int)($_POST['default_priority'] ?? 5));
            
            $message = 'Default settings saved successfully.';
            $messageType = 'success';
            
        } else        if ($action === 'save_webhook_url') {
            $webhookUrl = $_POST['webhook_url'] ?? '';
            $smsService->updateSetting('webhook_url', $webhookUrl);
            
            $message = 'Webhook URL saved successfully.';
            $messageType = 'success';
            
        } elseif ($action === 'change_password') {
            $currentPassword = $_POST['current_password'] ?? '';
            $newPassword = $_POST['new_password'] ?? '';
            $confirmPassword = $_POST['confirm_password'] ?? '';
            
            $user = $auth->getCurrentUser();
            if (!$user) {
                $message = 'User not found.';
                $messageType = 'danger';
            } else {
                $db = Database::getInstance();
                $stmt = $db->prepare("SELECT password FROM users WHERE id = ?");
                $stmt->execute([$user['id']]);
                $storedHash = $stmt->fetchColumn();
                
                if (!password_verify($currentPassword, $storedHash)) {
                    $message = 'Current password is incorrect.';
                    $messageType = 'danger';
                } elseif (strlen($newPassword) < 6) {
                    $message = 'New password must be at least 6 characters.';
                    $messageType = 'danger';
                } elseif ($newPassword !== $confirmPassword) {
                    $message = 'New passwords do not match.';
                    $messageType = 'danger';
                } else {
                    $auth->changePassword($user['id'], $newPassword);
                    $message = 'Password changed successfully.';
                    $messageType = 'success';
                }
            }
        }
        
        $settings = [
            'rate_per_minute' => $smsService->getSetting('rate_per_minute', DEFAULT_RATE_LIMIT_PER_MINUTE),
            'rate_per_hour' => $smsService->getSetting('rate_per_hour', DEFAULT_RATE_LIMIT_PER_HOUR),
            'rate_per_day' => $smsService->getSetting('rate_per_day', DEFAULT_RATE_LIMIT_PER_DAY),
            'delay_between_sms' => $smsService->getSetting('delay_between_sms', DEFAULT_DELAY_BETWEEN_SMS),
            'default_sim' => $smsService->getSetting('default_sim', 'auto'),
            'default_priority' => $smsService->getSetting('default_priority', '5'),
        ];
    }
}

ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title">Settings</h1>
        <p class="page-meta mb-0">Manage your SMS Panel configuration</p>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-<?= $messageType ?> mb-4">
    <i class="bi bi-<?= $messageType === 'success' ? 'check-circle-fill' : 'exclamation-circle-fill' ?>"></i>
    <span><?= htmlspecialchars($message) ?></span>
    <button type="button" class="btn-close float-end" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($showDefaultPasswordWarning): ?>
<div class="alert alert-warning mb-4">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <strong>Security Warning:</strong> You are using the default password. Please change your password immediately for security.
    <a href="#change-password" class="alert-link">Change Password</a>
</div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card mb-4" id="change-password">
            <div class="card-header">
                <h5><i class="bi bi-shield-lock"></i>Change Password</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= CSRF::getField() ?>
                    <input type="hidden" name="action" value="change_password">
                    
                    <div class="mb-3">
                        <label class="form-label">Current Password</label>
                        <input type="password" class="form-control" name="current_password" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" class="form-control" name="new_password" required minlength="6">
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" class="form-control" name="confirm_password" required minlength="6">
                    </div>
                    
                    <button type="submit" class="btn btn-secondary w-100">
                        <i class="bi bi-key"></i> Change Password
                    </button>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-sliders"></i>Default Values</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= CSRF::getField() ?>
                    <input type="hidden" name="action" value="save_defaults">
                    
                    <div class="mb-3">
                        <label class="form-label">Default SIM Card</label>
                        <select class="form-select" name="default_sim">
                            <option value="auto" <?= $settings['default_sim'] === 'auto' ? 'selected' : '' ?>>Auto - System picks</option>
                            <option value="1" <?= $settings['default_sim'] === '1' ? 'selected' : '' ?>>SIM 1</option>
                            <option value="2" <?= $settings['default_sim'] === '2' ? 'selected' : '' ?>>SIM 2</option>
                        </select>
                        <div class="form-text">Default SIM selection for new messages</div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label">Default Priority</label>
                        <select class="form-select" name="default_priority">
                            <option value="1" <?= $settings['default_priority'] === '1' ? 'selected' : '' ?>>Low (1)</option>
                            <option value="5" <?= $settings['default_priority'] === '5' ? 'selected' : '' ?>>Normal (5)</option>
                            <option value="9" <?= $settings['default_priority'] === '9' ? 'selected' : '' ?>>High (9)</option>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-check-lg"></i> Save Defaults
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-speedometer2"></i>Rate Limiting</h5>
            </div>
            <div class="card-body">
                <form method="POST" id="rateLimitForm">
                    <?= CSRF::getField() ?>
                    <input type="hidden" name="action" value="save_rate_limits">
                    
                    <div class="alert alert-info mb-3">
                        <i class="bi bi-info-circle"></i>
                        <strong>Control your sending rate</strong> to avoid carrier limitations. Messages exceeding limits are queued.
                    </div>
                    
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label">Messages / Minute</label>
                            <input type="number" class="form-control rate-input" name="rate_per_minute" id="rate_per_minute" value="<?= (int)$settings['rate_per_minute'] ?>" min="1" max="1000" required>
                            <div class="form-text" id="perMinuteDesc">1 message every <?= round(60 / max(1, (int)$settings['rate_per_minute']), 1) ?>s</div>
                        </div>
                        
                        <div class="col-6">
                            <label class="form-label">Messages / Hour</label>
                            <input type="number" class="form-control rate-input" name="rate_per_hour" id="rate_per_hour" value="<?= (int)$settings['rate_per_hour'] ?>" min="1" max="10000" required>
                            <div class="form-text">Max <?= (int)$settings['rate_per_hour'] ?> per hour</div>
                        </div>
                        
                        <div class="col-6">
                            <label class="form-label">Messages / Day</label>
                            <input type="number" class="form-control rate-input" name="rate_per_day" id="rate_per_day" value="<?= (int)$settings['rate_per_day'] ?>" min="1" max="100000" required>
                            <div class="form-text">Max <?= (int)$settings['rate_per_day'] ?> per day</div>
                        </div>
                        
                        <div class="col-6">
                            <label class="form-label">Delay Between SMS (sec)</label>
                            <input type="number" class="form-control" name="delay_between_sms" id="delay_between_sms" value="<?= (int)$settings['delay_between_sms'] ?>" min="0" max="60" required>
                            <div class="form-text">Min delay between messages</div>
                        </div>
                    </div>
                    
                    <hr class="my-4">
                    
                    <div class="rate-preview mb-4 p-3 bg-light rounded">
                        <h6 class="mb-3"><i class="bi bi-clock-history"></i> Sending Time Preview</h6>
                        <div class="row g-2 small">
                            <div class="col-4">
                                <div class="p-2 bg-white rounded border">
                                    <div class="text-muted">10 messages</div>
                                    <div class="fw-bold text-primary" id="preview10">~<?= ceil(10 / max(1, (int)$settings['rate_per_minute']) * 60) ?> sec</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="p-2 bg-white rounded border">
                                    <div class="text-muted">100 messages</div>
                                    <div class="fw-bold text-primary" id="preview100">~<?= ceil(100 / max(1, (int)$settings['rate_per_minute'])) ?> min</div>
                                </div>
                            </div>
                            <div class="col-4">
                                <div class="p-2 bg-white rounded border">
                                    <div class="text-muted">500 messages</div>
                                    <div class="fw-bold text-primary" id="preview500">~<?= ceil(500 / max(1, (int)$settings['rate_per_hour'])) ?> hours</div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-check-lg"></i> Save Rate Limits
                    </button>
                </form>
            </div>
        </div>

        <div class="card mt-4">
            <div class="card-header">
                <h5 class="mb-0"><i class="bi bi-link-45deg"></i> Webhook URL</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-info mb-3">
                    <strong>This is your webhook URL:</strong> Copy this and paste it in SMS-Gate.app → Settings → Webhooks
                </div>
                
                <div class="input-group">
                    <input type="text" class="form-control" value="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/app/api/webhook.php' ?>" readonly>
                    <button class="btn btn-outline-secondary" type="button" onclick="navigator.clipboard.writeText('<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/app/api/webhook.php' ?>').then(() => alert('Copied!'))">
                        <i class="bi bi-clipboard"></i> Copy
                    </button>
                </div>
                
                <hr>
                
                <form method="POST">
                    <?= CSRF::getField() ?>
                    <input type="hidden" name="action" value="save_webhook_url">
                    
                    <div class="mb-3">
                        <label class="form-label">External Webhook URL (Optional)</label>
                        <input type="text" class="form-control" name="webhook_url" id="webhookUrl" value="<?= htmlspecialchars($smsService->getSetting('webhook_url', '')) ?>" placeholder="https://yourdomain.com/webhook">
                        <div class="form-text">Forward events to another webhook URL (optional)</div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> Save
                    </button>
                </form>
            </div>
        </div>

    </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Settings - ' . APP_NAME;
$currentPage = 'settings';

require_once TEMPLATES_PATH . '/base.php';
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const rateForm = document.getElementById('rateLimitForm');
    const ratePerMinute = document.getElementById('rate_per_minute');
    const perMinuteDesc = document.getElementById('perMinuteDesc');
    const preview10 = document.getElementById('preview10');
    const preview100 = document.getElementById('preview100');
    const preview500 = document.getElementById('preview500');
    
    function updatePreview() {
        const rpm = parseInt(ratePerMinute.value) || 1;
        
        const secPerMsg = (60 / rpm).toFixed(1);
        if (perMinuteDesc) perMinuteDesc.textContent = '1 message every ' + secPerMsg + 's';
        
        const time10 = Math.ceil(10 / rpm) * secPerMsg;
        if (preview10) preview10.textContent = '~' + Math.ceil(time10) + ' sec';
        
        const time100 = (100 / rpm) * secPerMsg / 60;
        if (preview100) preview100.textContent = '~' + Math.ceil(time100) + ' min';
        
        const hours500 = 500 / 60 / rpm;
        if (preview500) preview500.textContent = '~' + hours500.toFixed(1) + ' hours';
    }
    
    if (ratePerMinute) {
        ratePerMinute.addEventListener('input', updatePreview);
        updatePreview();
    }
});
</script>
