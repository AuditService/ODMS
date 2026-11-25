<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verifyCsrfToken()) {
    http_response_code(403);
    exit('Invalid logout request.');
}

$userId = (int) $_SESSION['user_id'];

try {
    // Check that the logged-in account still exists.
    $check = $pdo->prepare(
        "SELECT user_id FROM users WHERE user_id = ?"
    );
    $check->execute([$userId]);

    if ($check->fetch()) {
        $log = $pdo->prepare(
            "INSERT INTO activity_logs (user_id, action, description)
             VALUES (?, 'LOGOUT', 'User logged out.')"
        );
        $log->execute([$userId]);
    }
} catch (PDOException $e) {
    // Do not prevent logout if activity logging fails.
    error_log('ODMS logout logging error: ' . $e->getMessage());
}

// Destroy the session regardless of the activity log result.
$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();

header('Location: /odms/login.php');
exit;
