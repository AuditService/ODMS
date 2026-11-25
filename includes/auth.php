<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function isLoggedIn(): bool
{
    return isset($_SESSION['user_id']);
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: /odms/login.php');
        exit;
    }

    // Force users to change their temporary password first.
    $currentPage = basename($_SERVER['PHP_SELF'] ?? '');

    $allowedPages = [
        'change-password.php',
        'logout.php'
    ];

    if (
        !empty($_SESSION['must_change_password']) &&
        !in_array($currentPage, $allowedPages, true)
    ) {
        header('Location: /odms/change-password.php');
        exit;
    }
}

function requireRole(string $role): void
{
    requireLogin();

    if ($_SESSION['role'] !== $role) {

        $_SESSION['toast'] = [
    'type' => 'error',
    'message' => 'Access denied. You do not have permission to access this page.'
];

        header('Location: /odms/index.php');
        exit;
    }
}

function generateCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function verifyCsrfToken(): bool
{
    return isset($_POST['csrf_token'], $_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']);
}