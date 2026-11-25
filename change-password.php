<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

// This page is only required for users with temporary passwords.
if (empty($_SESSION['must_change_password'])) {
    header('Location: /odms/index.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrfToken()) {
        $error = 'Your session has expired. Please refresh the page and try again.';
    } else {

        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (
            $currentPassword === '' ||
            $newPassword === '' ||
            $confirmPassword === ''
        ) {
            $error = 'Please complete all password fields.';
        } elseif (strlen($newPassword) < 8) {
            $error = 'Your new password must be at least 8 characters long.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'Your new password and confirmation do not match.';
        } else {

            $stmt = $pdo->prepare(
                'SELECT password
                 FROM users
                 WHERE user_id = ?
                   AND is_active = 1
                 LIMIT 1'
            );

            $stmt->execute([(int) $_SESSION['user_id']]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($currentPassword, $user['password'])) {
                $error = 'Your current temporary password is incorrect.';
            } elseif (password_verify($newPassword, $user['password'])) {
                $error = 'Your new password must be different from your current password.';
            } else {

                $newPasswordHash = password_hash(
                    $newPassword,
                    PASSWORD_DEFAULT
                );

                try {
                    $pdo->beginTransaction();

                    $update = $pdo->prepare(
                        'UPDATE users
                         SET password = ?,
                             must_change_password = 0
                         WHERE user_id = ?'
                    );

                    $update->execute([
                        $newPasswordHash,
                        (int) $_SESSION['user_id']
                    ]);

                    $log = $pdo->prepare(
                        "INSERT INTO activity_logs
                         (user_id, action, description)
                         VALUES (?, 'PASSWORD_CHANGED',
                                 'User changed their password.')"
                    );

                    $log->execute([(int) $_SESSION['user_id']]);

                    $pdo->commit();

                    $_SESSION['must_change_password'] = 0;

                    // Rotate the session ID after the password change.
                    session_regenerate_id(true);

                    header('Location: /odms/index.php?password_changed=1');
                    exit;

                } catch (PDOException $e) {

                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }

                    error_log(
                        'ODMS password change error: ' . $e->getMessage()
                    );

                    $error = 'Unable to update your password right now. Please try again.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Change Password | ODMS</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            font-family: Arial, Helvetica, sans-serif;
            background: linear-gradient(135deg, #0b4677, #031d3b);
            color: #102b4b;
        }

        .password-card {
            width: 100%;
            max-width: 440px;
            padding: 32px;
            background: #ffffff;
            border-radius: 18px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, .25);
        }

        .seal {
            display: block;
            width: 76px;
            height: 76px;
            object-fit: contain;
            margin: 0 auto 12px;
            border-radius: 50%;
        }

        h1 {
            text-align: center;
            color: #082b55;
            font-size: 25px;
            margin-bottom: 8px;
        }

        .subtitle {
            text-align: center;
            color: #64748b;
            font-size: 13px;
            line-height: 1.5;
            margin-bottom: 22px;
        }

        .notice {
            padding: 12px;
            margin-bottom: 18px;
            border-radius: 9px;
            background: #fff8e6;
            border: 1px solid #f2d58a;
            color: #805b0b;
            font-size: 13px;
            line-height: 1.5;
        }

        .error {
            padding: 11px;
            margin-bottom: 15px;
            border-radius: 9px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #b91c1c;
            font-size: 13px;
            line-height: 1.5;
        }

        label {
            display: block;
            margin: 14px 0 7px;
            color: #243b53;
            font-size: 13px;
            font-weight: 700;
        }

        input {
            width: 100%;
            height: 46px;
            padding: 0 12px;
            border: 1px solid #cbd5e1;
            border-radius: 9px;
            outline: none;
            font-size: 14px;
        }

        input:focus {
            border-color: #164d80;
            box-shadow: 0 0 0 3px rgba(22, 77, 128, .10);
        }

        button {
            width: 100%;
            height: 48px;
            margin-top: 22px;
            border: none;
            border-radius: 9px;
            background: linear-gradient(110deg, #0b3767, #06264d);
            color: white;
            font-size: 14px;
            font-weight: 700;
            cursor: pointer;
        }

        button:hover {
            background: #12518a;
        }

        .hint {
            margin-top: 12px;
            color: #64748b;
            font-size: 12px;
            line-height: 1.5;
        }

        @media (max-width: 480px) {
            .password-card {
                padding: 25px 20px;
            }
        }
    </style>
</head>

<body>

<div class="password-card">

    <img
        src="/odms/assets/images/city-of-bais-seal.jpg"
        alt="City of Bais Official Seal"
        class="seal"
    >

    <h1>Change Your Password</h1>

    <p class="subtitle">
        Operations Document Monitoring System<br>
        Internal Audit Service
    </p>

    <div class="notice">
        <strong>First-time login:</strong>
        You are using a temporary password. Please create your own password
        before continuing to ODMS.
    </div>

    <?php if ($error !== ''): ?>
        <div class="error" role="alert">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="/odms/change-password.php">

        <input
            type="hidden"
            name="csrf_token"
            value="<?= htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') ?>"
        >

        <label for="current_password">Temporary / Current Password</label>
        <input
            type="password"
            id="current_password"
            name="current_password"
            autocomplete="current-password"
            required
        >

        <label for="new_password">New Password</label>
        <input
            type="password"
            id="new_password"
            name="new_password"
            minlength="8"
            autocomplete="new-password"
            required
        >

        <label for="confirm_password">Confirm New Password</label>
        <input
            type="password"
            id="confirm_password"
            name="confirm_password"
            minlength="8"
            autocomplete="new-password"
            required
        >

        <p class="hint">
            Your new password must contain at least 8 characters and must
            be different from your temporary password.
        </p>

        <button type="submit">
            Save New Password
        </button>

    </form>

</div>

</body>
</html>