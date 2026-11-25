<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

requireRole('admin');

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Display temporary password once after account creation.
$createdUser = $_SESSION['created_user'] ?? null;
unset($_SESSION['created_user']);
 
// Search users.
$search = trim($_GET['search'] ?? '');

// Handle account activation and deactivation.
if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['account_action'])) {

    if (!verifyCsrfToken()) {
        header('Location: index.php?status=invalid_token');
        exit;
    }

    $userId = filter_var(
        $_POST['user_id'] ?? null,
        FILTER_VALIDATE_INT
    );

    $action = $_POST['account_action'];

    if (!$userId || !in_array($action, ['activate', 'deactivate'], true)) {
        header('Location: index.php?status=invalid_action');
        exit;
    }

    // Only employee accounts may be activated or deactivated.
    $check = $pdo->prepare(
        'SELECT user_id, username, role, is_active
         FROM users
         WHERE user_id = ?
         LIMIT 1'
    );

    $check->execute([$userId]);
    $targetUser = $check->fetch();

    if (!$targetUser || $targetUser['role'] !== 'employee') {
        header('Location: index.php?status=not_allowed');
        exit;
    }

    $newStatus = ($action === 'activate') ? 1 : 0;

    if ((int)$targetUser['is_active'] === $newStatus) {
        header('Location: index.php?status=no_change');
        exit;
    }

    try {
        $pdo->beginTransaction();

        $update = $pdo->prepare(
            'UPDATE users
             SET is_active = ?
             WHERE user_id = ?
               AND role = ?'
        );

        $update->execute([
            $newStatus,
            $userId,
            'employee'
        ]);

        $activity = $pdo->prepare(
            'INSERT INTO activity_logs
             (user_id, action, description)
             VALUES (?, ?, ?)'
        );

        $activity->execute([
            (int)$_SESSION['user_id'],
            $action === 'activate' ? 'ACCOUNT_ACTIVATED' : 'ACCOUNT_DEACTIVATED',
            ($action === 'activate' ? 'Activated' : 'Deactivated')
                . ' employee account: ' . $targetUser['username']
        ]);

        $pdo->commit();

        header('Location: index.php?status=' . $action);
        exit;

    } catch (PDOException $e) {

        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        error_log('ODMS account status update error: ' . $e->getMessage());

        header('Location: index.php?status=error');
        exit;
    }
}

// Display the result of account status changes.
$statusMessage = '';
$statusType = 'success';

switch ($_GET['status'] ?? '') {
    case 'activate':
        $statusMessage = 'Employee account activated successfully.';
        break;

    case 'deactivate':
        $statusMessage = 'Employee account deactivated successfully.';
        break;

    case 'invalid_token':
        $statusMessage = 'Your session expired. Please refresh the page and try again.';
        $statusType = 'error';
        break;

    case 'invalid_action':
        $statusMessage = 'Invalid account action.';
        $statusType = 'error';
        break;

    case 'not_allowed':
        $statusMessage = 'Only employee accounts can be activated or deactivated.';
        $statusType = 'error';
        break;

    case 'no_change':
        $statusMessage = 'The account already has that status.';
        $statusType = 'error';
        break;

    case 'error':
        $statusMessage = 'Unable to update the account status. Please try again.';
        $statusType = 'error';
        break;
}

// Search users.
$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT
        user_id,
        full_name,
        username,
        role,
        is_active,
        created_at
    FROM users
";

$params = [];

