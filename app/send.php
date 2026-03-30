<?php
/**
 * Standalone Send SMS Page
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';

$auth = new Auth();
$auth->requireLogin();

$smsService = new SMSService();
$rateLimiter = new RateLimiter();
$templateService = new TemplateService();
$contactService = new ContactService();
$pendingCount = $smsService->getPendingQueueCount();
$rateStatus = $rateLimiter->getRateStatus();

$templates = $templateService->getAll();
$contacts = $contactService->getAllContacts(['limit' => 100]);
$groups = $contactService->getAllGroups();

$bulkSender = new BulkSender();
$batchStats = $bulkSender->getBatchStats();
$recentBatches = $bulkSender->getAllBatches(5);

$devices = $smsService->getDevices();

$errors = [];
$selectedTemplate = null;
$selectedContact = null;
$currentTab = $_GET['tab'] ?? 'single';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validateToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid request token.';
    } else {
        $action = $_POST['action'] ?? 'single';
        
        if ($action === 'bulk') {
            $phoneNumbers = trim($_POST['phone_numbers'] ?? '');
            $message = trim($_POST['message'] ?? '');
            $sim = $_POST['sim'] ?? 'auto';
            $priority = (int)($_POST['priority'] ?? 5);
            $queue = isset($_POST['queue']);
            
            if (empty($phoneNumbers)) {
                $errors[] = 'Phone numbers are required.';
            }
            if (empty($message)) {
                $errors[] = 'Message is required.';
            }
            
            if (empty($errors)) {
                $numbers = preg_split('/[\s,;\n]+/', $phoneNumbers);
                $numbers = array_filter(array_map('trim', $numbers));
                $numbers = array_filter($numbers, function($n) {
                    return preg_match('/^\+?[0-9]{6,15}$/', $n);
                });
                
                if (empty($numbers)) {
                    $errors[] = 'No valid phone numbers found. Use E.164 format (e.g., +1234567890).';
                } else {
                    $queued = 0;
                    $skipped = 0;
                    
                    foreach ($numbers as $phone) {
                        if ($queue) {
                            $result = $smsService->queueMessage($phone, $message, $sim, $priority);
                            if ($result['success']) {
                                $queued++;
                            } else {
                                $skipped++;
                            }
                        } else {
                            $rateCheck = $rateLimiter->checkLimit('send_sms');
                            if ($rateCheck['allowed']) {
                                $result = $smsService->sendSingleSMS($phone, $message, $sim, $priority);
                                if ($result['success']) {
                                    $queued++;
                                } else {
                                    $skipped++;
                                }
                            } else {
                                $skipped++;
                            }
                        }
                    }
                    
                    $_SESSION['flash_message'] = "Queued: {$queued} messages." . ($skipped > 0 ? " Skipped: {$skipped}." : '');
                    $_SESSION['flash_type'] = $skipped > 0 ? 'warning' : 'success';
                    header('Location: /queue');
                    exit;
                }
            }
        } elseif ($action === 'csv_bulk') {
            $contactsJson = $_POST['contacts'] ?? '[]';
            $contacts = json_decode($contactsJson, true);
            $filename = trim($_POST['filename'] ?? 'manual_' . date('Ymd_His'));
            
            if (empty($contacts)) {
                $errors[] = 'No valid contacts found in the uploaded file.';
            } else {
                $batchId = $bulkSender->createBatch($filename, count($contacts));
                
                $queued = 0;
                $skipped = 0;
                
                foreach ($contacts as $contact) {
                    $result = $smsService->queueMessage(
                        $contact['phone'],
                        $contact['message'],
                        $contact['sim'] ?? 'auto',
                        (int)($contact['priority'] ?? 5)
                    );
                    
                    if ($result['success']) {
                        $queued++;
                        $bulkSender->incrementBatchCount($batchId, 'sent');
                    } else {
                        $skipped++;
                        $bulkSender->incrementBatchCount($batchId, 'failed');
                    }
                }
                
                $bulkSender->completeBatch($batchId);
                
                $_SESSION['flash_message'] = "Queued: {$queued} messages from CSV. Skipped: {$skipped}.";
                $_SESSION['flash_type'] = $skipped > 0 ? 'warning' : 'success';
                header('Location: /queue');
                exit;
            }
        } else {
            $phone = trim($_POST['phone'] ?? '');
            $message = trim($_POST['message'] ?? '');
            $sim = $_POST['sim'] ?? 'auto';
            $priority = (int)($_POST['priority'] ?? 5);
            $deviceId = $_POST['device_id'] ?? '';
            $queue = isset($_POST['queue']);
            
            if (empty($phone)) $errors[] = 'Phone number is required.';
            if (empty($message)) $errors[] = 'Message is required.';
            
            if (empty($errors)) {
                if ($queue) {
                    $scheduledAt = null;
                    if (!empty($_POST['schedule_toggle'])) {
                        $scheduleDate = $_POST['schedule_date'] ?? '';
                        $scheduleTime = $_POST['schedule_time'] ?? '';
                        if ($scheduleDate && $scheduleTime) {
                            $scheduledAt = $scheduleDate . ' ' . $scheduleTime . ':00';
                            $scheduledTimestamp = strtotime($scheduledAt);
                            if ($scheduledTimestamp <= time()) {
                                $errors[] = 'Scheduled time must be in the future.';
                            }
                        }
                    }
                    
                    if (empty($errors)) {
                        $result = $smsService->queueMessage($phone, $message, $sim, $priority, $scheduledAt, $deviceId ?: null);
                        if ($result['success']) {
                            if ($scheduledAt) {
                                $_SESSION['flash_message'] = 'Message scheduled for ' . date('M j, Y g:i A', strtotime($scheduledAt));
                            } else {
                                $_SESSION['flash_message'] = 'Message added to queue successfully.';
                            }
                            $_SESSION['flash_type'] = 'success';
                            header('Location: /queue');
                            exit;
                        } else {
                            $errors[] = $result['error'];
                        }
                    }
                } else {
                    $rateCheck = $rateLimiter->checkLimit('send_sms');
                    if (!$rateCheck['allowed']) {
                        $errors[] = "Rate limit exceeded: {$rateCheck['reason']}. Please try again later.";
                    } else {
                        $result = $smsService->sendSingleSMS($phone, $message, $sim, $priority, $deviceId ?: null);
                        if ($result['success']) {
                            $_SESSION['flash_message'] = "Message sent successfully! ID: {$result['external_id']}";
                            $_SESSION['flash_type'] = 'success';
                            header('Location: /dashboard');
                            exit;
                        } else {
                            $errors[] = $result['error'];
                        }
                    }
                }
            }
        }
    }
}

$prefillPhone = $_GET['phone'] ?? $_POST['phone'] ?? '';
$prefillMessage = $_POST['message'] ?? '';

if (isset($_GET['template_id'])) {
    $selectedTemplate = $templateService->getById((int)$_GET['template_id']);
    if ($selectedTemplate) {
        $prefillMessage = $selectedTemplate['content'];
    }
}

if (isset($_GET['contact_id'])) {
    $selectedContact = $contactService->getContactById((int)$_GET['contact_id']);
    if ($selectedContact) {
        $prefillPhone = $selectedContact['phone'];
    }
}

ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title">Send SMS</h1>
        <p class="page-meta mb-0">Compose and send a new message</p>
    </div>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-danger mb-4">
    <i class="bi bi-exclamation-circle-fill"></i>
    <ul class="mb-0 ps-3">
        <?php foreach ($errors as $error): ?>
        <li><?= htmlspecialchars($error) ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $currentTab === 'single' ? 'active' : '' ?>" href="?tab=single">
            <i class="bi bi-person"></i> Single
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $currentTab === 'bulk' ? 'active' : '' ?>" href="?tab=bulk">
            <i class="bi bi-people"></i> Bulk
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $currentTab === 'templates' ? 'active' : '' ?>" href="?tab=templates">
            <i class="bi bi-file-earmark-text"></i> Templates
        </a>
    </li>
</ul>

<?php if ($currentTab === 'single'): ?>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-pencil-square"></i>New Message</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= CSRF::getField() ?>
                    
                    <div class="row g-3">
                        <?php if (!empty($templates)): ?>
                        <div class="col-12">
                            <label class="form-label">Use Template</label>
                            <div class="input-group">
                                <select class="form-select" id="templateSelect">
                                    <option value="">-- Select a template --</option>
                                    <?php foreach ($templates as $tmpl): ?>
                                    <option value="<?= $tmpl['id'] ?>" data-content="<?= htmlspecialchars($tmpl['content']) ?>" data-variables="<?= htmlspecialchars($tmpl['variables'] ?? '') ?>">
                                        <?= htmlspecialchars($tmpl['name']) ?>
                                        <?php if (!empty($tmpl['category'])): ?>
                                        (<?= htmlspecialchars($tmpl['category']) ?>)
                                        <?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <a href="/templates" class="btn btn-outline-secondary" title="Manage Templates">
                                    <i class="bi bi-gear"></i>
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="col-md-5">
                            <label class="form-label">Phone Number *</label>
                            <input type="tel" class="form-control" name="phone" id="phoneInput" value="<?= htmlspecialchars($prefillPhone) ?>" placeholder="+1234567890" required>
                        </div>
                        
                        <?php if (!empty($contacts)): ?>
                        <div class="col-md-4">
                            <label class="form-label">Select Contact</label>
                            <select class="form-select" id="contactSelect">
                                <option value="">-- Select contact --</option>
                                <?php foreach ($contacts as $contact): ?>
                                <option value="<?= htmlspecialchars($contact['phone']) ?>" data-id="<?= $contact['id'] ?>">
                                    <?= htmlspecialchars($contact['name'] ?: $contact['phone']) ?>
                                    <?php if (!empty($contact['group_name'])): ?>
                                    - <?= htmlspecialchars($contact['group_name']) ?>
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">&nbsp;</label>
                            <a href="/contacts?action=new" class="btn btn-outline-primary d-block">
                                <i class="bi bi-person-plus"></i> New Contact
                            </a>
                        </div>
                        <?php else: ?>
                        <div class="col-md-7">
                            <label class="form-label">&nbsp;</label>
                            <a href="/contacts?action=new" class="btn btn-outline-primary d-block">
                                <i class="bi bi-person-plus"></i> Add Contact
                            </a>
                        </div>
                        <?php endif; ?>
                        
                        <div class="col-md-3">
                            <label class="form-label">Priority</label>
                            <select class="form-select" name="priority">
                                <option value="1" <?= (($_POST['priority'] ?? 5) == 1) ? 'selected' : '' ?>>Low</option>
                                <option value="5" <?= (($_POST['priority'] ?? 5) == 5) ? 'selected' : '' ?>>Normal</option>
                                <option value="9" <?= (($_POST['priority'] ?? 5) == 9) ? 'selected' : '' ?>>High</option>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Device</label>
                            <select class="form-select" name="device_id" id="deviceSelect">
                                <option value="">All Devices (Auto)</option>
                                <?php if (!empty($devices)): ?>
                                <?php foreach ($devices as $device): ?>
                                <option value="<?= htmlspecialchars($device['device_id']) ?>" <?= ($_POST['device_id'] ?? '') === $device['device_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($device['friendly_name'] ?? $device['name']) ?>
                                    (<?= $device['status'] === 'online' ? 'Online' : 'Offline' ?>)
                                </option>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <option value="" disabled>No devices - Add one in Devices page</option>
                                <?php endif; ?>
                            </select>
                            <?php if (empty($devices)): ?>
                            <div class="form-text"><a href="/devices">Add a device</a></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-12">
                            <label class="form-label">SIM Selection</label>
                            <div class="sim-selector">
                                <div class="sim-option">
                                    <input type="radio" name="sim" id="sim-auto" value="auto" checked>
                                    <label for="sim-auto">
                                        <span class="sim-name">Auto</span>
                                        <span class="sim-desc">System picks</span>
                                    </label>
                                </div>
                                <div class="sim-option">
                                    <input type="radio" name="sim" id="sim-1" value="1">
                                    <label for="sim-1">
                                        <span class="sim-name">SIM 1</span>
                                        <span class="sim-desc">First slot</span>
                                    </label>
                                </div>
                                <div class="sim-option">
                                    <input type="radio" name="sim" id="sim-2" value="2">
                                    <label for="sim-2">
                                        <span class="sim-name">SIM 2</span>
                                        <span class="sim-desc">Second slot</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12" id="messageSection">
                            <label class="form-label">Message *</label>
                            <textarea class="form-control" name="message" id="messageInput" rows="5" placeholder="Type your message here..." required><?= htmlspecialchars($prefillMessage) ?></textarea>
                            <div class="char-counter">
                                <span id="charCount">0</span> characters
                                <span id="segmentCount">~1 segment</span>
                            </div>
                            <?php if ($selectedTemplate && !empty($selectedTemplate['variables'])): ?>
                            <div class="template-variables mt-2 p-3 bg-light rounded">
                                <small class="text-muted">
                                    <strong>Template Variables:</strong> 
                                    <?php 
                                    $vars = json_decode($selectedTemplate['variables'] ?? '[]', true);
                                    foreach ($vars as $var): 
                                    ?>
                                    <code class="me-2">{{<?= htmlspecialchars($var) ?>}}</code>
                                    <?php endforeach; ?>
                                </small>
                            </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="col-12">
                            <div class="form-check form-switch mb-2">
                                <input type="checkbox" class="form-check-input" id="schedule-toggle" name="schedule_toggle" value="1" onchange="toggleSchedule()">
                                <label class="form-check-label" for="schedule-toggle">
                                    <strong>Schedule for later</strong>
                                </label>
                            </div>
                            <div id="schedule-options" class="mt-3 p-3 bg-light rounded" style="display: none;">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="form-label">Date</label>
                                        <input type="date" class="form-control" name="schedule_date" id="schedule_date" min="<?= date('Y-m-d') ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label">Time</label>
                                        <input type="time" class="form-control" name="schedule_time" id="schedule_time" value="09:00">
                                    </div>
                                </div>
                                <div class="mt-2 small text-muted" id="schedule-preview"></div>
                            </div>
                            
                            <div class="form-check mt-3">
                                <input type="checkbox" class="form-check-input" id="queue" name="queue" value="1" checked>
                                <label class="form-check-label" for="queue">Add to queue (messages will be processed based on rate limits)</label>
                            </div>
                        </div>
                        
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary" id="sendBtn">
                                <i class="bi bi-send"></i> Queue SMS
                            </button>
                            <a href="/dashboard" class="btn btn-secondary ms-2">
                                Cancel
                            </a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
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
        
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-info-circle"></i>Tips</h5>
            </div>
            <div class="card-body">
                <ul class="list-unstyled mb-0 small">
                    <li class="mb-3">
                        <i class="bi bi-check2 text-success me-2"></i>
                        Use E.164 format for international numbers
                    </li>
                    <li class="mb-3">
                        <i class="bi bi-check2 text-success me-2"></i>
                        Standard SMS supports up to 160 characters
                    </li>
                    <li class="mb-3">
                        <i class="bi bi-check2 text-success me-2"></i>
                        Long messages are automatically split
                    </li>
                    <li>
                        <i class="bi bi-check2 text-success me-2"></i>
                        High priority messages skip the queue
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<?php elseif ($currentTab === 'bulk'): ?>

<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon blue">
                    <i class="bi bi-collection"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format($batchStats['total_batches'] ?? 0) ?></div>
                    <div class="stat-label">Total Batches</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon green">
                    <i class="bi bi-send-check"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format($batchStats['total_sent'] ?? 0) ?></div>
                    <div class="stat-label">Total Sent</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon red">
                    <i class="bi bi-x-circle"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format($batchStats['total_failed'] ?? 0) ?></div>
                    <div class="stat-label">Failed</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <a class="nav-link <?= !isset($_GET['subtab']) || $_GET['subtab'] === 'manual' ? 'active' : '' ?>" href="?tab=bulk&subtab=manual">
                    <i class="bi bi-keyboard"></i> Manual Entry
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= (isset($_GET['subtab']) && $_GET['subtab'] === 'csv') ? 'active' : '' ?>" href="?tab=bulk&subtab=csv">
                    <i class="bi bi-file-earmark-spreadsheet"></i> CSV Upload
                </a>
            </li>
        </ul>

        <?php if (!isset($_GET['subtab']) || $_GET['subtab'] === 'manual'): ?>
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-people"></i>Manual Bulk SMS</h5>
            </div>
            <div class="card-body">
                <form method="POST">
                    <?= CSRF::getField() ?>
                    <input type="hidden" name="action" value="bulk">
                    
                    <div class="row g-3">
                        <?php if (!empty($templates)): ?>
                        <div class="col-12">
                            <label class="form-label">Use Template</label>
                            <div class="input-group">
                                <select class="form-select" id="bulkTemplateSelect">
                                    <option value="">-- Select a template --</option>
                                    <?php foreach ($templates as $tmpl): ?>
                                    <option value="<?= $tmpl['id'] ?>" data-content="<?= htmlspecialchars($tmpl['content']) ?>" data-variables="<?= htmlspecialchars($tmpl['variables'] ?? '') ?>">
                                        <?= htmlspecialchars($tmpl['name']) ?>
                                        <?php if (!empty($tmpl['category'])): ?>
                                        (<?= htmlspecialchars($tmpl['category']) ?>)
                                        <?php endif; ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <a href="/templates" class="btn btn-outline-secondary" title="Manage Templates">
                                    <i class="bi bi-gear"></i>
                                </a>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="col-12">
                            <label class="form-label">Phone Numbers *</label>
                            <textarea class="form-control" name="phone_numbers" id="phoneNumbersInput" rows="6" placeholder="+1234567890, +0987654321, +1123456789&#10;or one per line:&#10;+1234567890&#10;+0987654321&#10;+1123456789" required></textarea>
                            <div class="form-text">
                                <i class="bi bi-info-circle"></i> Separate numbers with commas, spaces, semicolons, or new lines. Use E.164 format (e.g., +1234567890).
                            </div>
                            <div class="mt-2">
                                <span class="badge bg-primary" id="phoneCount">0</span> numbers detected
                            </div>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Priority</label>
                            <select class="form-select" name="priority">
                                <option value="1">Low</option>
                                <option value="5" selected>Normal</option>
                                <option value="9">High</option>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Device</label>
                            <select class="form-select" name="device_id">
                                <option value="">All Devices (Auto)</option>
                                <?php if (!empty($devices)): ?>
                                <?php foreach ($devices as $device): ?>
                                <option value="<?= htmlspecialchars($device['device_id']) ?>">
                                    <?= htmlspecialchars($device['friendly_name'] ?? $device['name']) ?>
                                    (<?= $device['status'] === 'online' ? 'Online' : 'Offline' ?>)
                                </option>
                                <?php endforeach; ?>
                                <?php else: ?>
                                <option value="" disabled>No devices - Add one in Devices page</option>
                                <?php endif; ?>
                            </select>
                            <?php if (empty($devices)): ?>
                            <div class="form-text"><a href="/devices">Add a device</a></div>
                            <?php endif; ?>
                        </div>

                        <div class="col-12">
                            <label class="form-label">SIM Selection</label>
                            <div class="sim-selector">
                                <div class="sim-option">
                                    <input type="radio" name="sim" id="bulk-sim-auto" value="auto" checked>
                                    <label for="bulk-sim-auto">
                                        <span class="sim-name">Auto</span>
                                        <span class="sim-desc">System picks</span>
                                    </label>
                                </div>
                                <div class="sim-option">
                                    <input type="radio" name="sim" id="bulk-sim-1" value="1">
                                    <label for="bulk-sim-1">
                                        <span class="sim-name">SIM 1</span>
                                        <span class="sim-desc">First slot</span>
                                    </label>
                                </div>
                                <div class="sim-option">
                                    <input type="radio" name="sim" id="bulk-sim-2" value="2">
                                    <label for="bulk-sim-2">
                                        <span class="sim-name">SIM 2</span>
                                        <span class="sim-desc">Second slot</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-12" id="bulkMessageSection">
                            <label class="form-label">Message *</label>
                            <textarea class="form-control" name="message" id="bulkMessageInput" rows="5" placeholder="Type your message here..." required></textarea>
                            <div class="char-counter">
                                <span id="bulkCharCount">0</span> characters
                                <span id="bulkSegmentCount">~1 segment</span>
                            </div>
                        </div>
                        
                        <div class="col-12">
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i>
                                <strong>All messages will be queued</strong> and processed based on your rate limits. This ensures reliable delivery without hitting API limits.
                            </div>
                            <input type="hidden" name="queue" value="1">
                        </div>
                        
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary" id="bulkSendBtn">
                                <i class="bi bi-send"></i> Queue All Messages
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php else: ?>
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5><i class="bi bi-file-earmark-spreadsheet"></i>CSV Upload</h5>
                <a href="/app/api/bulk.php?action=sample" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-download"></i> Download Template
                </a>
            </div>
            <div class="card-body">
                <form id="bulkUploadForm" enctype="multipart/form-data">
                    <div class="mb-4">
                        <label class="form-label">CSV File (Max 5MB)</label>
                        <input type="file" class="form-control" id="csvFile" accept=".csv" required>
                        <div class="form-text">
                            Required columns: <code>phone</code>, <code>message</code><br>
                            Optional columns: <code>sim</code>, <code>priority</code>, <code>name</code>
                        </div>
                    </div>
                    
                    <div class="alert alert-info mb-4">
                        <i class="bi bi-info-circle"></i>
                        <strong>Variable Support:</strong> Use <code>{{column_name}}</code> in your message to insert data from other columns.
                        <br>Example: <code>Hello {{name}}, your appointment is confirmed</code>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-upload"></i> Upload & Preview
                    </button>
                </form>
            </div>
        </div>

        <div id="previewSection" class="card mt-4" style="display: none;">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5><i class="bi bi-eye"></i> Preview</h5>
                <span id="previewCount" class="badge badge-info"></span>
            </div>
            <div class="card-body">
                <div id="previewErrors" class="alert alert-warning mb-3" style="display: none;"></div>
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Phone</th>
                                <th>Message</th>
                                <th>SIM</th>
                                <th>Priority</th>
                            </tr>
                        </thead>
                        <tbody id="previewTableBody"></tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer">
                <form id="bulkCsvSendForm" method="POST">
                    <?= CSRF::getField() ?>
                    <input type="hidden" name="action" value="csv_bulk">
                    <input type="hidden" name="contacts" id="contactsJson">
                    <input type="hidden" name="filename" id="filenameValue">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send"></i> Queue All Messages
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="cancelCsvBulk()">Cancel</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <div class="col-lg-4">
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
        
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-clock-history"></i>Recent Batches</h5>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentBatches)): ?>
                <div class="p-3 text-center text-muted">
                    <small>No batches yet</small>
                </div>
                <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($recentBatches as $batch): ?>
                    <div class="list-group-item px-3 py-2">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <strong><?= htmlspecialchars($batch['filename']) ?></strong>
                                <br>
                                <small class="text-muted"><?= number_format($batch['total_recipients']) ?> recipients</small>
                            </div>
                            <span class="badge bg-<?= match($batch['status']) { 'completed' => 'success', 'processing' => 'info', 'pending' => 'warning', 'failed' => 'danger', default => 'secondary' } ?>">
                                <?= ucfirst($batch['status']) ?>
                            </span>
                        </div>
                        <div class="mt-1 small">
                            <span class="text-success"><?= number_format($batch['sent_count']) ?> sent</span> · 
                            <span class="text-danger"><?= number_format($batch['failed_count']) ?> failed</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php elseif ($currentTab === 'templates'): ?>
<div class="row g-4">
    <div class="col-lg-8">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h5><i class="bi bi-file-earmark-text"></i> Message Templates</h5>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#templateModal">
                <i class="bi bi-plus-lg"></i> New Template
            </button>
        </div>
        
        <div class="card">
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Variables</th>
                            <th>Usage</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($templates)): ?>
                        <tr>
                            <td colspan="5" class="text-center py-5">
                                <div class="empty-state">
                                    <i class="bi bi-file-earmark-text"></i>
                                    <h5>No templates yet</h5>
                                    <p>Create your first template to get started</p>
                                </div>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($templates as $tmpl): ?>
                        <?php 
                        $vars = json_decode($tmpl['variables'] ?? '[]', true);
                        $variables = is_array($vars) ? $vars : [];
                        ?>
                        <tr>
                            <td>
                                <div class="fw-medium"><?= htmlspecialchars($tmpl['name']) ?></div>
                                <?php if ($tmpl['subject']): ?>
                                <small class="text-muted"><?= htmlspecialchars($tmpl['subject']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-secondary"><?= htmlspecialchars($tmpl['category'] ?: 'general') ?></span>
                            </td>
                            <td>
                                <?php if (empty($variables)): ?>
                                <span class="text-muted">-</span>
                                <?php else: ?>
                                <?php foreach (array_slice($variables, 0, 2) as $v): ?>
                                <code class="me-1">{{<?= htmlspecialchars($v) ?>}}</code>
                                <?php endforeach; ?>
                                <?php if (count($variables) > 2): ?>
                                <span class="text-muted">+<?= count($variables) - 2 ?></span>
                                <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-info"><?= number_format($tmpl['usage_count'] ?? 0) ?></span>
                            </td>
                            <td>
                                <div class="action-group">
                                    <button class="action-btn" onclick="useTemplate(<?= $tmpl['id'] ?>)" title="Use">
                                        <i class="bi bi-send"></i>
                                    </button>
                                    <button class="action-btn" onclick="editTemplate(<?= htmlspecialchars(json_encode($tmpl)) ?>)" title="Edit">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="action-btn danger" onclick="deleteTemplate(<?= $tmpl['id'] ?>, '<?= htmlspecialchars($tmpl['name'], ENT_QUOTES) ?>')" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header">
                <h5><i class="bi bi-lightbulb"></i> Tips</h5>
            </div>
            <div class="card-body">
                <ul class="list-unstyled mb-0 small">
                    <li class="mb-2">
                        <i class="bi bi-check2 text-success me-2"></i>
                        Use <code>{{variable}}</code> for dynamic content
                    </li>
                    <li class="mb-2">
                        <i class="bi bi-check2 text-success me-2"></i>
                        Variables are replaced with CSV data
                    </li>
                    <li class="mb-2">
                        <i class="bi bi-check2 text-success me-2"></i>
                        Templates work in both single and bulk SMS
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="templateModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="templateModalTitle">New Template</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="/app/api/template.php?action=create" id="templateForm">
                <div class="modal-body">
                    <?= CSRF::getField() ?>
                    <input type="hidden" name="id" id="templateId">
                    
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Template Name *</label>
                            <input type="text" class="form-control" name="name" id="templateName" required placeholder="e.g., Appointment Reminder">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Category</label>
                            <select class="form-select" name="category" id="templateCategory">
                                <option value="general">General</option>
                                <option value="notification">Notification</option>
                                <option value="alert">Alert</option>
                                <option value="reminder">Reminder</option>
                                <option value="marketing">Marketing</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Subject (Optional)</label>
                            <input type="text" class="form-control" name="subject" id="templateSubject" placeholder="Brief subject line">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Message Content *</label>
                            <textarea class="form-control" name="content" id="templateContent" rows="5" required placeholder="Hello {{name}}, your appointment is on {{date}} at {{time}}..."></textarea>
                            <div class="form-text">
                                Use <code>{{variable}}</code> for dynamic content. Example: <code>{{name}}</code>, <code>{{date}}</code>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-3" id="variablePreview">
                        <label class="form-label">Detected Variables</label>
                        <div id="variableTags"><span class="text-muted small">Enter content to detect variables</span></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> Save Template
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<form method="POST" id="deleteTemplateForm" style="display: none;">
    <?= CSRF::getField() ?>
    <input type="hidden" name="id" id="deleteTemplateId">
</form>

<script>
function editTemplate(tmpl) {
    document.getElementById('templateModalTitle').textContent = 'Edit Template';
    document.getElementById('templateId').value = tmpl.id;
    document.getElementById('templateName').value = tmpl.name || '';
    document.getElementById('templateCategory').value = tmpl.category || 'general';
    document.getElementById('templateSubject').value = tmpl.subject || '';
    document.getElementById('templateContent').value = tmpl.content || '';
    document.getElementById('templateForm').action = '/app/api/template.php?action=update';
    updateTemplateVariables();
    new bootstrap.Modal(document.getElementById('templateModal')).show();
}

function useTemplate(id) {
    window.location.href = '/send?template=' + id;
}

function deleteTemplate(id, name) {
    if (confirm('Delete template "' + name + '"?')) {
        document.getElementById('deleteTemplateId').value = id;
        document.getElementById('deleteTemplateForm').action = '/app/api/template.php?action=delete';
        document.getElementById('deleteTemplateForm').submit();
    }
}

function updateTemplateVariables() {
    const content = document.getElementById('templateContent').value;
    const matches = content.match(/\{\{(\w+)\}\}/g) || [];
    const vars = [...new Set(matches.map(m => m.replace(/\{\{|\}\}/g, '')))];
    
    const container = document.getElementById('variableTags');
    if (vars.length === 0) {
        container.innerHTML = '<span class="text-muted small">No variables detected</span>';
    } else {
        container.innerHTML = vars.map(v => '<span class="badge badge-info me-1">{{' + v + '}}</span>').join('');
    }
}

document.getElementById('templateContent')?.addEventListener('input', updateTemplateVariables);

document.getElementById('templateModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('templateModalTitle').textContent = 'New Template';
    document.getElementById('templateForm').action = '/app/api/template.php?action=create';
    document.getElementById('templateForm').reset();
    document.getElementById('variableTags').innerHTML = '<span class="text-muted small">Enter content to detect variables</span>';
});
</script>

<?php endif; ?>
<?php
$content = ob_get_clean();
$pageTitle = 'Send SMS - ' . APP_NAME;
$currentPage = 'send';

require_once TEMPLATES_PATH . '/base.php';
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const messageInput = document.getElementById('messageInput') || document.querySelector('textarea[name="message"]');
    const charCount = document.getElementById('charCount');
    const segmentCount = document.getElementById('segmentCount');
    const templateSelect = document.getElementById('templateSelect');
    const contactSelect = document.getElementById('contactSelect');
    const phoneInput = document.getElementById('phoneInput');
    const messageSection = document.getElementById('messageSection');
    
    function updateCount() {
        if (!messageInput) return;
        const len = messageInput.value.length;
        if (charCount) charCount.textContent = len;
        const segLen = len <= 160 ? 160 : 153;
        const segments = Math.ceil(len / segLen);
        if (segmentCount) segmentCount.textContent = '~' + segments + ' segment' + (segments > 1 ? 's' : '');
    }
    
    function showVariables(variables) {
        if (!messageSection || !variables) return;
        const vars = JSON.parse(variables);
        if (vars.length === 0) {
            const existing = messageSection.querySelector('.template-variables');
            if (existing) existing.remove();
            return;
        }
        
        let existing = messageSection.querySelector('.template-variables');
        if (!existing) {
            existing = document.createElement('div');
            existing.className = 'template-variables mt-2 p-3 bg-light rounded';
            messageSection.appendChild(existing);
        }
        
        existing.innerHTML = '<small class="text-muted"><strong>Template Variables:</strong> ' + 
            vars.map(v => '<code class="me-2">{{' + v + '}}</code>').join('') + 
            '</small>';
    }
    
    if (templateSelect) {
        templateSelect.addEventListener('change', function() {
            const option = this.options[this.selectedIndex];
            if (option.value) {
                const content = option.dataset.content;
                const variables = option.dataset.variables;
                if (messageInput) messageInput.value = content;
                showVariables(variables);
                updateCount();
            }
        });
    }
    
    if (contactSelect) {
        contactSelect.addEventListener('change', function() {
            const option = this.options[this.selectedIndex];
            if (option.value && phoneInput) {
                phoneInput.value = option.value;
            }
        });
    }
    
    if (messageInput) {
        messageInput.addEventListener('input', updateCount);
        updateCount();
    }
    
    const prefillMessage = <?= json_encode($prefillMessage) ?>;
    if (prefillMessage && messageInput && messageInput.value !== prefillMessage) {
        messageInput.value = prefillMessage;
        updateCount();
    }
    
    const selectedTemplate = <?= json_encode($selectedTemplate) ?>;
    if (selectedTemplate && selectedTemplate.variables) {
        showVariables(selectedTemplate.variables);
    }
    
    const bulkTemplateSelect = document.getElementById('bulkTemplateSelect');
    const bulkMessageInput = document.getElementById('bulkMessageInput');
    const bulkCharCount = document.getElementById('bulkCharCount');
    const bulkSegmentCount = document.getElementById('bulkSegmentCount');
    const bulkMessageSection = document.getElementById('bulkMessageSection');
    const phoneNumbersInput = document.getElementById('phoneNumbersInput');
    const phoneCount = document.getElementById('phoneCount');
    
    function updateBulkCount() {
        if (!bulkMessageInput) return;
        const len = bulkMessageInput.value.length;
        if (bulkCharCount) bulkCharCount.textContent = len;
        const segLen = len <= 160 ? 160 : 153;
        const segments = Math.ceil(len / segLen);
        if (bulkSegmentCount) bulkSegmentCount.textContent = '~' + segments + ' segment' + (segments > 1 ? 's' : '');
    }
    
    function countPhoneNumbers() {
        if (!phoneNumbersInput) return;
        const text = phoneNumbersInput.value.trim();
        if (!text) {
            if (phoneCount) phoneCount.textContent = '0';
            return;
        }
        const numbers = text.split(/[\s,;\n]+/).filter(n => n.length > 0);
        const valid = numbers.filter(n => /^\+?[0-9]{6,15}$/.test(n));
        if (phoneCount) {
            phoneCount.textContent = valid.length;
            phoneCount.className = valid.length > 0 ? 'badge bg-success' : 'badge bg-primary';
        }
    }
    
    if (bulkTemplateSelect) {
        bulkTemplateSelect.addEventListener('change', function() {
            const option = this.options[this.selectedIndex];
            if (option.value && bulkMessageInput) {
                bulkMessageInput.value = option.dataset.content;
                updateBulkCount();
            }
        });
    }
    
    if (bulkMessageInput) {
        bulkMessageInput.addEventListener('input', updateBulkCount);
        updateBulkCount();
    }
    
    if (phoneNumbersInput) {
        phoneNumbersInput.addEventListener('input', countPhoneNumbers);
        phoneNumbersInput.addEventListener('paste', function() {
            setTimeout(countPhoneNumbers, 10);
        });
        countPhoneNumbers();
    }
});

function toggleSchedule() {
    const toggle = document.getElementById('schedule-toggle');
    const options = document.getElementById('schedule-options');
    const preview = document.getElementById('schedule-preview');
    const queueCheck = document.getElementById('queue');
    const sendBtn = document.getElementById('sendBtn');
    
    if (toggle.checked) {
        options.style.display = 'block';
        queueCheck.checked = true;
        sendBtn.innerHTML = '<i class="bi bi-clock"></i> Schedule SMS';
        
        const today = new Date();
        document.getElementById('schedule_date').value = today.toISOString().split('T')[0];
        updateSchedulePreview();
    } else {
        options.style.display = 'none';
        sendBtn.innerHTML = '<i class="bi bi-send"></i> Queue SMS';
    }
}

function updateSchedulePreview() {
    const dateInput = document.getElementById('schedule_date');
    const timeInput = document.getElementById('schedule_time');
    const preview = document.getElementById('schedule-preview');
    
    if (dateInput.value && timeInput.value) {
        const date = new Date(dateInput.value + 'T' + timeInput.value);
        const options = { weekday: 'short', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' };
        preview.textContent = 'Message will be sent: ' + date.toLocaleDateString('en-US', options);
        
        const now = new Date();
        const diff = Math.floor((date - now) / 1000 / 60);
        if (diff > 0) {
            if (diff < 60) {
                preview.textContent += ' (in ' + diff + ' minutes)';
            } else if (diff < 1440) {
                preview.textContent += ' (in ' + Math.floor(diff / 60) + ' hours)';
            } else {
                preview.textContent += ' (in ' + Math.floor(diff / 1440) + ' days)';
            }
        }
    }
}

document.getElementById('schedule_date')?.addEventListener('change', updateSchedulePreview);
document.getElementById('schedule_time')?.addEventListener('change', updateSchedulePreview);

// CSV Upload handling
let parsedContacts = [];
let csvFilename = '';

document.getElementById('bulkUploadForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const fileInput = document.getElementById('csvFile');
    const file = fileInput.files[0];
    
    if (!file) {
        alert('Please select a CSV file');
        return;
    }
    
    const formData = new FormData();
    formData.append('file', file);
    
    const btn = this.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Processing...';
    
    try {
        const response = await fetch('/app/api/bulk.php?action=parse', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            parsedContacts = result.contacts;
            csvFilename = file.name;
            showCsvPreview(result);
        } else {
            alert('Error: ' + result.error);
        }
    } catch (err) {
        alert('Upload failed: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-upload"></i> Upload & Preview';
    }
});

function showCsvPreview(result) {
    const section = document.getElementById('previewSection');
    const tbody = document.getElementById('previewTableBody');
    const count = document.getElementById('previewCount');
    const errorsDiv = document.getElementById('previewErrors');
    
    tbody.innerHTML = '';
    
    result.contacts.slice(0, 50).forEach((contact, i) => {
        tbody.innerHTML += `
            <tr>
                <td>${i + 1}</td>
                <td>${escapeHtml(contact.phone)}</td>
                <td style="max-width: 200px;"><small>${escapeHtml(contact.message.substring(0, 50))}${contact.message.length > 50 ? '...' : ''}</small></td>
                <td>${contact.sim || 'auto'}</td>
                <td>${contact.priority || 5}</td>
            </tr>
        `;
    });
    
    count.textContent = `${result.valid_count} recipients`;
    
    if (result.errors && result.errors.length > 0) {
        errorsDiv.style.display = 'block';
        errorsDiv.innerHTML = '<i class="bi bi-exclamation-triangle"></i> <strong>Skipped rows:</strong><br>' + result.errors.slice(0, 5).join('<br>');
        if (result.errors.length > 5) {
            errorsDiv.innerHTML += `<br>... and ${result.errors.length - 5} more`;
        }
    } else {
        errorsDiv.style.display = 'none';
    }
    
    if (result.valid_count > 50) {
        tbody.innerHTML += `
            <tr>
                <td colspan="5" class="text-center text-muted">
                    ... and ${result.valid_count - 50} more recipients
                </td>
            </tr>
        `;
    }
    
    document.getElementById('contactsJson').value = JSON.stringify(parsedContacts);
    document.getElementById('filenameValue').value = csvFilename;
    
    section.style.display = 'block';
    section.scrollIntoView({ behavior: 'smooth' });
}

function cancelCsvBulk() {
    document.getElementById('previewSection').style.display = 'none';
    parsedContacts = [];
    document.getElementById('csvFile').value = '';
}

document.getElementById('bulkCsvSendForm')?.addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    
    const btn = this.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Queuing...';
    
    try {
        const response = await fetch('/send?tab=bulk&subtab=csv', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success || response.redirected) {
            window.location.href = '/queue';
        } else {
            alert('Error: ' + (result.error || 'Unknown error'));
        }
    } catch (err) {
        alert('Failed: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-send"></i> Queue All Messages';
    }
});
</script>
