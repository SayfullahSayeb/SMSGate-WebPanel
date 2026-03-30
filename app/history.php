<?php
/**
 * History Page - Message history and logs
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';

$auth = new Auth();
$auth->requireLogin();

$smsService = new SMSService();
$pendingCount = $smsService->getPendingQueueCount();

$db = Database::getInstance();

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;
$tab = $_GET['tab'] ?? 'all';

$filters = [
    'status' => $_GET['status'] ?? '',
    'search' => $_GET['search'] ?? '',
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
];

$messages = [];
$totalMessages = 0;
$totalPages = 0;

if ($tab === 'received') {
    $where = ['1=1'];
    $params = [];

    if (!empty($filters['search'])) {
        $where[] = "(phone_number LIKE ? OR message LIKE ?)";
        $search = '%' . $filters['search'] . '%';
        $params[] = $search;
        $params[] = $search;
    }

    if (!empty($filters['date_from'])) {
        $where[] = "received_at >= ?";
        $params[] = $filters['date_from'] . ' 00:00:00';
    }

    if (!empty($filters['date_to'])) {
        $where[] = "received_at <= ?";
        $params[] = $filters['date_to'] . ' 23:59:59';
    }

    $whereClause = implode(' AND ', $where);

    $stmt = $db->prepare("SELECT COUNT(*) FROM received_messages WHERE $whereClause");
    $stmt->execute($params);
    $totalMessages = (int)$stmt->fetchColumn();
    $totalPages = ceil($totalMessages / $perPage);

    $sql = "SELECT * FROM received_messages WHERE $whereClause ORDER BY received_at DESC LIMIT ? OFFSET ?";
    $params[] = $perPage;
    $params[] = $offset;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $messages = $stmt->fetchAll();

    $stmt = $db->prepare("SELECT COUNT(*) FROM received_messages");
    $stmt->execute();
    $inboxTotal = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM received_messages WHERE is_read = 0");
    $stmt->execute();
    $inboxUnread = (int)$stmt->fetchColumn();

    $stats = ['inbox_total' => $inboxTotal, 'inbox_unread' => $inboxUnread, 'received_total' => $inboxTotal];
} elseif ($tab === 'sent') {
    $where = ['1=1'];
    $params = [];

    if (!empty($filters['status'])) {
        if ($filters['status'] === 'awaiting') {
            $where[] = "status = 'sent' AND (delivered_at IS NULL OR delivered_at = '') AND sent_at > datetime('now', '-7 days')";
        } else {
            $where[] = "status = ?";
            $params[] = $filters['status'];
        }
    }

    if (!empty($filters['search'])) {
        $where[] = "(phone_number LIKE ? OR message LIKE ? OR external_id LIKE ?)";
        $search = '%' . $filters['search'] . '%';
        $params[] = $search;
        $params[] = $search;
        $params[] = $search;
    }

    if (!empty($filters['date_from'])) {
        $where[] = "sent_at >= ?";
        $params[] = $filters['date_from'] . ' 00:00:00';
    }

    if (!empty($filters['date_to'])) {
        $where[] = "sent_at <= ?";
        $params[] = $filters['date_to'] . ' 23:59:59';
    }

    $whereClause = implode(' AND ', $where);

    $stmt = $db->prepare("SELECT COUNT(*) FROM sent_messages WHERE $whereClause");
    $stmt->execute($params);
    $totalMessages = (int)$stmt->fetchColumn();
    $totalPages = ceil($totalMessages / $perPage);

    $sql = "SELECT * FROM sent_messages WHERE $whereClause ORDER BY sent_at DESC LIMIT ? OFFSET ?";
    $params[] = $perPage;
    $params[] = $offset;
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $messages = $stmt->fetchAll();

    $stmt = $db->prepare("SELECT COUNT(*) FROM sent_messages");
    $stmt->execute();
    $sentTotal = (int)$stmt->fetchColumn();

    $stats = ['sent_total' => $sentTotal, 'total' => $sentTotal];
} else {
    $whereSent = ['1=1'];
    $paramsSent = [];
    $whereReceived = ['1=1'];
    $paramsReceived = [];

    if (!empty($filters['search'])) {
        $whereSent[] = "(phone_number LIKE ? OR message LIKE ? OR external_id LIKE ?)";
        $search = '%' . $filters['search'] . '%';
        $paramsSent[] = $search;
        $paramsSent[] = $search;
        $paramsSent[] = $search;
        
        $whereReceived[] = "(phone_number LIKE ? OR message LIKE ?)";
        $paramsReceived[] = $search;
        $paramsReceived[] = $search;
    }

    if (!empty($filters['date_from'])) {
        $whereSent[] = "sent_at >= ?";
        $paramsSent[] = $filters['date_from'] . ' 00:00:00';
        $whereReceived[] = "received_at >= ?";
        $paramsReceived[] = $filters['date_from'] . ' 00:00:00';
    }

    if (!empty($filters['date_to'])) {
        $whereSent[] = "sent_at <= ?";
        $paramsSent[] = $filters['date_to'] . ' 23:59:59';
        $whereReceived[] = "received_at <= ?";
        $paramsReceived[] = $filters['date_to'] . ' 23:59:59';
    }

    $whereClauseSent = implode(' AND ', $whereSent);
    $whereClauseReceived = implode(' AND ', $whereReceived);

    $stmt = $db->prepare("SELECT COUNT(*) FROM sent_messages WHERE $whereClauseSent");
    $stmt->execute($paramsSent);
    $sentCount = (int)$stmt->fetchColumn();

    $stmt = $db->prepare("SELECT COUNT(*) FROM received_messages WHERE $whereClauseReceived");
    $stmt->execute($paramsReceived);
    $receivedCount = (int)$stmt->fetchColumn();

    $totalMessages = $sentCount + $receivedCount;
    $totalPages = ceil($totalMessages / $perPage);

    $offsetSent = min($offset, $sentCount);
    $offsetReceived = max(0, $offset - $sentCount);
    
    $limitSent = min($perPage, $sentCount - $offsetSent);
    $limitReceived = $perPage - $limitSent;

    $sentMessages = [];
    if ($limitSent > 0) {
        $sqlSent = "SELECT *, 'sent' as msg_type FROM sent_messages WHERE $whereClauseSent ORDER BY sent_at DESC LIMIT $limitSent OFFSET $offsetSent";
        $stmt = $db->prepare($sqlSent);
        $stmt->execute($paramsSent);
        $sentMessages = $stmt->fetchAll();
    }

    $receivedMessages = [];
    if ($limitReceived > 0) {
        $sqlReceived = "SELECT *, 'received' as msg_type FROM received_messages WHERE $whereClauseReceived ORDER BY received_at DESC LIMIT $limitReceived OFFSET $offsetReceived";
        $stmt = $db->prepare($sqlReceived);
        $stmt->execute($paramsReceived);
        $receivedMessages = $stmt->fetchAll();
    }

    $messages = array_merge($sentMessages, $receivedMessages);
    usort($messages, function($a, $b) {
        $dateA = $a['msg_type'] === 'sent' ? $a['sent_at'] : $a['received_at'];
        $dateB = $b['msg_type'] === 'sent' ? $b['sent_at'] : $b['received_at'];
        return strtotime($dateB) - strtotime($dateA);
    });

    $stats = ['sent_total' => $sentCount, 'received_total' => $receivedCount, 'total' => $totalMessages];
}

$stmt = $db->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent,
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
    FROM sent_messages
");
$stmt->execute();
$statsOverall = $stmt->fetch();

$stmt = $db->prepare("
    SELECT COUNT(*) FROM sent_messages 
    WHERE status = 'sent' 
    AND (delivered_at IS NULL OR delivered_at = '')
    AND sent_at > datetime('now', '-7 days')
");
$stmt->execute();
$pendingDelivery = (int)$stmt->fetchColumn();

ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title">Message History</h1>
        <p class="page-meta mb-0">View all sent messages and their delivery status</p>
    </div>
    <div class="d-flex gap-2">
        <?php if ($tab === 'sent'): ?>
        <button type="button" class="btn btn-outline-primary" onclick="refreshStatuses()">
            <i class="bi bi-arrow-clockwise"></i> Refresh Statuses
        </button>
        <a href="/app/api/history.php?action=export<?= !empty($filters['status']) ? '&status=' . urlencode($filters['status']) : '' ?><?= !empty($filters['date_from']) ? '&date_from=' . urlencode($filters['date_from']) : '' ?><?= !empty($filters['date_to']) ? '&date_to=' . urlencode($filters['date_to']) : '' ?>" class="btn btn-secondary">
            <i class="bi bi-download"></i> Export CSV
        </a>
        <?php endif; ?>
    </div>
</div>

<ul class="nav nav-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'all' ? 'active' : '' ?>" href="?tab=all">
            <i class="bi bi-list"></i> All
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'sent' ? 'active' : '' ?>" href="?tab=sent">
            <i class="bi bi-send"></i> Sent
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $tab === 'received' ? 'active' : '' ?>" href="?tab=received">
            <i class="bi bi-inbox"></i> Received
            <?php if (!empty($stats['inbox_unread'])): ?>
            <span class="badge bg-danger ms-1"><?= $stats['inbox_unread'] ?></span>
            <?php endif; ?>
        </a>
    </li>
</ul>

<?php if ($tab === 'all'): ?>
<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon blue">
                    <i class="bi bi-list"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format($stats['total'] ?? 0) ?></div>
                    <div class="stat-label">Total Messages</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon green">
                    <i class="bi bi-send"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format($stats['sent_total'] ?? 0) ?></div>
                    <div class="stat-label">Sent</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon yellow">
                    <i class="bi bi-inbox"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format($stats['received_total'] ?? 0) ?></div>
                    <div class="stat-label">Received</div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php elseif ($tab === 'sent'): ?>
<div class="row g-4 mb-4">
    <div class="col-md-3">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon blue">
                    <i class="bi bi-chat-left-text"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format($stats['sent_total'] ?? 0) ?></div>
                    <div class="stat-label">Total Sent</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon green">
                    <i class="bi bi-check-circle"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format($statsOverall['sent'] ?? 0) ?></div>
                    <div class="stat-label">Sent</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon yellow">
                    <i class="bi bi-check-all"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format($statsOverall['delivered'] ?? 0) ?></div>
                    <div class="stat-label">Delivered</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon red">
                    <i class="bi bi-x-circle"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format($statsOverall['failed'] ?? 0) ?></div>
                    <div class="stat-label">Failed</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon <?= ($pendingDelivery ?? 0) > 0 ? 'yellow' : 'green' ?>">
                    <i class="bi bi-hourglass-split"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format($pendingDelivery ?? 0) ?></div>
                    <div class="stat-label">Pending Delivery</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="alert alert-info mb-4">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <strong>Delivery Reports:</strong> The system receives delivery reports via webhooks when configured in SMS-Gate app settings. 
            If delivery status is not updating, click <strong>Refresh Statuses</strong> to sync with the API. Make sure webhooks is enabled in your SMS-Gate app under Settings → Webhooks.
        </div>
    </div>
    <div class="mt-2 small">
        <strong>Webhook URL:</strong> <code id="webhookUrl"><?= 'https://' . ($_SERVER['HTTP_HOST'] ?? 'your-server') . '/app/api/webhook.php' ?></code>
        <button class="btn btn-sm btn-outline-secondary ms-2" onclick="copyWebhookUrl()">
            <i class="bi bi-clipboard"></i> Copy
        </button>
    </div>
</div>

<style>
.delivery-timeline {
    display: flex;
    align-items: center;
    gap: 8px;
    font-size: 0.85rem;
}
.delivery-step {
    display: flex;
    align-items: center;
    gap: 4px;
}
.delivery-step .bi {
    font-size: 0.9rem;
}
.delivery-arrow {
    color: #6c757d;
    font-size: 0.8rem;
}
.status-sent { color: #198754; }
.status-delivered { color: #0dcaf0; }
.status-failed { color: #dc3545; }
.status-pending { color: #ffc107; }
</style>

<script>
function copyWebhookUrl() {
    const url = document.getElementById('webhookUrl').textContent;
    navigator.clipboard.writeText(url).then(() => {
        showToast('success', 'Webhook URL copied!');
    });
}
</script>
<?php elseif ($tab === 'received'): ?>
<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon blue">
                    <i class="bi bi-inbox"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format($stats['inbox_total'] ?? 0) ?></div>
                    <div class="stat-label">Total Received</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon <?= ($stats['inbox_unread'] ?? 0) > 0 ? 'yellow' : 'green' ?>">
                    <i class="bi bi-envelope"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format($stats['inbox_unread'] ?? 0) ?></div>
                    <div class="stat-label">Unread</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="alert alert-info mb-4">
    <div class="d-flex justify-content-between align-items-start">
        <div>
            <strong>Inbox:</strong> Incoming SMS from your devices are shown here. Make sure webhooks are configured in SMS-Gate app to receive messages automatically.
        </div>
        <?php if ($tab === 'received' && ($stats['inbox_unread'] ?? 0) > 0): ?>
        <button class="btn btn-sm btn-outline-primary" onclick="markAllRead()">
            <i class="bi bi-check-all"></i> Mark all read (<?= $stats['inbox_unread'] ?>)
        </button>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <input type="hidden" name="tab" value="<?= $tab ?>">
            <div class="col-md-3">
                <label class="form-label">Search</label>
                <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($filters['search']) ?>" placeholder="Phone, message...">
            </div>
            <?php if ($tab === 'sent' || $tab === 'all'): ?>
            <div class="col-md-2">
                <label class="form-label">Status</label>
                <select class="form-select" name="status">
                    <option value="">All</option>
                    <option value="pending" <?= $filters['status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="sent" <?= $filters['status'] === 'sent' ? 'selected' : '' ?>>Sent</option>
                    <option value="delivered" <?= $filters['status'] === 'delivered' ? 'selected' : '' ?>>Delivered</option>
                    <option value="awaiting" <?= $filters['status'] === 'awaiting' ? 'selected' : '' ?>>Awaiting Delivery</option>
                    <option value="failed" <?= $filters['status'] === 'failed' ? 'selected' : '' ?>>Failed</option>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-2">
                <label class="form-label">From Date</label>
                <input type="date" class="form-control" name="date_from" value="<?= htmlspecialchars($filters['date_from']) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">To Date</label>
                <input type="date" class="form-control" name="date_to" value="<?= htmlspecialchars($filters['date_to']) ?>">
            </div>
            <div class="col-md-3 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-funnel"></i> Filter
                </button>
                <a href="/history?tab=<?= $tab ?>" class="btn btn-secondary">Reset</a>
            </div>
        </form>
    </div>
</div>

<?php if ($tab === 'all'): ?>
<div class="card">
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th>Type</th>
                    <th>Date/Time</th>
                    <th>Phone</th>
                    <th>Message</th>
                    <th>SIM</th>
                    <th>Status</th>
                    <th>ID</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($messages)): ?>
                <tr>
                    <td colspan="7" class="text-center py-5">
                        <div class="empty-state">
                            <i class="bi bi-inbox"></i>
                            <h5>No messages</h5>
                            <p>Send or receive your first message</p>
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($messages as $msg): ?>
                <tr data-bs-toggle="modal" data-bs-target="#messageModal" data-id="<?= $msg['id'] ?>" data-type="<?= $msg['msg_type'] ?? 'sent' ?>">
                    <td>
                        <?php if (($msg['msg_type'] ?? 'sent') === 'sent'): ?>
                        <span class="badge badge-success"><i class="bi bi-send"></i> Sent</span>
                        <?php else: ?>
                        <span class="badge badge-primary"><i class="bi bi-inbox"></i> Received</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="fw-medium"><?= date('M d, Y', strtotime($msg['sent_at'] ?? $msg['received_at'])) ?></span>
                        <br><small class="text-muted"><?= date('H:i:s', strtotime($msg['sent_at'] ?? $msg['received_at'])) ?></small>
                    </td>
                    <td>
                        <span class="fw-medium"><?= htmlspecialchars($msg['phone_number']) ?></span>
                    </td>
                    <td>
                        <span class="text-truncate d-inline-block" style="max-width: 200px;" title="<?= htmlspecialchars($msg['message']) ?>"><?= htmlspecialchars($msg['message']) ?></span>
                    </td>
                    <td>
                        <?php if (($msg['msg_type'] ?? 'sent') === 'sent'): ?>
                        <?= ($msg['sim_selection'] ?? 'auto') === 'auto' ? '<span class="badge badge-secondary">Auto</span>' : '<span class="badge badge-info">SIM ' . htmlspecialchars($msg['sim_selection']) . '</span>' ?>
                        <?php else: ?>
                        <?= !empty($msg['sim_slot']) ? '<span class="badge badge-info">SIM ' . htmlspecialchars($msg['sim_slot']) . '</span>' : '<span class="badge badge-secondary">-</span>' ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (($msg['msg_type'] ?? 'sent') === 'sent'): ?>
                        <?php
                        $statusClass = match($msg['status']) {
                            'sent' => 'badge-success',
                            'delivered' => 'badge-info',
                            'pending' => 'badge-warning',
                            'failed' => 'badge-danger',
                            default => 'badge-secondary'
                        };
                        ?>
                        <span class="badge <?= $statusClass ?>"><?= ucfirst($msg['status']) ?></span>
                        <?php if (!empty($msg['delivered_at'])): ?>
                        <br><small class="text-success" title="Delivered"><i class="bi bi-check-circle-fill"></i></small>
                        <?php endif; ?>
                        <?php else: ?>
                        <?php if (empty($msg['is_read'])): ?>
                        <span class="badge badge-primary">New</span>
                        <?php else: ?>
                        <span class="badge badge-secondary">Read</span>
                        <?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <code class="small text-muted">#<?= $msg['id'] ?></code>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <?php if ($totalPages > 1): ?>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <span class="text-muted">Showing <?= (($page - 1) * $perPage) + 1 ?> - <?= min($page * $perPage, $totalMessages) ?> of <?= number_format($totalMessages) ?></span>
        <nav class="pagination mb-0">
            <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>&tab=all&status=<?= urlencode($filters['status']) ?>&search=<?= urlencode($filters['search']) ?>&date_from=<?= urlencode($filters['date_from']) ?>&date_to=<?= urlencode($filters['date_to']) ?>" class="page-link">
                <i class="bi bi-chevron-left"></i>
            </a>
            <?php endif; ?>
            
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="?page=<?= $i ?>&tab=all&status=<?= urlencode($filters['status']) ?>&search=<?= urlencode($filters['search']) ?>&date_from=<?= urlencode($filters['date_from']) ?>&date_to=<?= urlencode($filters['date_to']) ?>" class="page-link <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            
            <?php if ($page < $totalPages): ?>
            <a href="?page=<?= $page + 1 ?>&tab=all&status=<?= urlencode($filters['status']) ?>&search=<?= urlencode($filters['search']) ?>&date_from=<?= urlencode($filters['date_from']) ?>&date_to=<?= urlencode($filters['date_to']) ?>" class="page-link">
                <i class="bi bi-chevron-right"></i>
            </a>
            <?php endif; ?>
        </nav>
    </div>
    <?php endif; ?>
</div>
<?php elseif ($tab === 'sent'): ?>
<div class="card">
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th>Date/Time</th>
                    <th>Phone</th>
                    <th>Message</th>
                    <th>SIM</th>
                    <th>Status</th>
                    <th>Delivery</th>
                    <th>External ID</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($messages)): ?>
                <tr>
                    <td colspan="7" class="text-center py-5">
                        <div class="empty-state">
                            <i class="bi bi-send"></i>
                            <h5>No sent messages</h5>
                            <p>Send your first message from the Send page</p>
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($messages as $msg): ?>
                <tr data-bs-toggle="modal" data-bs-target="#messageModal" data-id="<?= $msg['id'] ?>" data-type="sent">
                    <td>
                        <span class="fw-medium"><?= date('M d, Y', strtotime($msg['sent_at'])) ?></span>
                        <br><small class="text-muted"><?= date('H:i:s', strtotime($msg['sent_at'])) ?></small>
                    </td>
                    <td>
                        <span class="fw-medium"><?= htmlspecialchars($msg['phone_number']) ?></span>
                    </td>
                    <td>
                        <span class="text-truncate d-inline-block" style="max-width: 250px;"><?= htmlspecialchars($msg['message']) ?></span>
                    </td>
                    <td>
                        <?= $msg['sim_selection'] === 'auto' ? '<span class="badge badge-secondary">Auto</span>' : '<span class="badge badge-info">SIM ' . htmlspecialchars($msg['sim_selection']) . '</span>' ?>
                    </td>
                    <td>
                        <?php
                        $statusClass = match($msg['status']) {
                            'sent' => 'badge-success',
                            'delivered' => 'badge-info',
                            'pending' => 'badge-warning',
                            'failed' => 'badge-danger',
                            default => 'badge-secondary'
                        };
                        ?>
                        <span class="badge <?= $statusClass ?>"><?= ucfirst($msg['status']) ?></span>
                    </td>
                    <td>
                        <div class="delivery-timeline">
                            <div class="delivery-step status-sent" title="Sent <?= date('M d, H:i', strtotime($msg['sent_at'])) ?>">
                                <i class="bi bi-send-fill"></i>
                            </div>
                            <?php if ($msg['status'] === 'delivered' && !empty($msg['delivered_at'])): ?>
                            <div class="delivery-arrow"><i class="bi bi-arrow-right"></i></div>
                            <div class="delivery-step status-delivered" title="Delivered <?= date('M d, H:i', strtotime($msg['delivered_at'])) ?>">
                                <i class="bi bi-check-circle-fill"></i>
                            </div>
                            <?php elseif ($msg['status'] === 'failed'): ?>
                            <div class="delivery-arrow"><i class="bi bi-arrow-right"></i></div>
                            <div class="delivery-step status-failed" title="<?= htmlspecialchars($msg['error_message'] ?? 'Failed') ?>">
                                <i class="bi bi-x-circle-fill"></i>
                            </div>
                            <?php elseif ($msg['status'] === 'pending'): ?>
                            <div class="delivery-arrow"><i class="bi bi-arrow-right"></i></div>
                            <div class="delivery-step status-pending">
                                <i class="bi bi-clock"></i>
                            </div>
                            <?php elseif ($msg['status'] === 'sent'): ?>
                            <div class="delivery-arrow"><i class="bi bi-arrow-right"></i></div>
                            <div class="delivery-step status-pending" title="Awaiting delivery report">
                                <i class="bi bi-hourglass-split"></i>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($msg['delivered_at'])): ?>
                        <div class="small text-muted mt-1">
                            <i class="bi bi-check2-all"></i> <?= date('M d, H:i', strtotime($msg['delivered_at'])) ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <code class="small"><?= htmlspecialchars($msg['external_id'] ?? '-') ?></code>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <?php if ($totalPages > 1): ?>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <span class="text-muted">Showing <?= (($page - 1) * $perPage) + 1 ?> - <?= min($page * $perPage, $totalMessages) ?> of <?= number_format($totalMessages) ?></span>
        <nav class="pagination mb-0">
            <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>&tab=sent&status=<?= urlencode($filters['status']) ?>&search=<?= urlencode($filters['search']) ?>&date_from=<?= urlencode($filters['date_from']) ?>&date_to=<?= urlencode($filters['date_to']) ?>" class="page-link">
                <i class="bi bi-chevron-left"></i>
            </a>
            <?php endif; ?>
            
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="?page=<?= $i ?>&tab=sent&status=<?= urlencode($filters['status']) ?>&search=<?= urlencode($filters['search']) ?>&date_from=<?= urlencode($filters['date_from']) ?>&date_to=<?= urlencode($filters['date_to']) ?>" class="page-link <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            
            <?php if ($page < $totalPages): ?>
            <a href="?page=<?= $page + 1 ?>&tab=sent&status=<?= urlencode($filters['status']) ?>&search=<?= urlencode($filters['search']) ?>&date_from=<?= urlencode($filters['date_from']) ?>&date_to=<?= urlencode($filters['date_to']) ?>" class="page-link">
                <i class="bi bi-chevron-right"></i>
            </a>
            <?php endif; ?>
        </nav>
    </div>
    <?php endif; ?>
</div>
<?php else: ?>
<div class="card">
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th>Date/Time</th>
                    <th>From</th>
                    <th>Message</th>
                    <th>SIM</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($messages)): ?>
                <tr>
                    <td colspan="4" class="text-center py-5">
                        <div class="empty-state">
                            <i class="bi bi-inbox"></i>
                            <h5>No received messages</h5>
                            <p>Incoming SMS will appear here when webhooks are configured</p>
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($messages as $msg): ?>
                <tr data-bs-toggle="modal" data-bs-target="#inboxModal" data-id="<?= $msg['id'] ?>" data-type="inbox" style="<?= empty($msg['is_read']) ? 'font-weight: 600;' : '' ?>">
                    <td>
                        <span class="fw-medium"><?= date('M d, Y', strtotime($msg['received_at'])) ?></span>
                        <br><small class="text-muted"><?= date('H:i:s', strtotime($msg['received_at'])) ?></small>
                    </td>
                    <td>
                        <span class="fw-medium"><?= htmlspecialchars($msg['phone_number']) ?></span>
                        <?php if (empty($msg['is_read'])): ?>
                        <span class="badge bg-primary ms-1">New</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="text-truncate d-inline-block" style="max-width: 300px;"><?= htmlspecialchars($msg['message']) ?></span>
                    </td>
                    <td>
                        <?= $msg['sim_slot'] ? '<span class="badge badge-info">SIM ' . htmlspecialchars($msg['sim_slot']) . '</span>' : '<span class="badge badge-secondary">Auto</span>' ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <?php if ($totalPages > 1): ?>
    <div class="card-footer d-flex justify-content-between align-items-center">
        <span class="text-muted">Showing <?= (($page - 1) * $perPage) + 1 ?> - <?= min($page * $perPage, $totalMessages) ?> of <?= number_format($totalMessages) ?></span>
        <nav class="pagination mb-0">
            <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 ?>&tab=received&search=<?= urlencode($filters['search']) ?>&date_from=<?= urlencode($filters['date_from']) ?>&date_to=<?= urlencode($filters['date_to']) ?>" class="page-link">
                <i class="bi bi-chevron-left"></i>
            </a>
            <?php endif; ?>
            
            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
            <a href="?page=<?= $i ?>&tab=received&search=<?= urlencode($filters['search']) ?>&date_from=<?= urlencode($filters['date_from']) ?>&date_to=<?= urlencode($filters['date_to']) ?>" class="page-link <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            
            <?php if ($page < $totalPages): ?>
            <a href="?page=<?= $page + 1 ?>&tab=received&search=<?= urlencode($filters['search']) ?>&date_from=<?= urlencode($filters['date_from']) ?>&date_to=<?= urlencode($filters['date_to']) ?>" class="page-link">
                <i class="bi bi-chevron-right"></i>
            </a>
            <?php endif; ?>
        </nav>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="modal fade" id="messageModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Sent Message Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="messageModalBody">
                <div class="text-center">
                    <div class="spinner-border text-primary" role="status"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="inboxModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Received Message</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="inboxModalBody">
                <div class="text-center">
                    <div class="spinner-border text-primary" role="status"></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('tr[data-bs-toggle="modal"]').forEach(row => {
        row.style.cursor = 'pointer';
    });
    
    document.querySelectorAll('tr[data-type="inbox"], tr[data-type="received"]').forEach(row => {
        row.addEventListener('click', function() {
            const id = this.dataset.id;
            fetch('/app/api/inbox.php?action=get&id=' + id)
                .then(r => r.json())
                .then(result => {
                    if (result.success) {
                        const msg = result.data;
                        document.getElementById('inboxModalBody').innerHTML = `
                            <div class="mb-3">
                                <label class="form-label text-muted small">From</label>
                                <div class="fw-medium">${escapeHtml(msg.phone_number)}</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label text-muted small">Received</label>
                                <div>${formatDateTime(msg.received_at)}</div>
                            </div>
                            ${msg.sim_slot ? '<div class="mb-3"><label class="form-label text-muted small">SIM</label><div>SIM ' + msg.sim_slot + '</div></div>' : ''}
                            <div class="mb-3">
                                <label class="form-label text-muted small">Message</label>
                                <div class="p-3 bg-light rounded">${escapeHtml(msg.message)}</div>
                            </div>
                        `;
                        fetch('/app/api/inbox.php?action=mark_read&id=' + id, { method: 'POST' });
                    }
                });
        });
    });
});

async function refreshStatuses() {
    const btn = document.querySelector('button[onclick="refreshStatuses()"]');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Refreshing...';
    
    try {
        const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
        const response = await fetch('/app/api/refresh.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken
            }
        });
        const result = await response.json();
        
        if (result.success) {
            showToast('success', result.message);
            setTimeout(() => location.reload(), 500);
        } else {
            showToast('danger', result.error || 'Failed to refresh');
        }
    } catch (err) {
        showToast('danger', 'Network error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

async function markAllRead() {
    try {
        const response = await fetch('/app/api/inbox.php?action=mark_all_read', {
            method: 'POST'
        });
        const result = await response.json();
        
        if (result.success) {
            showToast('success', 'All messages marked as read');
            setTimeout(() => location.reload(), 300);
        } else {
            showToast('danger', result.error || 'Failed to mark as read');
        }
    } catch (err) {
        showToast('danger', 'Network error');
    }
}

function showToast(type, message) {
    const toast = document.createElement('div');
    toast.className = `toast align-items-center text-white bg-${type} position-fixed bottom-0 end-0 m-3`;
    toast.setAttribute('role', 'alert');
    toast.innerHTML = '<div class="d-flex"><div class="toast-body">' + message + '</div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>';
    document.body.appendChild(toast);
    const bsToast = new bootstrap.Toast(toast);
    bsToast.show();
    toast.addEventListener('hidden.bs.toast', () => toast.remove());
}

const messageModal = document.getElementById('messageModal');
messageModal.addEventListener('show.bs.modal', async function(e) {
    const row = e.relatedTarget;
    const id = row.dataset.id;
    
    document.getElementById('messageModalBody').innerHTML = '<div class="text-center"><div class="spinner-border text-primary" role="status"></div></div>';
    
    try {
        const response = await fetch('/app/api/history.php?action=get&id=' + id);
        const result = await response.json();
        
        if (result.success) {
            const msg = result.data;
            let deliveryTimeline = '';
            
            if (msg.status === 'delivered' && msg.delivered_at) {
                deliveryTimeline = `
                    <div class="card bg-light mb-3">
                        <div class="card-body py-2">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge bg-success"><i class="bi bi-send-fill"></i> Sent</span>
                                <i class="bi bi-arrow-right text-muted"></i>
                                <span class="badge bg-info"><i class="bi bi-check-circle-fill"></i> Delivered</span>
                            </div>
                            <div class="row small text-muted">
                                <div class="col-6">
                                    <i class="bi bi-clock"></i> Sent: ${formatDateTime(msg.sent_at)}
                                </div>
                                <div class="col-6">
                                    <i class="bi bi-check2-all"></i> Delivered: ${formatDateTime(msg.delivered_at)}
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            } else if (msg.status === 'failed') {
                deliveryTimeline = `
                    <div class="card bg-light mb-3">
                        <div class="card-body py-2">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge bg-success"><i class="bi bi-send-fill"></i> Sent</span>
                                <i class="bi bi-arrow-right text-muted"></i>
                                <span class="badge bg-danger"><i class="bi bi-x-circle-fill"></i> Failed</span>
                            </div>
                            <div class="row small text-muted">
                                <div class="col-6">
                                    <i class="bi bi-clock"></i> Sent: ${formatDateTime(msg.sent_at)}
                                </div>
                                <div class="col-6">
                                    <i class="bi bi-exclamation-triangle"></i> Failed
                                </div>
                            </div>
                            ${msg.error_message ? '<div class="mt-2 text-danger small"><i class="bi bi-exclamation-circle"></i> ' + escapeHtml(msg.error_message) + '</div>' : ''}
                        </div>
                    </div>
                `;
            } else if (msg.status === 'sent') {
                deliveryTimeline = `
                    <div class="alert alert-warning mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <i class="bi bi-hourglass-split"></i>
                            <span>Awaiting delivery confirmation</span>
                        </div>
                        <div class="small mt-1">Sent: ${formatDateTime(msg.sent_at)}</div>
                    </div>
                `;
            }
            
            document.getElementById('messageModalBody').innerHTML = `
                ${deliveryTimeline}
                <div class="mb-3">
                    <label class="form-label text-muted small">Phone Number</label>
                    <div class="fw-medium">${escapeHtml(msg.phone_number)}</div>
                </div>
                <div class="mb-3">
                    <label class="form-label text-muted small">Message</label>
                    <div class="p-3 bg-light rounded">${escapeHtml(msg.message)}</div>
                </div>
                <div class="row">
                    <div class="col-6">
                        <label class="form-label text-muted small">SIM</label>
                        <div>${msg.sim_selection || 'Auto'}</div>
                    </div>
                    <div class="col-6">
                        <label class="form-label text-muted small">Priority</label>
                        <div>${msg.priority || 5}</div>
                    </div>
                </div>
                <hr>
                <div class="row">
                    <div class="col-6">
                        <label class="form-label text-muted small">Status</label>
                        <div><span class="badge badge-${getStatusClass(msg.status)}">${msg.status}</span></div>
                    </div>
                    <div class="col-6">
                        <label class="form-label text-muted small">Sent At</label>
                        <div>${formatDateTime(msg.sent_at)}</div>
                    </div>
                </div>
                ${msg.external_id ? '<div class="mt-3"><label class="form-label text-muted small">External ID</label><code>' + escapeHtml(msg.external_id) + '</code></div>' : ''}
            `;
        }
    } catch (err) {
        document.getElementById('messageModalBody').innerHTML = '<div class="text-danger">Failed to load message details</div>';
    }
});

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function getStatusClass(status) {
    return {
        'sent': 'success',
        'delivered': 'info',
        'pending': 'warning',
        'failed': 'danger'
    }[status] || 'secondary';
}

function formatDateTime(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}
</script>
<?php
$content = ob_get_clean();
$pageTitle = 'Message History - ' . APP_NAME;
$currentPage = 'history';

require_once TEMPLATES_PATH . '/base.php';
?>
