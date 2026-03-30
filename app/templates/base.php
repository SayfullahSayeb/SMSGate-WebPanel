<?php
declare(strict_types=1);
$currentPath = $_SERVER['REQUEST_URI'];
$cleanPath = str_replace('/app/', '', parse_url($currentPath, PHP_URL_PATH));
$cleanPath = str_replace('.php', '', $cleanPath);
if ($cleanPath === '/') $cleanPath = '/dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($pageTitle ?? APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #D97706;
            --primary-dark: #B45309;
            --primary-light: #FEF3C7;
            --primary-lighter: #FFFBEB;
            --bg-body: #F1F5F9;
            --bg-card: #FFFFFF;
            --text-primary: #0F172A;
            --text-secondary: #64748B;
            --text-muted: #94A3B8;
            --border-color: #E2E8F0;
            --success: #10B981;
            --success-light: #D1FAE5;
            --warning: #F59E0B;
            --warning-light: #FEF3C7;
            --danger: #EF4444;
            --danger-light: #FEE2E2;
            --info: #3B82F6;
            --info-light: #DBEAFE;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.07), 0 2px 4px -2px rgb(0 0 0 / 0.07);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.08), 0 4px 6px -4px rgb(0 0 0 / 0.08);
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-xl: 20px;
        }

        * { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; }

        body {
            background: var(--bg-body);
            color: var(--text-primary);
            min-height: 100vh;
            font-size: 14px;
            line-height: 1.5;
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            top: 0;
            left: 0;
            height: 100vh;
            width: 260px;
            background: var(--bg-card);
            border-right: 1px solid var(--border-color);
            z-index: 1000;
            display: flex;
            flex-direction: column;
            transition: transform 0.3s ease;
        }

        .sidebar-header {
            padding: 24px;
            border-bottom: 1px solid var(--border-color);
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            text-decoration: none;
        }

        .brand-icon {
            width: 44px;
            height: 44px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 20px;
            box-shadow: 0 4px 12px rgba(217, 119, 6, 0.3);
        }

        .brand-text {
            display: flex;
            flex-direction: column;
        }

        .brand-name {
            font-weight: 700;
            font-size: 16px;
            color: var(--text-primary);
        }

        .brand-tagline {
            font-size: 11px;
            color: var(--text-muted);
        }

        .sidebar-nav {
            flex: 1;
            padding: 16px 12px;
            overflow-y: auto;
        }

        .nav-section {
            margin-bottom: 24px;
        }

        .nav-section-title {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-muted);
            padding: 0 12px;
            margin-bottom: 8px;
        }

        .nav-item {
            margin-bottom: 4px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 12px;
            color: var(--text-secondary);
            text-decoration: none;
            border-radius: var(--radius-md);
            font-weight: 500;
            font-size: 14px;
            transition: all 0.15s ease;
        }

        .nav-link i {
            font-size: 18px;
            width: 20px;
            text-align: center;
            color: var(--text-muted);
            transition: color 0.15s;
        }

        .nav-link:hover {
            background: var(--primary-light);
            color: var(--primary);
        }

        .nav-link:hover i { color: var(--primary); }

        .nav-link.active {
            background: var(--primary-light);
            color: var(--primary);
            font-weight: 600;
        }

        .nav-link.active i { color: var(--primary); }

        .nav-badge {
            margin-left: auto;
            background: var(--primary);
            color: white;
            font-size: 11px;
            font-weight: 600;
            padding: 2px 8px;
            border-radius: 10px;
        }

        .sidebar-footer {
            padding: 16px;
            border-top: 1px solid var(--border-color);
        }

        .user-card {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px;
            background: var(--bg-body);
            border-radius: var(--radius-md);
        }

        .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 14px;
        }

        .user-info { flex: 1; min-width: 0; }

        .user-name {
            font-weight: 600;
            font-size: 13px;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .user-role {
            font-size: 11px;
            color: var(--text-muted);
        }

        .user-logout {
            color: var(--text-muted);
            padding: 6px;
            border-radius: var(--radius-sm);
            transition: all 0.15s;
        }

        .user-logout:hover {
            background: var(--danger-light);
            color: var(--danger);
        }

        /* Main Content */
        .main-wrapper {
            margin-left: 260px;
            min-height: 100vh;
        }

        .main-header {
            background: var(--bg-card);
            border-bottom: 1px solid var(--border-color);
            padding: 16px 32px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .page-title {
            font-size: 20px;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0;
        }

        .page-meta {
            font-size: 13px;
            color: var(--text-muted);
        }

        .main-content {
            padding: 32px;
        }

        /* Cards */
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
        }

        .card-header {
            background: transparent;
            border-bottom: 1px solid var(--border-color);
            padding: 20px 24px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card-header h5 {
            font-size: 15px;
            font-weight: 600;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .card-header h5 i { color: var(--primary); }

        .card-body { padding: 24px; }

        /* Stat Cards */
        .stat-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 20px;
            transition: all 0.2s ease;
            position: relative;
            overflow: hidden;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 4px;
            height: 100%;
            background: linear-gradient(180deg, var(--primary) 0%, #FB923C 100%);
        }

        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
        }

        .stat-icon {
            width: 48px;
            height: 48px;
            border-radius: var(--radius-md);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }

        .stat-icon.orange {
            background: var(--primary-light);
            color: var(--primary);
        }

        .stat-icon.green {
            background: var(--success-light);
            color: var(--success);
        }

        .stat-icon.blue {
            background: var(--info-light);
            color: var(--info);
        }

        .stat-icon.red {
            background: var(--danger-light);
            color: var(--danger);
        }

        .stat-icon.yellow {
            background: var(--warning-light);
            color: var(--warning);
        }

        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: var(--text-primary);
            line-height: 1.2;
        }

        .stat-label {
            font-size: 13px;
            color: var(--text-secondary);
            font-weight: 500;
        }

        /* Buttons */
        .btn {
            font-weight: 500;
            font-size: 13px;
            padding: 8px 16px;
            border-radius: var(--radius-sm);
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.15s ease;
        }

        .btn i { font-size: 16px; }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border: none;
            color: white;
            box-shadow: 0 2px 8px rgba(217, 119, 6, 0.25);
        }

        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, #C2410C 100%);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(217, 119, 6, 0.35);
            color: white;
        }

        .btn-outline-primary {
            color: var(--primary);
            border: 1.5px solid var(--primary);
            background: transparent;
        }

        .btn-outline-primary:hover {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        .btn-secondary {
            background: var(--bg-body);
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
        }

        .btn-secondary:hover {
            background: var(--border-color);
            color: var(--text-primary);
        }

        .btn-ghost {
            background: transparent;
            border: none;
            color: var(--text-secondary);
            padding: 6px 10px;
        }

        .btn-ghost:hover {
            background: var(--bg-body);
            color: var(--text-primary);
        }

        .btn-danger {
            background: var(--danger);
            border: none;
            color: white;
        }

        .btn-danger:hover {
            background: #DC2626;
            color: white;
        }

        .btn-sm { padding: 6px 12px; font-size: 12px; }

        /* Forms */
        .form-label {
            font-weight: 500;
            font-size: 13px;
            color: var(--text-primary);
            margin-bottom: 6px;
        }

        .form-control, .form-select {
            border: 1.5px solid var(--border-color);
            border-radius: var(--radius-sm);
            padding: 10px 14px;
            font-size: 14px;
            transition: all 0.15s ease;
            background: var(--bg-card);
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(217, 119, 6, 0.1);
        }

        .form-control::placeholder { color: var(--text-muted); }

        textarea.form-control { resize: vertical; min-height: 100px; }

        /* Tables */
        .table-wrapper {
            border-radius: var(--radius-md);
            overflow: hidden;
            border: 1px solid var(--border-color);
        }

        .table {
            margin: 0;
            font-size: 13px;
        }

        .table thead {
            background: var(--bg-body);
        }

        .table thead th {
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--text-secondary);
            padding: 14px 16px;
            border-bottom: 1px solid var(--border-color);
            white-space: nowrap;
        }

        .table tbody td {
            padding: 14px 16px;
            vertical-align: middle;
            border-bottom: 1px solid var(--border-color);
        }

        .table tbody tr:last-child td { border-bottom: none; }

        .table tbody tr:hover { background: var(--primary-light); }

        /* Badges */
        .badge {
            font-weight: 600;
            font-size: 11px;
            padding: 4px 10px;
            border-radius: 6px;
        }

        .badge-success {
            background: var(--success-light);
            color: #059669;
        }

        .badge-warning {
            background: var(--warning-light);
            color: #B45309;
        }

        .badge-danger {
            background: var(--danger-light);
            color: #DC2626;
        }

        .badge-info {
            background: var(--info-light);
            color: #2563EB;
        }

        .badge-secondary {
            background: var(--bg-body);
            color: var(--text-secondary);
        }

        /* Alerts */
        .alert {
            border: none;
            border-radius: var(--radius-md);
            padding: 14px 18px;
            font-size: 14px;
            display: flex;
            align-items: flex-start;
            gap: 12px;
        }

        .alert i { font-size: 18px; margin-top: 2px; }

        .alert-success {
            background: var(--success-light);
            color: #065F46;
        }

        .alert-danger {
            background: var(--danger-light);
            color: #991B1B;
        }

        .alert-warning {
            background: var(--warning-light);
            color: #92400E;
        }

        .alert-info {
            background: var(--info-light);
            color: #1E40AF;
        }

        .alert-flash {
            position: fixed;
            top: 24px;
            right: 24px;
            z-index: 1100;
            animation: slideIn 0.3s ease;
            max-width: 400px;
            box-shadow: var(--shadow-lg);
        }

        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        /* Progress Bars */
        .progress-wrapper {
            margin-bottom: 16px;
        }

        .progress-header {
            display: flex;
            justify-content: space-between;
            margin-bottom: 6px;
        }

        .progress-label {
            font-size: 13px;
            font-weight: 500;
            color: var(--text-secondary);
            text-transform: capitalize;
        }

        .progress-value {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-primary);
        }

        .progress {
            height: 8px;
            border-radius: 4px;
            background: var(--bg-body);
            overflow: hidden;
        }

        .progress-bar {
            height: 100%;
            border-radius: 4px;
            background: linear-gradient(90deg, var(--primary) 0%, #FB923C 100%);
            transition: width 0.4s ease;
        }

        .progress-bar.danger {
            background: linear-gradient(90deg, var(--danger) 0%, #F87171 100%);
        }

        .progress-bar.warning {
            background: linear-gradient(90deg, var(--warning) 0%, #FBBF24 100%);
        }

        /* Status Dots */
        .status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            flex-shrink: 0;
        }
        
        .status-dot.online {
            background: var(--success);
            box-shadow: 0 0 6px var(--success);
            animation: pulse-online 2s infinite;
        }
        
        .status-dot.offline {
            background: var(--danger);
            animation: pulse-offline 1s infinite;
        }
        
        @keyframes pulse-online {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.6; }
        }
        
        @keyframes pulse-offline {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.4; }
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 48px 24px;
            color: var(--text-muted);
        }

        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.5;
        }

        .empty-state h5 {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 8px;
        }

        .empty-state p { font-size: 14px; }

        /* Checkbox */
        .form-check-input {
            width: 18px;
            height: 18px;
            border-radius: 4px;
            border: 1.5px solid var(--border-color);
        }

        .form-check-input:checked {
            background-color: var(--primary);
            border-color: var(--primary);
        }

        .form-check-input:focus {
            box-shadow: 0 0 0 3px rgba(217, 119, 6, 0.1);
        }

        /* Action Buttons Group */
        .action-group {
            display: flex;
            gap: 6px;
        }

        .action-btn {
            width: 32px;
            height: 32px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border-color);
            background: var(--bg-card);
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.15s;
        }

        .action-btn:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .action-btn.danger:hover {
            border-color: var(--danger);
            background: var(--danger-light);
            color: var(--danger);
        }

        /* SIM Selector */
        .sim-selector {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
        }

        .sim-option {
            position: relative;
        }

        .sim-option input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .sim-option label {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 12px;
            border: 2px solid var(--border-color);
            border-radius: var(--radius-md);
            cursor: pointer;
            transition: all 0.15s;
        }

        .sim-option label:hover {
            border-color: var(--primary);
            background: var(--primary-light);
        }

        .sim-option input:checked + label {
            border-color: var(--primary);
            background: var(--primary-light);
        }

        .sim-option .sim-name {
            font-weight: 600;
            font-size: 14px;
            color: var(--text-primary);
        }

        .sim-option .sim-desc {
            font-size: 11px;
            color: var(--text-muted);
        }

        /* Quick Send Form */
        .quick-send-card {
            background: linear-gradient(135deg, var(--bg-card) 0%, var(--primary-light) 100%);
            border: 1px solid var(--primary-lighter);
        }

        /* Character Counter */
        .char-counter {
            display: flex;
            justify-content: space-between;
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 6px;
        }

        /* Pagination */
        .pagination {
            gap: 4px;
        }

        .page-link {
            border: none;
            border-radius: var(--radius-sm);
            padding: 8px 12px;
            font-size: 13px;
            color: var(--text-secondary);
        }

        .page-link:hover {
            background: var(--primary-light);
            color: var(--primary);
        }

        .page-item.active .page-link {
            background: var(--primary);
            color: white;
        }

        /* Filter Pills */
        .filter-pills {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .filter-pill {
            padding: 6px 14px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-secondary);
            background: var(--bg-body);
            border: 1px solid var(--border-color);
            text-decoration: none;
            transition: all 0.15s;
        }

        .filter-pill:hover {
            border-color: var(--primary);
            color: var(--primary);
        }

        .filter-pill.active {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        /* Mobile Toggle */
        .mobile-toggle {
            display: none;
            position: fixed;
            top: 16px;
            left: 16px;
            z-index: 1001;
            width: 44px;
            height: 44px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            align-items: center;
            justify-content: center;
            font-size: 20px;
            cursor: pointer;
            box-shadow: var(--shadow-md);
        }

        @media (max-width: 991px) {
            .sidebar {
                transform: translateX(-100%);
            }
            .sidebar.show {
                transform: translateX(0);
            }
            .main-wrapper {
                margin-left: 0;
            }
            .mobile-toggle {
                display: flex;
            }
            .main-content {
                padding: 80px 16px 32px;
            }
        }

        /* Code */
        code {
            background: var(--bg-body);
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 12px;
            color: var(--primary-dark);
        }
    </style>
</head>
<body>
    <button class="mobile-toggle" onclick="document.getElementById('sidebar').classList.toggle('show')">
        <i class="bi bi-list"></i>
    </button>

    <nav class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="/dashboard" class="brand">
                <div class="brand-icon">
                    <i class="bi bi-sms-fill"></i>
                </div>
                <div class="brand-text">
                    <span class="brand-name">SMS Panel</span>
                </div>
            </a>
        </div>
        
        <div class="sidebar-nav">
            <div class="nav-item">
                <a href="/dashboard" class="nav-link <?= ($currentPage ?? '') === 'dashboard' ? 'active' : '' ?>">
                    <i class="bi bi-grid-1x2"></i>
                    Dashboard
                </a>
            </div>
            <div class="nav-item">
                <a href="/send" class="nav-link <?= ($currentPage ?? '') === 'send' ? 'active' : '' ?>">
                    <i class="bi bi-send"></i>
                    Send SMS
                </a>
            </div>
            <div class="nav-item">
                <a href="/queue" class="nav-link <?= ($currentPage ?? '') === 'queue' ? 'active' : '' ?>">
                    <i class="bi bi-list-check"></i>
                    Queue
                    <?php if (isset($pendingCount) && $pendingCount > 0): ?>
                        <span class="nav-badge"><?= $pendingCount ?></span>
                    <?php endif; ?>
                </a>
            </div>
            <div class="nav-item">
                <a href="/history" class="nav-link <?= ($currentPage ?? '') === 'history' ? 'active' : '' ?>">
                    <i class="bi bi-clock-history"></i>
                    History
                </a>
            </div>
            <div class="nav-item">
                <a href="/automation" class="nav-link <?= ($currentPage ?? '') === 'automation' ? 'active' : '' ?>">
                    <i class="bi bi-lightning-charge-fill"></i>
                    Automation
                </a>
            </div>
            <div class="nav-item">
                <a href="/contacts" class="nav-link <?= ($currentPage ?? '') === 'contacts' ? 'active' : '' ?>">
                    <i class="bi bi-person-lines-fill"></i>
                    Contacts
                </a>
            </div>
            <div class="nav-item">
                <a href="/groups" class="nav-link <?= ($currentPage ?? '') === 'groups' ? 'active' : '' ?>">
                    <i class="bi bi-people-fill"></i>
                    Groups
                </a>
            </div>
            <div class="nav-item">
                <a href="/devices" class="nav-link <?= ($currentPage ?? '') === 'devices' ? 'active' : '' ?>">
                    <i class="bi bi-phone-fill"></i>
                    Devices
                </a>
            </div>
            <div class="nav-item">
                <a href="/settings" class="nav-link <?= ($currentPage ?? '') === 'settings' ? 'active' : '' ?>">
                    <i class="bi bi-gear-fill"></i>
                    Settings
                </a>
            </div>
        </div>
        
        <div class="sidebar-footer">
            <?php
            $sidebarAuth = new Auth();
            $sidebarUser = $sidebarAuth->getCurrentUser();
            $displayName = $sidebarUser ? htmlspecialchars($sidebarUser['username']) : 'User';
            $userInitials = $sidebarUser ? strtoupper(substr($sidebarUser['username'], 0, 2)) : 'US';
            ?>
            <div class="user-card">
                <div class="user-avatar">
                    <?= $userInitials ?>
                </div>
                <div class="user-info">
                    <div class="user-name"><?= $displayName ?></div>
                    <div class="user-role">Admin</div>
                </div>
                <a href="/logout" class="user-logout" title="Logout">
                    <i class="bi bi-box-arrow-right"></i>
                </a>
            </div>
        </div>
    </nav>

    <div class="main-wrapper">
        <?php if (isset($_SESSION['flash_message'])): ?>
            <div class="alert alert-<?= htmlspecialchars($_SESSION['flash_type'] ?? 'info') ?> alert-dismissible fade show alert-flash" role="alert">
                <i class="bi bi-<?= match($_SESSION['flash_type'] ?? 'info') { 'success' => 'check-circle-fill', 'danger' => 'exclamation-circle-fill', 'warning' => 'exclamation-triangle-fill', default => 'info-fill' } ?>"></i>
                <div><?= htmlspecialchars($_SESSION['flash_message']) ?></div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['flash_message'], $_SESSION['flash_type']); ?>
        <?php endif; ?>

        <div class="main-content">
            <?= $content ?? '' ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        setTimeout(() => {
            document.querySelectorAll('.alert-flash').forEach(el => new bootstrap.Alert(el).close());
        }, 5000);

        document.addEventListener('DOMContentLoaded', () => {
            document.querySelectorAll('.table tbody tr').forEach(row => row.style.cursor = 'pointer');
        });

        document.addEventListener('click', (e) => {
            if (e.target.closest('.sidebar') || e.target.closest('.mobile-toggle')) return;
            document.getElementById('sidebar')?.classList.remove('show');
        });
    </script>
</body>
</html>