if ($search !== '') {
    $sql .= "
        WHERE full_name LIKE ?
        OR username LIKE ?
    ";

    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management | ODMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="bg-slate-100 min-h-screen">

<div class="max-w-7xl mx-auto px-4 py-8">

    <div class="flex flex-wrap justify-between items-center gap-4 mb-8">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">
                User Management
            </h1>
            <p class="text-slate-500 mt-1">
                Manage authorized ODMS employee accounts.
            </p>
        </div>

        <div class="flex gap-2">
            <a href="/odms/index.php"
               class="px-4 py-2 rounded-lg bg-slate-700 text-white hover:bg-slate-800">
                Dashboard
            </a>

            <a href="create.php"
               class="px-4 py-2 rounded-lg bg-blue-700 text-white hover:bg-blue-800">
                + Add Employee
            </a>
        </div>
    </div>

    <?php if ($createdUser): ?>

        <div class="mb-6 rounded-xl border border-green-200 bg-green-50 p-5">
            <h2 class="font-bold text-green-800 mb-2">
                Employee account created successfully!
            </h2>

            <p class="text-sm text-green-800 mb-3">
                Give these login details to the employee privately.
                The temporary password will only be shown once.
            </p>

            <div class="bg-white rounded-lg p-4 space-y-2">
                <p class="text-sm text-slate-600">
                    Employee:
                    <strong><?= e($createdUser['full_name']) ?></strong>
                </p>

                <p class="text-sm text-slate-600">
                    Username:
                    <strong><?= e($createdUser['username']) ?></strong>
                </p>

                <p class="text-sm text-slate-600">
                    Temporary Password:
                </p>

                <div class="flex flex-wrap items-center gap-2">
                    <code id="temporaryPassword"
                          class="font-bold text-blue-800 break-all">
                        <?= e($createdUser['temporary_password']) ?>
                    </code>

                    <button type="button"
                            onclick="copyTemporaryPassword()"
                            class="px-3 py-1 rounded bg-blue-700 text-white text-sm">
                        Copy Password
                    </button>
                </div>
            </div>

            <p class="text-xs text-green-800 mt-3">
                For security, do not share this password publicly.
            </p>
        </div>

    <?php endif; ?>

<?php if ($statusMessage !== ''): ?>
    <div class="mb-5 rounded-xl p-4 border
        <?= $statusType === 'success'
            ? 'bg-green-50 border-green-200 text-green-800'
            : 'bg-red-50 border-red-200 text-red-800' ?>">
        <?= e($statusMessage) ?>
    </div>
<?php endif; ?>

<div class="bg-white rounded-xl shadow-sm p-6">

        <div class="flex flex-wrap justify-between items-center gap-3 mb-5">
            <div>
                <h2 class="text-lg font-semibold text-slate-800">
                    Registered Accounts
                </h2>
                <p class="text-sm text-slate-500">
                    Total accounts: <?= count($users) ?>
                </p>
            </div>
        </div>

        <form method="GET" class="flex gap-2 mb-5">
            <input
                type="text"
                name="search"
                value="<?= e($search) ?>"
                placeholder="Search by name or username..."
                class="flex-1 min-w-0 border border-slate-300 rounded-lg px-4 py-2"
            >

            <button type="submit"
                    class="px-4 py-2 rounded-lg bg-slate-800 text-white">
                Search
            </button>

            <?php if ($search !== ''): ?>
                <a href="index.php"
                   class="px-3 py-2 rounded-lg bg-slate-200 text-slate-700">
                    Clear
                </a>
            <?php endif; ?>
        </form>

       <div class="overflow-x-auto">
    <table class="w-full text-sm text-left">

        <thead class="bg-slate-50 text-slate-600">
            <tr>
                <th class="px-4 py-3">Full Name</th>
                <th class="px-4 py-3">Username</th>
                <th class="px-4 py-3">Role</th>
                <th class="px-4 py-3">Account Status</th>
                <th class="px-4 py-3">Date Created</th>
                <th class="px-4 py-3">Action</th>
            </tr>
        </thead>

        <tbody class="divide-y divide-slate-100">

            <?php if (empty($users)): ?>

                <tr>
                    <td colspan="6"
                        class="px-4 py-8 text-center text-slate-500">
                        No user accounts found.
                    </td>
                </tr>

            <?php else: ?>

                <?php foreach ($users as $user): ?>

                    <tr class="hover:bg-slate-50">

                        <td class="px-4 py-4 font-medium text-slate-800">
                            <?= e($user['full_name']) ?>
                        </td>

                        <td class="px-4 py-4 text-slate-600">
                            <?= e($user['username']) ?>
                        </td>

                        <td class="px-4 py-4">
                            <?= e(ucfirst($user['role'])) ?>
                        </td>

                        <td class="px-4 py-4">
                            <?php if ((int)$user['is_active'] === 1): ?>
                                <span class="px-3 py-1 rounded-full bg-green-100 text-green-700 text-xs font-medium">
                                    Active
                                </span>
                            <?php else: ?>
                                <span class="px-3 py-1 rounded-full bg-red-100 text-red-700 text-xs font-medium">
                                    Inactive
                                </span>
                            <?php endif; ?>
                        </td>

                        <td class="px-4 py-4 text-slate-600 whitespace-nowrap">
                            <?= e(date('M d, Y', strtotime($user['created_at']))) ?>
                        </td>

                       <td class="px-4 py-4 whitespace-nowrap">

    <a href="edit.php?id=<?= (int)$user['user_id'] ?>"
       class="text-blue-700 font-medium hover:underline mr-3">
        Edit
    </a>

    <?php if ($user['role'] === 'employee'): ?>

        <a href="reset-password.php?id=<?= (int)$user['user_id'] ?>"
           class="text-red-600 font-medium hover:underline mr-3">
            Reset Password
        </a>

        <form method="POST"
              action="index.php"
              class="inline"
              onsubmit="return confirmAccountAction(this);">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(generateCsrfToken()) ?>"
            >

            <input
                type="hidden"
                name="user_id"
                value="<?= (int)$user['user_id'] ?>"
            >

            <?php if ((int)$user['is_active'] === 1): ?>

                <input
                    type="hidden"
                    name="account_action"
                    value="deactivate"
                >

                <button type="submit"
                        class="text-red-700 font-medium hover:underline">
                    Deactivate
                </button>

            <?php else: ?>

                <input
                    type="hidden"
                    name="account_action"
                    value="activate"
                >

                <button type="submit"
                        class="text-green-700 font-medium hover:underline">
                    Activate
                </button>

            <?php endif; ?>

        </form>

    <?php endif; ?>

</td>

                    </tr>

                <?php endforeach; ?>

            <?php endif; ?>

        </tbody>

    </table>
</div>

    </div>

</div>

<script>
function copyTemporaryPassword() {
    const password = document.getElementById('temporaryPassword').textContent.trim();

    navigator.clipboard.writeText(password).then(function () {
        alert('Temporary password copied successfully!');
    }).catch(function () {
        alert('Unable to copy. Please select and copy the password manually.');
    });
}

function confirmAccountAction(form) {
    const action = form.querySelector(
        'input[name="account_action"]'
    ).value;

    if (action === 'deactivate') {
        return confirm(
            'Are you sure you want to deactivate this employee account? The employee will no longer be able to log in.'
        );
    }

    return confirm(
        'Are you sure you want to activate this employee account?'
    );
}
</script>

</body>
</html>