<?php
/**
 * Contacts Page - Contact management
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';

$auth = new Auth();
$auth->requireLogin();

$smsService = new SMSService();
$pendingCount = $smsService->getPendingQueueCount();
$contactService = new ContactService();

$groups = $contactService->getAllGroups();
$contacts = $contactService->getAllContacts(['limit' => 100]);
$stats = $contactService->getGroupStats();

ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title">Contacts</h1>
        <p class="page-meta mb-0">Manage your contact list</p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-secondary" data-bs-toggle="modal" data-bs-target="#importModal">
            <i class="bi bi-upload"></i> Import CSV
        </button>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#contactModal">
            <i class="bi bi-plus-lg"></i> Add Contact
        </button>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-md-6">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon blue">
                    <i class="bi bi-people"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format($contactService->getContactCount()) ?></div>
                    <div class="stat-label">Total Contacts</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="stat-card">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon green">
                    <i class="bi bi-collection"></i>
                </div>
                <div>
                    <div class="stat-value"><?= number_format($stats['total_groups']) ?></div>
                    <div class="stat-label">Groups</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3">
            <div class="col-md-4">
                <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($_GET['search'] ?? '') ?>" placeholder="Search by name, phone, email...">
            </div>
            <div class="col-md-3">
                <select class="form-select" name="group_id">
                    <option value="">All Groups</option>
                    <?php foreach ($groups as $group): ?>
                    <option value="<?= $group['id'] ?>" <?= ($_GET['group_id'] ?? '') == $group['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($group['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-search"></i> Search
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Group</th>
                    <th>Company</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($contacts)): ?>
                <tr>
                    <td colspan="6" class="text-center py-5">
                        <div class="empty-state">
                            <i class="bi bi-people"></i>
                            <h5>No contacts yet</h5>
                            <p>Add your first contact to get started</p>
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($contacts as $contact): ?>
                <?php $tags = json_decode($contact['tags'] ?? '[]', true); ?>
                <tr>
                    <td>
                        <div class="fw-medium"><?= htmlspecialchars($contact['name'] ?: '-') ?></div>
                        <?php if (!empty($tags)): ?>
                        <div class="mt-1">
                            <?php foreach (array_slice($tags, 0, 3) as $tag): ?>
                            <span class="badge badge-secondary" style="font-size: 10px;"><?= htmlspecialchars($tag) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td><code><?= htmlspecialchars($contact['phone']) ?></code></td>
                    <td><?= htmlspecialchars($contact['email'] ?: '-') ?></td>
                    <td>
                        <?php if ($contact['group_id'] && $contact['group_name']): ?>
                        <span class="badge" style="background: <?= htmlspecialchars($contact['group_color']) ?>; color: white;">
                            <?= htmlspecialchars($contact['group_name']) ?>
                        </span>
                        <?php else: ?>
                        <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($contact['company'] ?: '-') ?></td>
                    <td>
                        <div class="action-group">
                            <button class="action-btn" onclick="editContact(<?= htmlspecialchars(json_encode($contact)) ?>)" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="action-btn" onclick="sendToContact('<?= htmlspecialchars($contact['phone']) ?>')" title="Send SMS">
                                <i class="bi bi-send"></i>
                            </button>
                            <button class="action-btn danger" onclick="deleteContact(<?= $contact['id'] ?>, '<?= htmlspecialchars($contact['name'] ?: $contact['phone'], ENT_QUOTES) ?>')" title="Delete">
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

<div class="modal fade" id="contactModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="contactModalTitle">Add Contact</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="/app/api/contact.php?action=create" id="contactForm">
                <div class="modal-body">
                    <?= CSRF::getField() ?>
                    <input type="hidden" name="id" id="contactId">
                    
                    <div class="mb-3">
                        <label class="form-label">Phone Number *</label>
                        <input type="text" class="form-control" name="phone" id="contactPhone" required placeholder="+1234567890">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" class="form-control" name="name" id="contactName" placeholder="John Doe">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email" id="contactEmail" placeholder="john@example.com">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Company</label>
                        <input type="text" class="form-control" name="company" id="contactCompany" placeholder="Acme Inc">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Group</label>
                        <select class="form-select" name="group_id" id="contactGroup">
                            <option value="">No Group</option>
                            <?php foreach ($groups as $group): ?>
                            <option value="<?= $group['id'] ?>"><?= htmlspecialchars($group['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tags</label>
                        <input type="text" class="form-control" name="tags" id="contactTags" placeholder="vip, customer (comma separated)">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" id="contactNotes" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> Save Contact
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Import Contacts from CSV</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="/app/api/contact.php?action=import" enctype="multipart/form-data" id="importForm">
                <div class="modal-body">
                    <?= CSRF::getField() ?>
                    <div class="mb-3">
                        <label class="form-label">CSV File</label>
                        <input type="file" class="form-control" name="file" accept=".csv" required>
                        <div class="form-text">
                            Columns: phone, name, email, company, notes, tags, group_id
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-upload"></i> Import
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<form method="POST" id="deleteForm" style="display: none;">
    <?= CSRF::getField() ?>
</form>

<script>
function editContact(contact) {
    document.getElementById('contactModalTitle').textContent = 'Edit Contact';
    document.getElementById('contactId').value = contact.id;
    document.getElementById('contactPhone').value = contact.phone;
    document.getElementById('contactName').value = contact.name || '';
    document.getElementById('contactEmail').value = contact.email || '';
    document.getElementById('contactCompany').value = contact.company || '';
    document.getElementById('contactGroup').value = contact.group_id || '';
    document.getElementById('contactNotes').value = contact.notes || '';
    
    const tags = JSON.parse(contact.tags || '[]');
    document.getElementById('contactTags').value = tags.join(', ');
    
    document.getElementById('contactForm').action = '/app/api/contact.php?action=update';
    new bootstrap.Modal(document.getElementById('contactModal')).show();
}

function sendToContact(phone) {
    window.location.href = '/send?phone=' + encodeURIComponent(phone);
}

function deleteContact(id, name) {
    if (confirm(`Delete contact "${name}"?`)) {
        const form = document.getElementById('deleteForm');
        form.action = '/app/api/contact.php?action=delete';
        form.innerHTML += `<input type="hidden" name="id" value="${id}">`;
        form.submit();
    }
}

document.getElementById('contactModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('contactModalTitle').textContent = 'Add Contact';
    document.getElementById('contactForm').action = '/app/api/contact.php?action=create';
    document.getElementById('contactForm').reset();
});

document.getElementById('contactForm').addEventListener('submit', async function(e) {
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

document.getElementById('importForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const formData = new FormData(this);
    const btn = this.querySelector('button[type="submit"]');
    btn.disabled = true;
    
    try {
        const response = await fetch(this.action, {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            alert(`Imported: ${result.data.imported}\nDuplicates: ${result.data.duplicates}\nErrors: ${result.data.errors}`);
            location.reload();
        } else {
            alert('Error: ' + result.error);
        }
    } finally {
        btn.disabled = false;
    }
});
</script>
<?php
$content = ob_get_clean();
$pageTitle = 'Contacts - ' . APP_NAME;
$currentPage = 'contacts';

require_once TEMPLATES_PATH . '/base.php';
?>
