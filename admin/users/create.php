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

    $passwordCharacters = [
        $uppercase[random_int(0, strlen($uppercase) - 1)],
        $lowercase[random_int(0, strlen($lowercase) - 1)],
        $numbers[random_int(0, strlen($numbers) - 1)],
        $symbols[random_int(0, strlen($symbols) - 1)]
    ];

    for ($i = 0; $i < 12; $i++) {
        $passwordCharacters[] =
            $allCharacters[random_int(0, strlen($allCharacters) - 1)];
    }

    // Securely shuffle the password characters.
    for ($i = count($passwordCharacters) - 1; $i > 0; $i--) {
        $j = random_int(0, $i);

        $temporary = $passwordCharacters[$i];
        $passwordCharacters[$i] = $passwordCharacters[$j];
        $passwordCharacters[$j] = $temporary;
    }

    return implode('', $passwordCharacters);
}

$errors = [];
$fullName = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrfToken()) {
        $errors[] = 'Invalid security token. Please refresh the page and try again.';
    } else {

        $fullName = trim($_POST['full_name'] ?? '');
        $username = trim($_POST['username'] ?? '');

        if ($fullName === '') {
            $errors[] = 'Full name is required.';
        } elseif (strlen($fullName) > 150) {
            $errors[] = 'Full name must not exceed 150 characters.';
        }

        if ($username === '') {
            $errors[] = 'Username is required.';
        } elseif (strlen($username) > 80) {
            $errors[] = 'Username must not exceed 80 characters.';
        } elseif (!preg_match('/^[a-zA-Z0-9._-]+$/', $username)) {
            $errors[] = 'Username may only contain letters, numbers, periods, underscores, and hyphens.';
        }

        // Check whether username already exists.
        if (empty($errors)) {

            $check = $pdo->prepare("
                SELECT user_id
                FROM users
                WHERE username = ?
                LIMIT 1
            ");

            $check->execute([$username]);

            if ($check->fetch()) {
                $errors[] = 'That username is already taken. Please choose another.';
            }
        }

        if (empty($errors)) {

            $temporaryPassword = generateTemporaryPassword();
            $hashedPassword = password_hash(
                $temporaryPassword,
                PASSWORD_DEFAULT
            );

            try {

                $stmt = $pdo->prepare("
                    INSERT INTO users (
                        full_name,
                        username,
                        password,
                        role,
                        is_active,
                        must_change_password
                    )
                    VALUES (?, ?, ?, 'employee', 1, 1)
                ");

                $stmt->execute([
                    $fullName,
                    $username,
                    $hashedPassword
                ]);

                // Keep the temporary password in the session
                // only long enough to show it once to the Admin.
                $_SESSION['created_user'] = [
                    'full_name' => $fullName,
                    'username' => $username,
                    'temporary_password' => $temporaryPassword
                ];

                header('Location: index.php');
                exit;

            } catch (PDOException $e) {

                if ($e->getCode() === '23000') {
                    $errors[] = 'That username is already taken. Please choose another.';
                } else {
                    error_log($e->getMessage());
                    $errors[] = 'Unable to create the account. Please try again.';
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
    <title>Create Employee Account | ODMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-100 min-h-screen">

<div class="max-w-3xl mx-auto px-4 py-8">

    <div class="mb-8">
        <a href="index.php"
           class="text-blue-700 hover:underline text-sm">
            ← Back to User Management
        </a>

        <h1 class="text-2xl font-bold text-slate-800 mt-4">
            Create Employee Account
        </h1>

        <p class="text-slate-500 mt-1">
            Register an authorized employee in ODMS.
        </p>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="mb-3 p-4 rounded-lg bg-red-100 text-red-700">
            <?= e($error) ?>
        </div>
    <?php endforeach; ?>

    <div class="bg-white rounded-xl shadow-sm p-6">

        <form method="POST" action="create.php">

            <input type="hidden"
                   name="csrf_token"
                   value="<?= e(generateCsrfToken()) ?>">

            <div class="mb-5">
                <label class="block text-sm font-medium text-slate-700 mb-2">
                    Employee Full Name
                </label>

                <input
                    type="text"
                    name="full_name"
                    value="<?= e($fullName) ?>"
                    maxlength="150"
                    required
                    placeholder="Enter employee's full name"
                    class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 outline-none"
                >
            </div>

            <div class="mb-5">
                <label class="block text-sm font-medium text-slate-700 mb-2">
                    Username
                </label>

                <input
                    type="text"
                    name="username"
                    value="<?= e($username) ?>"
                    maxlength="80"
                    required
                    placeholder="Create a username"
                    class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 outline-none"
                >

                <p class="text-xs text-slate-500 mt-2">
                    Use letters, numbers, periods, underscores, or hyphens.
                </p>
            </div>

            <div class="mb-6">
                <label class="block text-sm font-medium text-slate-700 mb-2">
                    Account Role
                </label>

                <input
                    type="text"
                    value="Employee"
                    disabled
                    class="w-full border border-slate-200 rounded-lg px-4 py-3 bg-slate-100 text-slate-600"
                >

                <p class="text-xs text-slate-500 mt-2">
                    New accounts created here will automatically receive the Employee role.
                </p>
            </div>

            <div class="bg-blue-50 border border-blue-100 rounded-lg p-4 mb-6">
                <p class="text-sm font-semibold text-blue-900 mb-1">
                    Temporary Password
                </p>

                <p class="text-sm text-blue-800">
                    ODMS will automatically generate a secure temporary password after you create this account. The employee must change it during their first login.
                </p>
            </div>

            <button
                type="submit"
                class="w-full py-3 rounded-lg bg-blue-700 text-white font-medium hover:bg-blue-800"
            >
                Create Employee Account
            </button>

        </form>

    </div>

</div>

</body>
</html>