<?php
/**
 * Dashboard Page
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';

$auth = new Auth();
$auth->requireLogin();

$smsService = new SMSService();
$rateLimiter = new RateLimiter();
$queueProcessor = new QueueProcessor();

$stats = $smsService->getStats();
$queueStats = $queueProcessor->getQueueStats();
$rateStatus = $rateLimiter->getRateStatus();
$warnings = $rateLimiter->shouldWarnUser($rateStatus);
$devices = $smsService->getDevices();

$pendingCount = $stats['pending_queue'];

ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title">Dashboard</h1>
        <p class="page-meta mb-0">
            <i class="bi bi-calendar3 me-1"></i>
            <?= date('l, F j, Y') ?>
        </p>
    </div>
</div>

<?php foreach ($warnings as $warning): ?>
<div class="alert alert-<?= $warning['level'] === 'critical' ? 'danger' : 'warning' ?> mb-4">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <span><?= htmlspecialchars($warning['message']) ?></span>
    <button type="button" class="btn-close float-end" data-bs-dismiss="alert"></button>
</div>
<?php endforeach; ?>

<div class="row g-4 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon orange">
                    <i class="bi bi-hourglass-split"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format($stats['pending_queue']) ?></div>
                    <div class="stat-label">Pending Queue</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon green">
                    <i class="bi bi-send-check"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format($stats['sent_today']) ?></div>
                    <div class="stat-label">Sent Today</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon blue">
                    <i class="bi bi-check-all"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format($queueStats['sent']) ?></div>
                    <div class="stat-label">Total Sent</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon <?= $stats['configured'] ? 'green' : 'red' ?>">
                    <i class="bi bi-<?= $stats['configured'] ? 'cloud-check' : 'cloud-slash' ?>"></i>
                </div>
                <div>
                    <div class="stat-value"><?= $stats['configured'] ? 'Online' : 'Offline' ?></div>
                    <div class="stat-label">API Status</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card quick-send-card mb-4">
            <div class="card-header">
                <h5><i class="bi bi-lightning-charge"></i>Quick Send</h5>
                <a href="/send" class="btn btn-sm btn-outline-primary">
                    Full Form <i class="bi bi-arrow-right"></i>
                </a>
            </div>
            <div class="card-body">
                <form id="quickSendForm">
                    <?= CSRF::getField() ?>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Phone Number</label>
                            <input type="tel" class="form-control" id="phone" name="phone" placeholder="+1234567890" required>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">SIM Card</label>
                            <select class="form-select" id="sim" name="sim">
                                <option value="auto">Auto</option>
                                <option value="1">SIM 1</option>
                                <option value="2">SIM 2</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Device</label>
                            <select class="form-select" id="device_id" name="device_id">
                                <option value="">All Devices</option>
                                <?php if (!empty($devices)): ?>
                                <?php foreach ($devices as $device): ?>
                                <option value="<?= htmlspecialchars($device['device_id']) ?>">
                                    <?= htmlspecialchars($device['friendly_name'] ?? $device['name']) ?>
                                </option>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <option value="" disabled>No devices</option>
                                <?php endif; ?>
                            </select>
                            <?php if (empty($devices)): ?>
                            <div class="form-text"><a href="/devices">Add a device</a></div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Priority</label>
                            <select class="form-select" id="priority" name="priority">
                                <option value="1">Low</option>
                                <option value="5" selected>Normal</option>
                                <option value="9">High</option>
                            </select>
                        </div>
                        <div class="col-md-12 d-flex align-items-end">
                            <div class="form-check mb-3">
                                <input type="checkbox" class="form-check-input" id="queue" name="queue" value="1" checked>
                                <label class="form-check-label" for="queue">Add to queue</label>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Message</label>
                            <textarea class="form-control" id="message" name="message" rows="3" placeholder="Type your message..." required></textarea>
                            <div class="char-counter">
                                <span id="charCount">0</span> characters
                            </div>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary" id="sendBtn">
                                <i class="bi bi-send"></i> Send SMS
                            </button>
                        </div>
                    </div>
                </form>
                <div id="sendResult" class="mt-3 d-none"></div>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header">
                <h5><i class="bi bi-speedometer2"></i>Rate Limits</h5>
            </div>
            <div class="card-body">
                <?php foreach ($rateStatus as $period => $data): 
                    $pct = $data['percentage'];
                    $barClass = $pct >= 90 ? 'danger' : ($pct >= 75 ? 'warning' : '');
                ?>
                <div class="progress-wrapper">
                    <div class="progress-header">
                        <span class="progress-label"><?= htmlspecialchars($period) ?></span>
                        <span class="progress-value"><?= $data['current'] ?>/<?= $data['limit'] ?></span>
                    </div>
                    <div class="progress">
                        <div class="progress-bar <?= $barClass ?>" style="width: <?= min(100, $pct) ?>%"></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card mb-4">
            <div class="card-header">
                <h5><i class="bi bi-clock-history"></i>Quick Links</h5>
            </div>
            <div class="card-body p-2">
                <div class="list-group list-group-flush">
                    <a href="/queue" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-list-ul me-2"></i> Message Queue</span>
                        <?php if ($stats['pending_queue'] > 0): ?>
                        <span class="badge bg-warning"><?= $stats['pending_queue'] ?></span>
                        <?php endif; ?>
                    </a>
                    <a href="/history" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-clock-history me-2"></i> Message History</span>
                    </a>
                    <a href="/contacts" class="list-group-item list-group-item-action">
                        <i class="bi bi-people me-2"></i> Contacts
                    </a>
                    <a href="/templates" class="list-group-item list-group-item-action">
                        <i class="bi bi-file-text me-2"></i> Templates
                    </a>
                    <a href="/bulk" class="list-group-item list-group-item-action">
                        <i class="bi bi-upload me-2"></i> Bulk Send
                    </a>
                    <a href="/settings" class="list-group-item list-group-item-action">
                        <i class="bi bi-gear me-2"></i> Settings
                    </a>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-info-circle"></i>Quick Tips</h5>
            </div>
            <div class="card-body">
                <ul class="list-unstyled mb-0 small">
                    <li class="mb-2">
                        <i class="bi bi-check-circle text-success me-2"></i>
                        Use E.164 format for numbers
                    </li>
                    <li class="mb-2">
                        <i class="bi bi-check-circle text-success me-2"></i>
                        Standard SMS: 160 characters
                    </li>
                    <li class="mb-2">
                        <i class="bi bi-check-circle text-success me-2"></i>
                        Long messages split automatically
                    </li>
                    <li>
                        <i class="bi bi-check-circle text-success me-2"></i>
                        Enable queue for bulk sending
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Dashboard - ' . APP_NAME;
$currentPage = 'dashboard';

require_once TEMPLATES_PATH . '/base.php';
?>

<script>
document.getElementById('message')?.addEventListener('input', function() {
    document.getElementById('charCount').textContent = this.value.length;
});

document.getElementById('quickSendForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const form = e.target;
    const submitBtn = document.getElementById('sendBtn');
    const resultDiv = document.getElementById('sendResult');
    
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Sending...';
    resultDiv.classList.add('d-none');
    
    try {
        const formData = new FormData(form);
        const response = await fetch('/app/api/send.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        resultDiv.classList.remove('d-none');
        
        if (result.success) {
            resultDiv.innerHTML = `<div class="alert alert-success mb-0">
                <i class="bi bi-check-circle-fill"></i>
                <span>Message sent successfully! ${result.external_id ? 'ID: ' + result.external_id : ''}</span>
            </div>`;
            form.reset();
            document.getElementById('charCount').textContent = '0';
        } else {
            resultDiv.innerHTML = `<div class="alert alert-danger mb-0">
                <i class="bi bi-exclamation-circle-fill"></i>
                <span>${result.error || 'Failed to send message'}</span>
            </div>`;
        }
    } catch (error) {
        resultDiv.classList.remove('d-none');
        resultDiv.innerHTML = `<div class="alert alert-danger mb-0">
            <i class="bi bi-exclamation-circle-fill"></i>
            <span>Network error. Please try again.</span>
        </div>`;
    }
    
    submitBtn.disabled = false;
    submitBtn.innerHTML = '<i class="bi bi-send"></i> Send SMS';
});
</script>
