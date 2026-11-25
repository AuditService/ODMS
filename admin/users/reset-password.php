<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

requireRole('admin');

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Generate a secure temporary password.
function generateTemporaryPassword(): string
{
    $uppercase = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lowercase = 'abcdefghijkmnopqrstuvwxyz';
    $numbers = '23456789';
    $symbols = '!@#$%&*';

    $allCharacters = $uppercase . $lowercase . $numbers . $symbols;

    $characters = [
        $uppercase[random_int(0, strlen($uppercase) - 1)],
        $lowercase[random_int(0, strlen($lowercase) - 1)],
        $numbers[random_int(0, strlen($numbers) - 1)],
        $symbols[random_int(0, strlen($symbols) - 1)]
    ];

    for ($i = 0; $i < 12; $i++) {
        $characters[] = $allCharacters[
            random_int(0, strlen($allCharacters) - 1)
        ];
    }

    // Securely shuffle the characters.
    for ($i = count($characters) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);

        [$characters[$i], $characters[$j]] =
            [$characters[$j], $characters[$i]];
    }

    return implode('', $characters);
}

$errors = [];
$successUser = null;

$userId = (int)($_GET['id'] ?? $_POST['user_id'] ?? 0);

if ($userId <= 0) {
    http_response_code(400);
    exit('Invalid user ID.');
}

// Retrieve the selected employee account.
$stmt = $pdo->prepare("
    SELECT user_id, full_name, username, role, is_active
    FROM users
    WHERE user_id = ?
");

$stmt->execute([$userId]);
$user = $stmt->fetch();

if (!$user) {
    http_response_code(404);
    exit('User account not found.');
}

// This page is intended for employee accounts only.
if ($user['role'] !== 'employee') {
    http_response_code(403);
    exit('Password reset is only available for employee accounts.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrfToken()) {
        $errors[] = 'Invalid security token. Please refresh the page and try again.';
    } elseif ((int)$user['is_active'] !== 1) {
        $errors[] = 'This account is inactive. Activate it before resetting its password.';
    } else {

        $temporaryPassword = generateTemporaryPassword();

        $hashedPassword = password_hash(
            $temporaryPassword,
            PASSWORD_DEFAULT
        );

        try {

            $pdo->beginTransaction();

            // Replace the old password and require a new password at login.
            $update = $pdo->prepare("
                UPDATE users
                SET password = ?,
                    must_change_password = 1
                WHERE user_id = ?
                  AND role = 'employee'
                  AND is_active = 1
            ");

            $update->execute([
                $hashedPassword,
                $userId
            ]);

            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The account could not be updated.');
            }

            // Record the reset in the activity log.
            $log = $pdo->prepare("
                INSERT INTO activity_logs
                    (user_id, action, description)
                VALUES (?, ?, ?)
            ");

            $log->execute([
                $_SESSION['user_id'],
                'PASSWORD_RESET',
                'Reset temporary password for employee: ' . $user['username']
            ]);

            $pdo->commit();

            // Show the temporary password once to the Admin.
            $_SESSION['reset_password_result'] = [
                'full_name' => $user['full_name'],
                'username' => $user['username'],
                'temporary_password' => $temporaryPassword
            ];

            header('Location: reset-password.php?id=' . $userId . '&success=1');
            exit;

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            error_log($e->getMessage());
            $errors[] = 'Unable to reset the password. Please try again.';
        }
    }
}

// Retrieve the one-time result after redirect.
if (isset($_GET['success'])) {
    $successUser = $_SESSION['reset_password_result'] ?? null;
    unset($_SESSION['reset_password_result']);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Employee Password | ODMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-100 min-h-screen">

<div class="max-w-2xl mx-auto px-4 py-8">

    <div class="mb-8">
        <a href="index.php"
           class="text-blue-700 hover:underline text-sm">
            ← Back to User Management
        </a>

        <h1 class="text-2xl font-bold text-slate-800 mt-4">
            Reset Employee Password
        </h1>

        <p class="text-slate-500 mt-1">
            Generate a new temporary password for an employee.
        </p>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="mb-4 p-4 rounded-lg bg-red-100 text-red-700">
            <?= e($error) ?>
        </div>
    <?php endforeach; ?>

    <div class="bg-white rounded-xl shadow-sm p-6">

        <h2 class="text-lg font-semibold text-slate-800 mb-4">
            Employee Account
        </h2>

        <div class="space-y-3 mb-6">

            <div>
                <p class="text-xs text-slate-500">Full Name</p>
                <p class="font-medium text-slate-800">
                    <?= e($user['full_name']) ?>
                </p>
            </div>

            <div>
                <p class="text-xs text-slate-500">Username</p>
                <p class="font-medium text-slate-800">
                    <?= e($user['username']) ?>
                </p>
            </div>

            <div>
                <p class="text-xs text-slate-500">Account Status</p>
                <p class="font-medium text-slate-800">
                    <?= (int)$user['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                </p>
            </div>

        </div>

        <?php if ($successUser): ?>

            <div class="border border-green-200 bg-green-50 rounded-xl p-5">

                <h3 class="font-bold text-green-800 mb-2">
                    Password Reset Successfully!
                </h3>

                <p class="text-sm text-green-800 mb-4">
                    Give this temporary password privately to the employee.
                    It will only be displayed once.
                </p>

                <p class="text-sm text-slate-600 mb-1">
                    New Temporary Password
                </p>

                <div class="flex flex-wrap items-center gap-2">
                    <code id="temporaryPassword"
                          class="text-lg font-bold text-green-800 break-all">
                        <?= e($successUser['temporary_password']) ?>
                    </code>

                    <button type="button"
                            onclick="copyPassword()"
                            class="px-3 py-2 bg-green-700 text-white rounded-lg text-sm">
                        Copy Password
                    </button>
                </div>

                <p class="text-xs text-green-800 mt-4">
                    The employee must change this password at their next login.
                </p>

            </div>

            <a href="index.php"
               class="block text-center mt-5 py-3 rounded-lg bg-slate-700 text-white hover:bg-slate-800">
                Return to User Management
            </a>

        <?php elseif ((int)$user['is_active'] !== 1): ?>

            <div class="bg-red-50 border border-red-200 rounded-lg p-4 text-red-700">
                This employee account is inactive. Activate the account before resetting its password.
            </div>

        <?php else: ?>

            <div class="bg-blue-50 border border-blue-100 rounded-lg p-4 mb-6">

                <p class="font-semibold text-blue-900 mb-1">
                    Important
                </p>

                <p class="text-sm text-blue-800">
                    Resetting the password will immediately replace the employee's current password. The employee will be required to create a new password during their next login.
                </p>

            </div>

            <form method="POST"
                  action="reset-password.php?id=<?= $userId ?>"
                  onsubmit="return confirm('Are you sure you want to reset this employee password?');">

                <input type="hidden"
                       name="csrf_token"
                       value="<?= e(generateCsrfToken()) ?>">

                <input type="hidden"
                       name="user_id"
                       value="<?= $userId ?>">

                <button type="submit"
                        class="w-full py-3 rounded-lg bg-blue-700 text-white font-medium hover:bg-blue-800">
                    Generate New Temporary Password
                </button>

            </form>

        <?php endif; ?>

    </div>

</div>

<script>
function copyPassword() {
    const password = document.getElementById('temporaryPassword').textContent.trim();

    navigator.clipboard.writeText(password).then(function () {
        alert('Temporary password copied successfully!');
    }).catch(function () {
        alert('Unable to copy. Please copy the password manually.');
    });
}
</script>

</body>
</html>