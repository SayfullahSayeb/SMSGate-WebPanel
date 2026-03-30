<?php
/**
 * Devices Page - SMS Gateway device management
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';

$auth = new Auth();
$auth->requireLogin();

$smsService = new SMSService();
$deviceService = new DeviceService();
$pendingCount = $smsService->getPendingQueueCount();

$devices = $deviceService->getAllDevices();
$stats = $deviceService->getDeviceStats();

ob_start();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="page-title">Devices</h1>
        <p class="page-meta mb-0">Manage your SMS Gateway devices</p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-primary" onclick="refreshDevices()">
            <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
        <button class="btn btn-outline-secondary" onclick="viewWebhookLogs()">
            <i class="bi bi-card-list"></i> Logs
        </button>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDeviceModal">
            <i class="bi bi-plus-lg"></i> Add Device
        </button>
    </div>
</div>

<div class="card">
    <div class="table-wrapper">
        <table class="table" id="devicesTable">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Device ID</th>
                    <th>Status</th>
                    <th>Battery</th>
                    <th>Model</th>
                    <th>Manufacturer</th>
                    <th>App Version</th>
                    <th>Last Seen</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($devices)): ?>
                <tr>
                    <td colspan="9" class="text-center py-5">
                        <div class="empty-state">
                            <i class="bi bi-phone"></i>
                            <h5>No devices found</h5>
                            <p>Click "Add Device" to add your first device</p>
                        </div>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($devices as $device): ?>
                <tr data-device-id="<?= htmlspecialchars($device['device_id']) ?>">
                    <td>
                        <span class="device-name" data-value="<?= htmlspecialchars($device['friendly_name'] ?? $device['name']) ?>">
                            <?= htmlspecialchars($device['friendly_name'] ?? $device['name']) ?>
                        </span>
                    </td>
                    <td><code class="small"><?= htmlspecialchars($device['device_id']) ?></code></td>
                    <td>
                        <?php if ($device['status'] === 'online'): ?>
                        <span class="badge bg-success">Online</span>
                        <?php else: ?>
                        <span class="badge bg-secondary">Offline</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($device['battery'] !== null): ?>
                        <?= $device['battery'] ?>%
                        <?php else: ?>
                        <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($device['model'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($device['manufacturer'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($device['app_version'] ?? '-') ?></td>
                    <td>
                        <?php if ($device['last_seen']): ?>
                        <span class="last-seen" data-time="<?= $device['last_seen'] ?>">
                            <?= formatRelativeTime($device['last_seen']) ?>
                        </span>
                        <?php else: ?>
                        <span class="text-muted">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="action-group">
                            <button class="action-btn" onclick="editDeviceName('<?= htmlspecialchars($device['device_id']) ?>', '<?= htmlspecialchars($device['friendly_name'] ?? $device['name']) ?>', '<?= htmlspecialchars($device['api_url'] ?? '') ?>', '<?= htmlspecialchars($device['api_login'] ?? '') ?>', '<?= htmlspecialchars($device['api_password'] ?? '') ?>', '<?= htmlspecialchars($device['webhook_url'] ?? '') ?>')" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button class="action-btn danger" onclick="deleteDevice('<?= htmlspecialchars($device['device_id']) ?>')" title="Delete">
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

<div class="modal fade" id="addDeviceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Add New Device</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label">Device ID *</label>
                    <input type="text" class="form-control" id="addDeviceId" placeholder="Enter device ID from SMS-Gate.app">
                    <div class="form-text">You can find this in SMS-Gate.app → Settings → Device ID</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Friendly Name</label>
                    <input type="text" class="form-control" id="addDeviceName" placeholder="My Phone, Work Phone, etc.">
                </div>
                <div class="mb-3">
                    <label class="form-label">API URL *</label>
                    <input type="text" class="form-control" id="addApiUrl" placeholder="https://api.sms-gate.app/3rdparty/v1">
                    <div class="form-text">Cloud or local URL (e.g., http://192.168.0.195:8080)</div>
                </div>
                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label">Username *</label>
                        <input type="text" class="form-control" id="addApiLogin" placeholder="Username">
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label">Password *</label>
                        <input type="text" class="form-control" id="addApiPassword" placeholder="Password">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Webhook URL</label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="addWebhookUrl" value="<?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . '/app/api/webhook.php' ?>" readonly>
                        <button class="btn btn-outline-secondary" type="button" onclick="copyToClipboard(document.getElementById('addWebhookUrl').value)">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>
                    <div class="form-text">Copy and paste this into SMS-Gate.app webhook settings</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" onclick="testConnection('add')">
                    <i class="bi bi-plug"></i> Test
                </button>
                <span id="addTestResult" class="me-auto"></span>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="addDevice()">Add Device</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editDeviceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Device</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="editDeviceId">
                <div class="mb-3">
                    <label class="form-label">Friendly Name</label>
                    <input type="text" class="form-control" id="editDeviceName" placeholder="My Phone">
                </div>
                <div class="mb-3">
                    <label class="form-label">API URL</label>
                    <input type="text" class="form-control" id="editApiUrl" placeholder="http://192.168.0.195:8080">
                </div>
                <div class="row">
                    <div class="col-6 mb-3">
                        <label class="form-label">Username</label>
                        <input type="text" class="form-control" id="editApiLogin" placeholder="Username">
                    </div>
                    <div class="col-6 mb-3">
                        <label class="form-label">Password</label>
                        <input type="text" class="form-control" id="editApiPassword" placeholder="Password">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Webhook URL</label>
                    <input type="text" class="form-control" id="editWebhookUrl" placeholder="https://your-server.com/app/api/webhook.php">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" onclick="testConnection('edit')">
                    <i class="bi bi-plug"></i> Test
                </button>
                <span id="editTestResult" class="me-auto"></span>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveDevice()">Save Changes</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteDeviceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Delete Device</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to remove this device from the panel?</p>
                <p class="text-muted">This will not delete the device from your SMS-Gate.app account. You can refresh to add it back.</p>
                <input type="hidden" id="deleteDeviceId">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="confirmDeleteDevice()">Delete</button>
            </div>
        </div>
    </div>
</div>

<?php
function formatRelativeTime(string $dateTime): string
{
    $timestamp = strtotime($dateTime);
    $diff = time() - $timestamp;
    
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hour' . (floor($diff / 3600) > 1 ? 's' : '') . ' ago';
    if ($diff < 604800) return floor($diff / 86400) . ' day' . (floor($diff / 86400) > 1 ? 's' : '') . ' ago';
    
    return date('M d, Y', $timestamp);
}
?>

<script>
async function testConnection(type) {
    const resultEl = document.getElementById(type + 'TestResult');
    resultEl.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Testing...';
    
    let apiUrl, apiLogin, apiPassword, webhookUrl;
    
    if (type === 'add') {
        apiUrl = document.getElementById('addApiUrl').value.trim();
        apiLogin = document.getElementById('addApiLogin').value.trim();
        apiPassword = document.getElementById('addApiPassword').value;
        webhookUrl = document.getElementById('addWebhookUrl').value.trim();
        
        if (!apiUrl || !apiLogin || !apiPassword) {
            resultEl.innerHTML = '<span class="text-danger">Fill all fields</span>';
            return;
        }
    } else {
        apiUrl = document.getElementById('editApiUrl').value.trim();
        apiLogin = document.getElementById('editApiLogin').value.trim();
        apiPassword = document.getElementById('editApiPassword').value;
        webhookUrl = document.getElementById('editWebhookUrl').value.trim();
        
        if (!apiUrl || !apiLogin || !apiPassword) {
            resultEl.innerHTML = '<span class="text-danger">Fill all fields</span>';
            return;
        }
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'test_connection');
        formData.append('api_url', apiUrl);
        formData.append('api_login', apiLogin);
        if (apiPassword) {
            formData.append('api_password', apiPassword);
        }
        
        const response = await fetch('/app/api/devices.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            let msg = '<span class="text-success"><i class="bi bi-check-circle"></i> API OK</span>';
            
            if (webhookUrl) {
                msg += ' | <span class="text-info"><i class="bi bi-check-circle"></i> Webhook: ' + webhookUrl + '</span>';
            }
            resultEl.innerHTML = msg;
        } else {
            resultEl.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle"></i> ' + (result.error || 'Failed') + '</span>';
        }
    } catch (err) {
        resultEl.innerHTML = '<span class="text-danger">Network error</span>';
    }
}

async function refreshDevices() {
    const btn = document.querySelector('button[onclick="refreshDevices()"]');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Refreshing...';
    
    try {
        const response = await fetch('/app/api/devices.php?action=refresh');
        const result = await response.json();
        
        if (result.success) {
            showToast('success', result.message);
            setTimeout(() => location.reload(), 500);
        } else {
            showToast('danger', result.error || 'Failed to refresh devices');
        }
    } catch (err) {
        showToast('danger', 'Network error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = originalText;
    }
}

function editDeviceName(deviceId, currentName, apiUrl, apiLogin, apiPassword, webhookUrl) {
    document.getElementById('editDeviceId').value = deviceId;
    document.getElementById('editDeviceName').value = currentName;
    document.getElementById('editApiUrl').value = apiUrl || '';
    document.getElementById('editApiLogin').value = apiLogin || '';
    document.getElementById('editApiPassword').value = apiPassword || '';
    document.getElementById('editWebhookUrl').value = webhookUrl || '';
    document.getElementById('editTestResult').innerHTML = '';
    new bootstrap.Modal(document.getElementById('editDeviceModal')).show();
}

async function saveDevice() {
    const deviceId = document.getElementById('editDeviceId').value;
    const friendlyName = document.getElementById('editDeviceName').value.trim();
    const apiUrl = document.getElementById('editApiUrl').value.trim();
    const apiLogin = document.getElementById('editApiLogin').value.trim();
    const apiPassword = document.getElementById('editApiPassword').value;
    const webhookUrl = document.getElementById('editWebhookUrl').value.trim();
    
    if (!friendlyName) {
        showToast('danger', 'Friendly name is required');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'update_device');
    formData.append('device_id', deviceId);
    formData.append('friendly_name', friendlyName);
    formData.append('api_url', apiUrl);
    formData.append('api_login', apiLogin);
    if (apiPassword) {
        formData.append('api_password', apiPassword);
    }
    formData.append('webhook_url', webhookUrl);
    
    try {
        const response = await fetch('/app/api/devices.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            showToast('success', 'Device updated successfully');
            bootstrap.Modal.getInstance(document.getElementById('editDeviceModal')).hide();
            setTimeout(() => location.reload(), 300);
        } else {
            showToast('danger', result.error || 'Failed to update');
        }
    } catch (err) {
        showToast('danger', 'Network error');
    }
}

function deleteDevice(deviceId) {
    document.getElementById('deleteDeviceId').value = deviceId;
    new bootstrap.Modal(document.getElementById('deleteDeviceModal')).show();
}

async function confirmDeleteDevice() {
    const deviceId = document.getElementById('deleteDeviceId').value;
    
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('device_id', deviceId);
    
    try {
        const response = await fetch('/app/api/devices.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            showToast('success', 'Device removed');
            bootstrap.Modal.getInstance(document.getElementById('deleteDeviceModal')).hide();
            setTimeout(() => location.reload(), 300);
        } else {
            showToast('danger', result.error || 'Failed to delete');
        }
    } catch (err) {
        showToast('danger', 'Network error');
    }
}

async function addDevice() {
    const deviceId = document.getElementById('addDeviceId').value.trim();
    const friendlyName = document.getElementById('addDeviceName').value.trim();
    const apiUrl = document.getElementById('addApiUrl').value.trim();
    const apiLogin = document.getElementById('addApiLogin').value.trim();
    const apiPassword = document.getElementById('addApiPassword').value;
    const webhookUrl = document.getElementById('addWebhookUrl').value.trim();
    
    if (!deviceId) {
        showToast('danger', 'Device ID is required');
        return;
    }
    if (!apiUrl) {
        showToast('danger', 'API URL is required');
        return;
    }
    if (!apiLogin) {
        showToast('danger', 'API Login is required');
        return;
    }
    if (!apiPassword) {
        showToast('danger', 'API Password is required');
        return;
    }
    
    const formData = new FormData();
    formData.append('action', 'add');
    formData.append('device_id', deviceId);
    formData.append('friendly_name', friendlyName || deviceId);
    formData.append('api_url', apiUrl);
    formData.append('api_login', apiLogin);
    formData.append('api_password', apiPassword);
    formData.append('webhook_url', webhookUrl);
    
    try {
        const response = await fetch('/app/api/devices.php', {
            method: 'POST',
            body: formData
        });
        const result = await response.json();
        
        if (result.success) {
            showToast('success', 'Device added successfully');
            bootstrap.Modal.getInstance(document.getElementById('addDeviceModal')).hide();
            setTimeout(() => location.reload(), 500);
        } else {
            showToast('danger', result.error || 'Failed to add device');
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

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        showToast('success', 'Copied!');
    });
}

async function viewWebhookLogs() {
    try {
        const response = await fetch('/app/api/devices.php?action=webhook_logs');
        const result = await response.json();
        
        if (result.success) {
            let html = '<table class="table table-sm table-bordered"><thead><tr><th>Time</th><th>Event</th><th>Payload</th></tr></thead><tbody>';
            
            if (result.data.length === 0) {
                html += '<tr><td colspan="3" class="text-center">No webhooks received yet</td></tr>';
            } else {
                result.data.forEach(log => {
                    html += '<tr><td>' + log.created_at + '</td><td>' + (log.event_type || 'unknown') + '</td><td><pre style="font-size:10px;max-height:200px;overflow:auto;">' + (log.payload || '{}').substring(0, 500) + '</pre></td></tr>';
                });
            }
            
            html += '</tbody></table>';
            
            document.getElementById('webhookLogsBody').innerHTML = html;
            new bootstrap.Modal(document.getElementById('webhookLogsModal')).show();
        }
    } catch (err) {
        showToast('danger', 'Failed to load logs');
    }
}
</script>

<div class="modal fade" id="webhookLogsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Webhook Logs</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="webhookLogsBody">
                Loading...
            </div>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
$pageTitle = 'Devices - ' . APP_NAME;
$currentPage = 'devices';

require_once TEMPLATES_PATH . '/base.php';
