<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Login - <?= htmlspecialchars(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif; }
        
        :root {
            --primary: #D97706;
            --primary-dark: #B45309;
            --primary-light: #FEF3C7;
        }
        
        body {
            background: linear-gradient(135deg, #FFFBEB 0%, #FEF3C7 30%, #FDE68A 70%, #FCD34D 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }
        
        .login-container {
            width: 100%;
            max-width: 420px;
        }
        
        .login-card {
            background: #ffffff;
            border-radius: 24px;
            box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15), 0 0 0 1px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        
        .login-header {
            background: linear-gradient(135deg, #D97706 0%, #B45309 100%);
            padding: 40px 32px;
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .login-header::before {
            content: '';
            position: absolute;
            top: -100px;
            right: -100px;
            width: 300px;
            height: 300px;
            background: radial-gradient(circle, rgba(255,255,255,0.2) 0%, transparent 70%);
        }
        
        .login-header::after {
            content: '';
            position: absolute;
            bottom: -80px;
            left: -80px;
            width: 250px;
            height: 250px;
            background: radial-gradient(circle, rgba(255,255,255,0.15) 0%, transparent 70%);
        }
        
        .brand-icon {
            width: 72px;
            height: 72px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 32px;
            color: white;
            margin-bottom: 16px;
            backdrop-filter: blur(10px);
            position: relative;
            z-index: 1;
        }
        
        .login-header h4 {
            font-weight: 700;
            font-size: 24px;
            margin: 0;
            color: white;
            position: relative;
            z-index: 1;
        }
        
        .login-header p {
            font-size: 14px;
            color: rgba(255,255,255,0.85);
            margin: 8px 0 0;
            position: relative;
            z-index: 1;
        }
        
        .login-body {
            padding: 32px;
        }
        
        .form-label {
            font-weight: 500;
            font-size: 13px;
            color: #374151;
            margin-bottom: 6px;
        }
        
        .form-control {
            border: 1.5px solid #E5E7EB;
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 14px;
            transition: all 0.15s ease;
        }
        
        .form-control:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(217, 119, 6, 0.1);
        }
        
        .form-control::placeholder {
            color: #9CA3AF;
        }
        
        .input-icon {
            position: relative;
        }
        
        .input-icon i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: #9CA3AF;
            font-size: 18px;
        }
        
        .input-icon .form-control {
            padding-left: 48px;
        }
        
        .btn-login {
            background: linear-gradient(135deg, #D97706 0%, #B45309 100%);
            border: none;
            border-radius: 12px;
            padding: 14px 24px;
            font-weight: 600;
            font-size: 15px;
            box-shadow: 0 4px 14px rgba(217, 119, 6, 0.35);
            transition: all 0.2s ease;
        }
        
        .btn-login:hover {
            background: linear-gradient(135deg, #B45309 0%, #92400E 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(217, 119, 6, 0.45);
            color: white;
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .alert-danger {
            background: #FEF2F2;
            border: 1px solid #FECACA;
            border-radius: 12px;
            color: #DC2626;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-danger i {
            font-size: 18px;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="brand-icon">
                    <i class="bi bi-sms"></i>
                </div>
                <h4><?= htmlspecialchars(APP_NAME) ?></h4>
                <p>Welcome back! Sign in to continue.</p>
            </div>
            <div class="login-body">
                <?php if (isset($error)): ?>
                <div class="alert alert-danger mb-4">
                    <i class="bi bi-exclamation-circle-fill"></i>
                    <?= htmlspecialchars($error) ?>
                </div>
                <?php endif; ?>
                
                <form method="POST">
                    <?= CSRF::getField() ?>
                    
                    <div class="mb-3">
                        <label class="form-label">Username</label>
                        <div class="input-icon">
                            <i class="bi bi-person"></i>
                            <input type="text" class="form-control" name="username" placeholder="Enter your username" required autofocus>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label class="form-label">Password</label>
                        <div class="input-icon">
                            <i class="bi bi-lock"></i>
                            <input type="password" class="form-control" name="password" placeholder="Enter your password" required>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-login w-100">
                        <i class="bi bi-box-arrow-in-right me-2"></i>
                        Sign In
                    </button>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
