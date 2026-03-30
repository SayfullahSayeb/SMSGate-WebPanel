<?php
/**
 * Groups Page - Contact group management
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';

$auth = new Auth();
$auth->requireLogin();

$smsService = new SMSService();
$pendingCount = $smsService->getPendingQueueCount();
$contactService = new ContactService();

$groups = $contactService->getAllGroups();
$stats = $contactService->getGroupStats();

ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title">Contact Groups</h1>
        <p class="page-meta mb-0">Organize contacts into groups</p>
    </div>
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#groupModal">
        <i class="bi bi-plus-lg"></i> New Group
    </button>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon blue">
                    <i class="bi bi-collection"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format($stats['total_groups']) ?></div>
                    <div class="stat-label">Total Groups</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon green">
                    <i class="bi bi-people"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format($stats['total_contacts']) ?></div>
                    <div class="stat-label">Contacts in Groups</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <?php if (empty($groups)): ?>
    <div class="col-12">
        <div class="card">
            <div class="card-body text-center py-5">
                <div class="empty-state">
                    <i class="bi bi-collection"></i>
                    <h5>No groups yet</h5>
                    <p>Create your first group to organize contacts</p>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <?php foreach ($groups as $group): ?>
    <div class="col-md-6 col-lg-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex align-items-start justify-content-between mb-3">
                    <div class="d-flex align-items-center gap-2">
                        <div class="rounded-circle d-flex align-items-center justify-content-center" style="width: 40px; height: 40px; background: <?= htmlspecialchars($group['color']) ?>; color: white;">
                            <i class="bi bi-people"></i>
                        </div>
                        <div>
                            <h6 class="mb-0"><?= htmlspecialchars($group['name']) ?></h6>
                            <small class="text-muted"><?= number_format($group['contact_count']) ?> contacts</small>
                        </div>
                    </div>
                    <div class="dropdown">
                        <button class="btn btn-ghost btn-sm" data-bs-toggle="dropdown">
                            <i class="bi bi-three-dots-vertical"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="/contacts?group_id=<?= $group['id'] ?>">
                                <i class="bi bi-people me-2"></i> View Contacts
                            </a></li>
                            <li><a class="dropdown-item" href="#" onclick="editGroup(<?= htmlspecialchars(json_encode($group)) ?>)">
                                <i class="bi bi-pencil me-2"></i> Edit
                            </a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="#" onclick="deleteGroup(<?= $group['id'] ?>, '<?= htmlspecialchars($group['name'], ENT_QUOTES) ?>')">
                                <i class="bi bi-trash me-2"></i> Delete
                            </a></li>
                        </ul>
                    </div>
                </div>
                <?php if ($group['description']): ?>
                <p class="text-muted small mb-3"><?= htmlspecialchars($group['description']) ?></p>
                <?php endif; ?>
                <div class="d-flex gap-2">
                    <a href="/send?group=<?= $group['id'] ?>" class="btn btn-primary btn-sm flex-grow-1">
                        <i class="bi bi-send me-1"></i> Send SMS
                    </a>
                    <a href="/contacts?group_id=<?= $group['id'] ?>" class="btn btn-secondary btn-sm">
                        <i class="bi bi-list"></i>
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="modal fade" id="groupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="groupModalTitle">New Group</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="/app/api/group.php?action=create" id="groupForm">
                <div class="modal-body">
                    <?= CSRF::getField() ?>
                    <input type="hidden" name="id" id="groupId">
                    
                    <div class="mb-3">
                        <label class="form-label">Group Name *</label>
                        <input type="text" class="form-control" name="name" id="groupName" required placeholder="e.g., VIP Customers">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" id="groupDescription" rows="2" placeholder="Optional description..."></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Color</label>
                        <div class="d-flex gap-2 flex-wrap">
                            <?php
                            $colors = ['#6B7280', '#EF4444', '#F97316', '#EAB308', '#22C55E', '#14B8A6', '#3B82F6', '#8B5CF6', '#EC4899'];
                            foreach ($colors as $color): ?>
                            <label class="color-option">
                                <input type="radio" name="color" value="<?= $color ?>" <?= $color === '#6B7280' ? 'checked' : '' ?>>
                                <span class="color-swatch" style="background: <?= $color ?>;"></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> Save Group
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.color-option {
    cursor: pointer;
}
.color-option input {
    display: none;
}
.color-swatch {
    display: block;
    width: 32px;
    height: 32px;
    border-radius: 50%;
    border: 2px solid transparent;
    transition: all 0.15s;
}
.color-option input:checked + .color-swatch {
    border-color: #333;
    transform: scale(1.1);
}
</style>

<form method="POST" id="deleteForm" style="display: none;">
    <?= CSRF::getField() ?>
</form>

<script>
function editGroup(group) {
    document.getElementById('groupModalTitle').textContent = 'Edit Group';
    document.getElementById('groupId').value = group.id;
    document.getElementById('groupName').value = group.name;
    document.getElementById('groupDescription').value = group.description || '';
    document.querySelector(`input[name="color"][value="${group.color}"]`).checked = true;
    document.getElementById('groupForm').action = '/app/api/group.php?action=update';
    new bootstrap.Modal(document.getElementById('groupModal')).show();
}

function deleteGroup(id, name) {
    if (confirm(`Delete group "${name}"?\n\nContacts in this group will not be deleted, but will be unassigned.`)) {
        const form = document.getElementById('deleteForm');
        form.action = '/app/api/group.php?action=delete';
        form.innerHTML += `<input type="hidden" name="id" value="${id}">`;
        form.submit();
    }
}

document.getElementById('groupModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('groupModalTitle').textContent = 'New Group';
    document.getElementById('groupForm').action = '/app/api/group.php?action=create';
    document.getElementById('groupForm').reset();
});

document.getElementById('groupForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const response = await fetch(this.action, {
        method: 'POST',
        body: formData
    });
    
    const result = await response.json();
    
    if (result.success) {
        location.reload();
    } else {
        alert('Error: ' + result.error);
    }
});
</script>
<?php
$content = ob_get_clean();
$pageTitle = 'Groups - ' . APP_NAME;
$currentPage = 'groups';

require_once TEMPLATES_PATH . '/base.php';
?>
