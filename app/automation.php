<?php
/**
 * Automation Page - Auto-reply rules and keyword triggers
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';

$auth = new Auth();
$auth->requireLogin();

$smsService = new SMSService();
$pendingCount = $smsService->getPendingQueueCount();

$db = Database::getInstance();

$errors = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validateToken($_POST['csrf_token'] ?? null)) {
        $errors[] = 'Invalid request token.';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'create') {
            $name = trim($_POST['name'] ?? '');
            $keyword = trim($_POST['trigger_keyword'] ?? '');
            $matchType = $_POST['match_type'] ?? 'exact';
            $replyMessage = trim($_POST['reply_message'] ?? '');
            $enabled = isset($_POST['enabled']) ? 1 : 0;
            $priority = (int)($_POST['priority'] ?? 10);
            $caseSensitive = isset($_POST['case_sensitive']) ? 1 : 0;
            $maxReplies = (int)($_POST['max_replies_per_day'] ?? 0);
            
            if (empty($name)) $errors[] = 'Rule name is required.';
            if (empty($keyword)) $errors[] = 'Trigger keyword is required.';
            if (empty($replyMessage)) $errors[] = 'Reply message is required.';
            
            if (empty($errors)) {
                $stmt = $db->prepare("
                    INSERT INTO auto_reply_rules (name, trigger_keyword, match_type, reply_message, enabled, priority, case_sensitive, max_replies_per_day)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$name, $keyword, $matchType, $replyMessage, $enabled, $priority, $caseSensitive, $maxReplies]);
                
                $_SESSION['flash_message'] = 'Auto-reply rule created successfully!';
                $_SESSION['flash_type'] = 'success';
                header('Location: /automation');
                exit;
            }
        } elseif ($action === 'update') {
            $id = (int)($_POST['id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $keyword = trim($_POST['trigger_keyword'] ?? '');
            $matchType = $_POST['match_type'] ?? 'exact';
            $replyMessage = trim($_POST['reply_message'] ?? '');
            $enabled = isset($_POST['enabled']) ? 1 : 0;
            $priority = (int)($_POST['priority'] ?? 10);
            $caseSensitive = isset($_POST['case_sensitive']) ? 1 : 0;
            $maxReplies = (int)($_POST['max_replies_per_day'] ?? 0);
            
            if (empty($name)) $errors[] = 'Rule name is required.';
            if (empty($keyword)) $errors[] = 'Trigger keyword is required.';
            if (empty($replyMessage)) $errors[] = 'Reply message is required.';
            
            if (empty($errors) && $id > 0) {
                $stmt = $db->prepare("
                    UPDATE auto_reply_rules SET 
                        name = ?, trigger_keyword = ?, match_type = ?, reply_message = ?,
                        enabled = ?, priority = ?, case_sensitive = ?, max_replies_per_day = ?,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([$name, $keyword, $matchType, $replyMessage, $enabled, $priority, $caseSensitive, $maxReplies, $id]);
                
                $_SESSION['flash_message'] = 'Auto-reply rule updated successfully!';
                $_SESSION['flash_type'] = 'success';
                header('Location: /automation');
                exit;
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $db->prepare("DELETE FROM auto_reply_rules WHERE id = ?");
                $stmt->execute([$id]);
                
                $_SESSION['flash_message'] = 'Auto-reply rule deleted.';
                $_SESSION['flash_type'] = 'success';
                header('Location: /automation');
                exit;
            }
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            $enabled = isset($_POST['enabled']) ? 1 : 0;
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE auto_reply_rules SET enabled = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$enabled, $id]);
                
                $_SESSION['flash_message'] = $enabled ? 'Rule enabled.' : 'Rule disabled.';
                $_SESSION['flash_type'] = 'success';
                header('Location: /automation');
                exit;
            }
        }
    }
}

$stmt = $db->prepare("SELECT COUNT(*) FROM auto_reply_rules");
$stmt->execute();
$totalRules = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM auto_reply_rules WHERE enabled = 1");
$stmt->execute();
$activeRules = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT SUM(trigger_count) FROM auto_reply_rules");
$stmt->execute();
$totalTriggers = (int)$stmt->fetchColumn();

$stmt = $db->prepare("SELECT * FROM auto_reply_rules ORDER BY priority ASC, created_at DESC");
$stmt->execute();
$rules = $stmt->fetchAll();

$editRule = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $db->prepare("SELECT * FROM auto_reply_rules WHERE id = ?");
    $stmt->execute([$editId]);
    $editRule = $stmt->fetch();
}

ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title">Automation</h1>
        <p class="page-meta mb-0">Create auto-reply rules based on keyword triggers</p>
    </div>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#ruleModal">
        <i class="bi bi-plus-lg"></i> New Rule
    </button>
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

<div class="row g-4 mb-4">
    <div class="col-md-4">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon blue">
                    <i class="bi bi-list-ol"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format($totalRules) ?></div>
                    <div class="stat-label">Total Rules</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon green">
                    <i class="bi bi-check-circle"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format($activeRules) ?></div>
                    <div class="stat-label">Active Rules</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon yellow">
                    <i class="bi bi-lightning-charge"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format($totalTriggers) ?></div>
                    <div class="stat-label">Total Triggers</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="alert alert-info mb-4">
    <i class="bi bi-info-circle"></i>
    <strong>Auto-Reply Rules:</strong> When an incoming SMS matches a rule's keyword, an automatic reply will be sent. 
    Make sure webhooks are configured in SMS-Gate app to receive incoming messages.
</div>

<div class="card">
    <div class="card-header">
        <h5><i class="bi bi-list-ul"></i> Auto-Reply Rules</h5>
    </div>
    <?php if (empty($rules)): ?>
    <div class="card-body">
        <div class="empty-state">
            <i class="bi bi-lightning-charge"></i>
            <h5>No automation rules</h5>
            <p>Create your first auto-reply rule to automatically respond to incoming messages</p>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#ruleModal">
                <i class="bi bi-plus-lg"></i> Create First Rule
            </button>
        </div>
    </div>
    <?php else: ?>
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th>Status</th>
                    <th>Name</th>
                    <th>Keyword</th>
                    <th>Match Type</th>
                    <th>Reply Preview</th>
                    <th>Triggers</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rules as $rule): ?>
                <tr>
                    <td>
                        <form method="POST" class="d-inline">
                            <?= CSRF::getField() ?>
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= $rule['id'] ?>">
                            <input type="hidden" name="enabled" value="<?= $rule['enabled'] ? 0 : 1 ?>">
                            <button type="submit" class="btn btn-ghost p-1" title="<?= $rule['enabled'] ? 'Disable' : 'Enable' ?>">
                                <?php if ($rule['enabled']): ?>
                                <span class="status-dot online"></span>
                                <?php else: ?>
                                <span class="status-dot offline"></span>
                                <?php endif; ?>
                            </button>
                        </form>
                    </td>
                    <td>
                        <span class="fw-medium"><?= htmlspecialchars($rule['name']) ?></span>
                    </td>
                    <td>
                        <code class="text-dark"><?= htmlspecialchars($rule['trigger_keyword']) ?></code>
                    </td>
                    <td>
                        <span class="badge badge-secondary"><?= ucfirst(str_replace('_', ' ', $rule['match_type'])) ?></span>
                    </td>
                    <td>
                        <span class="text-truncate d-inline-block" style="max-width: 200px;" title="<?= htmlspecialchars($rule['reply_message']) ?>">
                            <?= htmlspecialchars($rule['reply_message']) ?>
                        </span>
                    </td>
                    <td>
                        <span class="badge badge-primary"><?= number_format($rule['trigger_count']) ?></span>
                    </td>
                    <td>
                        <div class="action-group">
                            <a href="?edit=<?= $rule['id'] ?>" class="action-btn" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <form method="POST" class="d-inline" onsubmit="return confirm('Delete this rule?');">
                                <?= CSRF::getField() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $rule['id'] ?>">
                                <button type="submit" class="action-btn danger" title="Delete">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="ruleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">
                    <i class="bi bi-lightning-charge text-primary"></i>
                    <?= $editRule ? 'Edit Rule' : 'Create Auto-Reply Rule' ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <?= CSRF::getField() ?>
                    <input type="hidden" name="action" value="<?= $editRule ? 'update' : 'create' ?>">
                    <?php if ($editRule): ?>
                    <input type="hidden" name="id" value="<?= $editRule['id'] ?>">
                    <?php endif; ?>
                    
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Rule Name *</label>
                            <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($editRule['name'] ?? '') ?>" placeholder="e.g., Balance Check" required>
                            <div class="form-text">A descriptive name for this rule</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Trigger Keyword *</label>
                            <input type="text" class="form-control" name="trigger_keyword" value="<?= htmlspecialchars($editRule['trigger_keyword'] ?? '') ?>" placeholder="e.g., BALANCE" required>
                            <div class="form-text">The keyword to trigger this auto-reply</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Match Type</label>
                            <select class="form-select" name="match_type">
                                <option value="exact" <?= ($editRule['match_type'] ?? 'exact') === 'exact' ? 'selected' : '' ?>>Exact Match</option>
                                <option value="contains" <?= ($editRule['match_type'] ?? '') === 'contains' ? 'selected' : '' ?>>Contains</option>
                                <option value="starts_with" <?= ($editRule['match_type'] ?? '') === 'starts_with' ? 'selected' : '' ?>>Starts With</option>
                                <option value="regex" <?= ($editRule['match_type'] ?? '') === 'regex' ? 'selected' : '' ?>>Regex Pattern</option>
                            </select>
                            <div class="form-text">How to match the keyword</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Reply Message *</label>
                            <textarea class="form-control" name="reply_message" rows="4" placeholder="Type your auto-reply message here..." required><?= htmlspecialchars($editRule['reply_message'] ?? '') ?></textarea>
                            <div class="char-counter">
                                <span id="replyCharCount">0</span> characters
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Priority</label>
                            <select class="form-select" name="priority">
                                <option value="1" <?= ($editRule['priority'] ?? 10) == 1 ? 'selected' : '' ?>>Low</option>
                                <option value="5" <?= ($editRule['priority'] ?? 10) == 5 ? 'selected' : '' ?>>Normal</option>
                                <option value="10" <?= ($editRule['priority'] ?? 10) == 10 ? 'selected' : '' ?>>High</option>
                                <option value="20" <?= ($editRule['priority'] ?? 10) == 20 ? 'selected' : '' ?>>Critical</option>
                            </select>
                            <div class="form-text">Higher priority rules match first</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Daily Reply Limit</label>
                            <input type="number" class="form-control" name="max_replies_per_day" value="<?= $editRule['max_replies_per_day'] ?? 0 ?>" placeholder="0 = unlimited" min="0">
                            <div class="form-text">0 = unlimited replies per day</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">&nbsp;</label>
                            <div class="form-check mt-2">
                                <input type="checkbox" class="form-check-input" id="enabled" name="enabled" value="1" <?= !isset($editRule) || $editRule['enabled'] ? 'checked' : '' ?>>
                                <label class="form-check-label" for="enabled">Enable this rule</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="case_sensitive" name="case_sensitive" value="1" <?= !empty($editRule['case_sensitive']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="case_sensitive">
                                    Case sensitive matching
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> <?= $editRule ? 'Update Rule' : 'Create Rule' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const replyTextarea = document.querySelector('textarea[name="reply_message"]');
    const charCount = document.getElementById('replyCharCount');
    
    function updateCount() {
        if (replyTextarea && charCount) {
            charCount.textContent = replyTextarea.value.length;
        }
    }
    
    if (replyTextarea) {
        replyTextarea.addEventListener('input', updateCount);
        updateCount();
    }
    
    <?php if ($editRule): ?>
    const modal = new bootstrap.Modal(document.getElementById('ruleModal'));
    modal.show();
    <?php endif; ?>
});
</script>
<?php
$content = ob_get_clean();
$pageTitle = 'Automation - ' . APP_NAME;
$currentPage = 'automation';

require_once TEMPLATES_PATH . '/base.php';
?>
