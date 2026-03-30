<?php
/**
 * Login Page
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';

$auth = new Auth();
$error = null;

if ($auth->isLoggedIn()) {
    header('Location: /dashboard');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CSRF::validateToken($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid request. Please try again.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($username) || empty($password)) {
            $error = 'Please enter both username and password.';
        } elseif ($auth->login($username, $password)) {
            header('Location: /dashboard');
            exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}

require_once TEMPLATES_PATH . '/login.php';
