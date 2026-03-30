<?php
/**
 * Queue Management Page
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';

$auth = new Auth();
$auth->requireLogin();

$smsService = new SMSService();
$queueProcessor = new QueueProcessor();

$pendingCount = $smsService->getPendingQueueCount();
$queueStats = $queueProcessor->getQueueStats();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    if (!CSRF::validateToken($_POST['csrf_token'] ?? null)) {
        $_SESSION['flash_message'] = 'Invalid request token.';
        $_SESSION['flash_type'] = 'danger';
    } else {
        $ids = $_POST['selected_ids'] ?? [];
        $action = $_POST['bulk_action'];
        
        if (empty($ids)) {
            $_SESSION['flash_message'] = 'No items selected.';
            $_SESSION['flash_type'] = 'warning';
        } else {
            $count = 0;
            foreach ($ids as $id) {
                $id = (int)$id;
                if ($action === 'delete') {
                    if ($smsService->deleteQueueMessage($id)) $count++;
                } elseif ($action === 'retry') {
                    if ($smsService->retryQueueMessage($id)) $count++;
                }
            }
            
            $_SESSION['flash_message'] = "{$count} messages {$action}d successfully.";
            $_SESSION['flash_type'] = 'success';
        }
    }
    
    header('Location: /queue');
    exit;
}

$statusFilter = $_GET['status'] ?? null;
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;

$allQueue = $smsService->getQueueMessages(1000, $statusFilter);

$scheduledCount = 0;
foreach ($allQueue as $msg) {
    if (!empty($msg['scheduled_at']) && $msg['scheduled_at'] > date('Y-m-d H:i:s')) {
        $scheduledCount++;
    }
}

$totalItems = count($allQueue);
$totalPages = ceil($totalItems / $perPage);
$queueMessages = array_slice($allQueue, ($page - 1) * $perPage, $perPage);

ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title">Queue Management</h1>
        <p class="page-meta mb-0"><?= number_format($totalItems) ?> total messages</p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-secondary" onclick="location.reload()">
            <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
        <a href="/send" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i> New Message
        </a>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon yellow"><i class="bi bi-hourglass-split"></i></div>
                <div>
                    <div class="stat-value"><?= number_format($queueStats['pending']) ?></div>
                    <div class="stat-label">Pending</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon blue"><i class="bi bi-clock"></i></div>
                <div>
                    <div class="stat-value"><?= number_format($scheduledCount) ?></div>
                    <div class="stat-label">Scheduled</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon green"><i class="bi bi-check-all"></i></div>
                <div>
                    <div class="stat-value"><?= number_format($queueStats['sent']) ?></div>
                    <div class="stat-label">Sent</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon red"><i class="bi bi-x-circle"></i></div>
                <div>
                    <div class="stat-value"><?= number_format($queueStats['failed']) ?></div>
                    <div class="stat-label">Failed</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="filter-pills">
                <a href="/queue" class="filter-pill <?= !$statusFilter ? 'active' : '' ?>">All</a>
                <a href="/queue?status=pending" class="filter-pill <?= $statusFilter === 'pending' ? 'active' : '' ?>">Pending</a>
                <a href="/queue?status=scheduled" class="filter-pill <?= $statusFilter === 'scheduled' ? 'active' : '' ?>">Scheduled</a>
                <a href="/queue?status=sent" class="filter-pill <?= $statusFilter === 'sent' ? 'active' : '' ?>">Sent</a>
                <a href="/queue?status=failed" class="filter-pill <?= $statusFilter === 'failed' ? 'active' : '' ?>">Failed</a>
                <a href="/queue?status=rate_limited" class="filter-pill <?= $statusFilter === 'rate_limited' ? 'active' : '' ?>">Rate Limited</a>
            </div>
            <div class="ms-auto">
                <form method="POST" class="d-flex gap-2">
                    <?= CSRF::getField() ?>
                    <select class="form-select form-select-sm" name="bulk_action" style="width: auto; min-width: 140px;">
                        <option value="">Bulk Actions</option>
                        <option value="retry">Retry Selected</option>
                        <option value="delete">Delete Selected</option>
                    </select>
                    <button type="submit" class="btn btn-sm btn-outline-primary">Apply</button>
                </form>
            </div>
        </div>
    </div>
    <div class="card-body p-0">
        <?php if (empty($queueMessages)): ?>
        <div class="empty-state">
            <i class="bi bi-inbox"></i>
            <h5>No Messages Found</h5>
            <p>There are no messages matching your filter.</p>
        </div>
        <?php else: ?>
        <div class="table-wrapper" style="border:none; border-radius:0;">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th style="width:40px;">
                            <input type="checkbox" class="form-check-input" id="selectAll">
                        </th>
                        <th>ID</th>
                        <th>Phone</th>
                        <th>Message</th>
                        <th>SIM</th>
                        <th>Priority</th>
                        <th>Status</th>
                        <th>Scheduled</th>
                        <th>Created</th>
                        <th style="width:80px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($queueMessages as $msg): 
                        $isScheduled = !empty($msg['scheduled_at']) && $msg['scheduled_at'] > date('Y-m-d H:i:s');
                        $isReady = $isScheduled && strtotime($msg['scheduled_at']) <= time() + 60;
                    ?>
                    <tr class="<?= $isScheduled ? 'table-warning' : '' ?>">
                        <td>
                            <input type="checkbox" class="form-check-input row-checkbox" name="selected_ids[]" value="<?= $msg['id'] ?>">
                        </td>
                        <td><code>#<?= $msg['id'] ?></code></td>
                        <td><code><?= htmlspecialchars($msg['phone_number']) ?></code></td>
                        <td>
                            <span style="max-width:150px;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?= htmlspecialchars($msg['message']) ?>">
                                <?= htmlspecialchars(substr($msg['message'], 0, 25)) ?><?= strlen($msg['message']) > 25 ? '...' : '' ?>
                            </span>
                        </td>
                        <td><span class="badge badge-secondary"><?= htmlspecialchars($msg['sim_selection']) ?></span></td>
                        <td>
                            <span class="badge badge-<?= $msg['priority'] >= 9 ? 'danger' : ($msg['priority'] <= 1 ? 'secondary' : 'info') ?>">
                                <?= $msg['priority'] ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($isScheduled): ?>
                            <span class="badge badge-info">
                                <i class="bi bi-clock"></i> Scheduled
                            </span>
                            <?php else: ?>
                            <span class="badge badge-<?= match($msg['status']) { 'pending' => 'warning', 'sent' => 'success', 'failed' => 'danger', 'rate_limited' => 'info', default => 'secondary' } ?>">
                                <?= htmlspecialchars($msg['status']) ?>
                            </span>
                            <?php endif; ?>
                            <?php if ($msg['error_message']): ?>
                            <small class="d-block text-muted mt-1" style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <?= htmlspecialchars(substr($msg['error_message'], 0, 25)) ?>
                            </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isScheduled): ?>
                            <small class="text-info">
                                <i class="bi bi-calendar3"></i> <?= date('M j, H:i', strtotime($msg['scheduled_at'])) ?>
                            </small>
                            <?php else: ?>
                            <small class="text-muted">-</small>
                            <?php endif; ?>
                        </td>
                        <td><small class="text-muted"><?= date('M j, H:i', strtotime($msg['created_at'])) ?></small></td>
                        <td>
                            <div class="action-group">
                                <?php if ($isScheduled): ?>
                                <button class="action-btn" onclick="sendNow(<?= $msg['id'] ?>)" title="Send Now">
                                    <i class="bi bi-play"></i>
                                </button>
                                <button class="action-btn danger" onclick="deleteItem(<?= $msg['id'] ?>)" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php elseif ($msg['status'] === 'pending'): ?>
                                <button class="action-btn danger" onclick="deleteItem(<?= $msg['id'] ?>)" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php elseif (in_array($msg['status'], ['failed', 'rate_limited'])): ?>
                                <button class="action-btn" onclick="retryItem(<?= $msg['id'] ?>)" title="Retry">
                                    <i class="bi bi-arrow-clockwise"></i>
                                </button>
                                <button class="action-btn danger" onclick="deleteItem(<?= $msg['id'] ?>)" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($totalPages > 1): ?>
    <div class="card-footer bg-transparent">
        <nav>
            <ul class="pagination justify-content-center mb-0">
                <?php if ($page > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?= $page - 1 ?>&status=<?= htmlspecialchars($statusFilter ?? '') ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
                <?php endif; ?>
                
                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link" href="?page=<?= $i ?>&status=<?= htmlspecialchars($statusFilter ?? '') ?>"><?= $i ?></a>
                </li>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                <li class="page-item">
                    <a class="page-link" href="?page=<?= $page + 1 ?>&status=<?= htmlspecialchars($statusFilter ?? '') ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Queue - ' . APP_NAME;
$currentPage = 'queue';

require_once TEMPLATES_PATH . '/base.php';
?>

<script>
document.getElementById('selectAll')?.addEventListener('change', function() {
    document.querySelectorAll('.row-checkbox').forEach(cb => cb.checked = this.checked);
});

async function deleteItem(id) {
    if (!confirm('Delete this message?')) return;
    await performAction('delete', id);
}

async function retryItem(id) {
    await performAction('retry', id);
}

async function sendNow(id) {
    if (!confirm('Send this scheduled message now?')) return;
    await performAction('send_now', id);
}

async function performAction(action, id) {
    try {
        const formData = new FormData();
        formData.append('csrf_token', '<?= CSRF::generateToken() ?>');
        formData.append('action', action);
        formData.append('id', id);
        
        const response = await fetch('/app/api/queue.php', {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) location.reload();
        else alert(result.error || 'Action failed');
    } catch (error) {
        alert('Network error');
    }
}
</script>
