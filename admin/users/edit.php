
<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

requireRole('admin');

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$errors = [];

$userId = (int)($_GET['id'] ?? $_POST['user_id'] ?? 0);

if ($userId <= 0) {
    http_response_code(400);
    exit('Invalid user ID.');
}

// Get the selected account.
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

$fullName = $user['full_name'];
$username = $user['username'];

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

        // Check whether another account already uses this username.
        if (empty($errors)) {

            $check = $pdo->prepare("
                SELECT user_id
                FROM users
                WHERE username = ?
                  AND user_id != ?
                LIMIT 1
            ");

            $check->execute([$username, $userId]);

            if ($check->fetch()) {
                $errors[] = 'That username is already being used by another account.';
            }
        }

        if (empty($errors)) {

            try {
                $update = $pdo->prepare("
                    UPDATE users
                    SET full_name = ?, username = ?
                    WHERE user_id = ?
                ");

                $update->execute([
                    $fullName,
                    $username,
                    $userId
                ]);

                header('Location: index.php?updated=1');
                exit;

            } catch (PDOException $e) {
                error_log($e->getMessage());
                $errors[] = 'Unable to update the account. Please try again.';
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
    <title>Edit User Account | ODMS</title>
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
            Edit User Account
        </h1>

        <p class="text-slate-500 mt-1">
            Update the employee's account information.
        </p>
    </div>

    <?php foreach ($errors as $error): ?>
        <div class="mb-3 p-4 rounded-lg bg-red-100 text-red-700">
            <?= e($error) ?>
        </div>
    <?php endforeach; ?>

    <div class="bg-white rounded-xl shadow-sm p-6">

        <div class="mb-5">
            <p class="text-sm text-slate-500">Account Role</p>
            <p class="font-semibold text-slate-800">
                <?= e(ucfirst($user['role'])) ?>
            </p>
            <p class="text-xs text-slate-500 mt-1">
                Account roles cannot be changed from this page.
            </p>
        </div>

        <form method="POST" action="edit.php?id=<?= $userId ?>">

            <input type="hidden"
                   name="csrf_token"
                   value="<?= e(generateCsrfToken()) ?>">

            <input type="hidden"
                   name="user_id"
                   value="<?= $userId ?>">

            <div class="mb-5">
                <label class="block text-sm font-medium text-slate-700 mb-2">
                    Full Name
                </label>

                <input
                    type="text"
                    name="full_name"
                    value="<?= e($fullName) ?>"
                    maxlength="150"
                    required
                    class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 outline-none"
                >
            </div>

            <div class="mb-6">
                <label class="block text-sm font-medium text-slate-700 mb-2">
                    Username
                </label>

                <input
                    type="text"
                    name="username"
                    value="<?= e($username) ?>"
                    maxlength="80"
                    required
                    class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 outline-none"
                >
            </div>

            <button
                type="submit"
                class="w-full py-3 rounded-lg bg-blue-700 text-white font-medium hover:bg-blue-800"
            >
                Save Changes
            </button>

        </form>

    </div>

</div>

</body>
</html>