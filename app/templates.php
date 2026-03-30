<?php
/**
 * Templates Page - Message template management
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';

$auth = new Auth();
$auth->requireLogin();

$smsService = new SMSService();
$pendingCount = $smsService->getPendingQueueCount();
$templateService = new TemplateService();

$templates = $templateService->getAll();
$categories = $templateService->getCategories();
$stats = $templateService->getStats();

$editingTemplate = null;
if (isset($_GET['edit'])) {
    $editingTemplate = $templateService->getById((int)$_GET['edit']);
}

ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title">Message Templates</h1>
        <p class="page-meta mb-0">Create and manage reusable message templates</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#templateModal">
        <i class="bi bi-plus-lg"></i> New Template
    </button>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon blue">
                    <i class="bi bi-file-text"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format($stats['total_templates']) ?></div>
                    <div class="stat-label">Total Templates</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon green">
                    <i class="bi bi-bar-chart"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format($stats['total_usage']) ?></div>
                    <div class="stat-label">Total Usage</div>
                </div>
            </div>
        </div>
    </div>
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
                    <th>Updated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($templates)): ?>
                <tr>
                    <td colspan="6" class="text-center py-5">
                        <div class="empty-state">
                            <i class="bi bi-file-text"></i>
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
                        <span class="badge badge-secondary"><?= htmlspecialchars($tmpl['category']) ?></span>
                    </td>
                    <td>
                        <?php if (empty($variables)): ?>
                        <span class="text-muted">-</span>
                        <?php else: ?>
                        <?php foreach ($variables as $v): ?>
                        <code class="me-1">{{<?= htmlspecialchars($v) ?>}}</code>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge badge-info"><?= number_format($tmpl['usage_count']) ?></span>
                    </td>
                    <td>
                        <small class="text-muted"><?= date('M d, Y', strtotime($tmpl['updated_at'])) ?></small>
                    </td>
                    <td>
                        <div class="action-group">
                            <button class="action-btn" onclick="previewTemplate(<?= $tmpl['id'] ?>)" title="Preview">
                                <i class="bi bi-eye"></i>
                            </button>
                            <a href="/templates?edit=<?= $tmpl['id'] ?>" class="action-btn" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <button class="action-btn" onclick="useTemplate(<?= $tmpl['id'] ?>)" title="Use">
                                <i class="bi bi-send"></i>
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

<div class="modal fade" id="templateModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?= $editingTemplate ? 'Edit Template' : 'New Template' ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="/app/api/template.php?action=<?= $editingTemplate ? 'update' : 'create' ?>" id="templateForm">
                <div class="modal-body">
                    <?= CSRF::getField() ?>
                    <?php if ($editingTemplate): ?>
                    <input type="hidden" name="id" value="<?= $editingTemplate['id'] ?>">
                    <?php endif; ?>
                    
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Template Name *</label>
                            <input type="text" class="form-control" name="name" value="<?= htmlspecialchars($editingTemplate['name'] ?? '') ?>" required placeholder="e.g., Appointment Reminder">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Category</label>
                            <select class="form-select" name="category">
                                <option value="general" <?= ($editingTemplate['category'] ?? '') === 'general' ? 'selected' : '' ?>>General</option>
                                <option value="notification" <?= ($editingTemplate['category'] ?? '') === 'notification' ? 'selected' : '' ?>>Notification</option>
                                <option value="alert" <?= ($editingTemplate['category'] ?? '') === 'alert' ? 'selected' : '' ?>>Alert</option>
                                <option value="reminder" <?= ($editingTemplate['category'] ?? '') === 'reminder' ? 'selected' : '' ?>>Reminder</option>
                                <option value="marketing" <?= ($editingTemplate['category'] ?? '') === 'marketing' ? 'selected' : '' ?>>Marketing</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Subject (Optional)</label>
                            <input type="text" class="form-control" name="subject" value="<?= htmlspecialchars($editingTemplate['subject'] ?? '') ?>" placeholder="Brief subject line">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Message Content *</label>
                            <textarea class="form-control" name="content" id="templateContent" rows="6" required placeholder="Hello {{name}}, your appointment is on {{date}} at {{time}}..."><?= htmlspecialchars($editingTemplate['content'] ?? '') ?></textarea>
                            <div class="form-text">
                                Use <code>{{variable}}</code> for dynamic content. Example: <code>{{name}}</code>, <code>{{date}}</code>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mt-3" id="variablePreview">
                        <label class="form-label">Detected Variables</label>
                        <div id="variableTags"></div>
                    </div>
                    
                    <div class="mt-3" id="messagePreview">
                        <label class="form-label">Preview</label>
                        <div class="p-3 bg-light rounded" id="previewContent">
                            <em class="text-muted">Enter message content to see preview...</em>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> <?= $editingTemplate ? 'Update Template' : 'Create Template' ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Template Preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="previewModalContent"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<form method="POST" id="deleteForm" style="display: none;">
    <?= CSRF::getField() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteId">
</form>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('templateModal');
    <?php if ($editingTemplate): ?>
    new bootstrap.Modal(modal).show();
    <?php endif; ?>
    
    const contentField = document.getElementById('templateContent');
    contentField.addEventListener('input', updateVariables);
    updateVariables();
});

function updateVariables() {
    const content = document.getElementById('templateContent').value;
    const matches = content.match(/\{\{(\w+)\}\}/g) || [];
    const vars = [...new Set(matches.map(m => m.replace(/\{\{|\}\}/g, '')))];
    
    const container = document.getElementById('variableTags');
    if (vars.length === 0) {
        container.innerHTML = '<span class="text-muted small">No variables detected</span>';
    } else {
        container.innerHTML = vars.map(v => `<span class="badge badge-info me-1">{{${v}}}</span>`).join('');
    }
    
    const previewContent = document.getElementById('previewContent');
    if (content.trim()) {
        let preview = content;
        vars.forEach(v => {
            preview = preview.replace(new RegExp('\\{\\{' + v + '\\}\\}', 'g'), `<strong>[${v}]</strong>`);
        });
        previewContent.innerHTML = preview;
    } else {
        previewContent.innerHTML = '<em class="text-muted">Enter message content to see preview...</em>';
    }
}

async function previewTemplate(id) {
    try {
        const response = await fetch('/app/api/template.php?action=get&id=' + id);
        const result = await response.json();
        
        if (result.success) {
            const tmpl = result.data;
            let content = tmpl.content;
            const vars = JSON.parse(tmpl.variables || '[]');
            
            vars.forEach(v => {
                content = content.replace(new RegExp('\\{\\{' + v + '\\}\\}', 'g'), `<strong>[${v}]</strong>`);
            });
            
            document.getElementById('previewModalContent').innerHTML = `
                <h6>${escapeHtml(tmpl.name)}</h6>
                ${tmpl.subject ? `<p class="text-muted mb-2">${escapeHtml(tmpl.subject)}</p>` : ''}
                <div class="p-3 bg-light rounded">${content}</div>
                <div class="mt-3 small text-muted">
                    Category: <span class="badge badge-secondary">${tmpl.category}</span> | 
                    Used: ${tmpl.usage_count} times
                </div>
            `;
            new bootstrap.Modal(document.getElementById('previewModal')).show();
        }
    } catch (err) {
        alert('Failed to load template');
    }
}

function useTemplate(id) {
    window.location.href = '/send?template=' + id;
}

function deleteTemplate(id, name) {
    if (confirm(`Delete template "${name}"?`)) {
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteForm').action = '/app/api/template.php?action=delete';
        document.getElementById('deleteForm').submit();
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}
</script>
<?php
$content = ob_get_clean();
$pageTitle = 'Templates - ' . APP_NAME;
$currentPage = 'templates';

require_once TEMPLATES_PATH . '/base.php';
?>
