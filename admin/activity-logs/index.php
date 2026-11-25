<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

requireRole('admin');

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Search and filter values.
$search = trim($_GET['search'] ?? '');
$actionFilter = trim($_GET['action'] ?? '');
$dateFrom = trim($_GET['date_from'] ?? '');
$dateTo = trim($_GET['date_to'] ?? '');

// Validate dates.
if (
    $dateFrom !== '' &&
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)
) {
    $dateFrom = '';
}

if (
    $dateTo !== '' &&
    !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)
) {
    $dateTo = '';
}

if ($dateFrom !== '') {
    [$year, $month, $day] = array_map('intval', explode('-', $dateFrom));

    if (!checkdate($month, $day, $year)) {
        $dateFrom = '';
    }
}

if ($dateTo !== '') {
    [$year, $month, $day] = array_map('intval', explode('-', $dateTo));

    if (!checkdate($month, $day, $year)) {
        $dateTo = '';
    }
}

// Get available activity types.
$actionStmt = $pdo->query(
    "SELECT DISTINCT action
     FROM activity_logs
     ORDER BY action ASC"
);

$activityTypes = $actionStmt->fetchAll(PDO::FETCH_COLUMN);

// Build filters.
$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(
        u.full_name LIKE ?
        OR u.username LIKE ?
        OR al.action LIKE ?
        OR al.description LIKE ?
    )";

    $keyword = "%{$search}%";

    $params[] = $keyword;
    $params[] = $keyword;
    $params[] = $keyword;
    $params[] = $keyword;
}

if ($actionFilter !== '') {
    $where[] = "al.action = ?";
    $params[] = $actionFilter;
}

if ($dateFrom !== '') {
    $where[] = "al.created_at >= ?";
    $params[] = $dateFrom . ' 00:00:00';
}

if ($dateTo !== '') {
    $where[] = "al.created_at <= ?";
    $params[] = $dateTo . ' 23:59:59';
}

$whereSql = '';

if (!empty($where)) {
    $whereSql = ' WHERE ' . implode(' AND ', $where);
}

// Count matching records.
$countSql = "
    SELECT COUNT(*)
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.user_id
    $whereSql
";

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);

$totalRecords = (int)$countStmt->fetchColumn();

// Pagination.
$perPage = 15;
$totalPages = max(1, (int)ceil($totalRecords / $perPage));

$currentPage = filter_var(
    $_GET['page'] ?? 1,
    FILTER_VALIDATE_INT
);

$currentPage = max(1, min($currentPage ?: 1, $totalPages));

$offset = ($currentPage - 1) * $perPage;

// Get activity records.
$sql = "
    SELECT
        al.log_id,
        al.user_id,
        al.action,
        al.description,
        al.created_at,
        u.full_name,
        u.username
    FROM activity_logs al
    LEFT JOIN users u ON al.user_id = u.user_id
    $whereSql
    ORDER BY al.created_at DESC, al.log_id DESC
    LIMIT $perPage OFFSET $offset
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

$logs = $stmt->fetchAll();

// Preserve filters when changing pages.
$queryParams = [
    'search' => $search,
    'action' => $actionFilter,
    'date_from' => $dateFrom,
    'date_to' => $dateTo
];

$queryParams = array_filter(
    $queryParams,
    fn($value) => $value !== ''
);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>ODMS - Activity Logs</title>

    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="min-h-screen bg-slate-100">

<div class="min-h-screen flex">

    <!-- SIDEBAR -->
    <aside class="w-64 bg-slate-900 text-white hidden md:flex flex-col">

        <div class="px-6 py-6 border-b border-slate-700">
            <h1 class="text-2xl font-bold tracking-wide">ODMS</h1>

            <p class="text-xs text-slate-400 mt-1">
                Operations Document Monitoring System
            </p>
        </div>

        <nav class="flex-1 px-4 py-6 space-y-2">

            <a href="/odms/index.php"
               class="block text-slate-300 hover:bg-slate-800 px-4 py-3 rounded-lg">
                Dashboard
            </a>

            <a href="/odms/documents/index.php"
               class="block text-slate-300 hover:bg-slate-800 px-4 py-3 rounded-lg">
                Documents
            </a>

            <a href="/odms/upload.php"
               class="block text-slate-300 hover:bg-slate-800 px-4 py-3 rounded-lg">
                Upload Document
            </a>

            <div class="pt-5 pb-2 px-4 text-xs uppercase tracking-wider text-slate-500">
                Administration
            </div>

            <a href="/odms/admin/categories/index.php"
               class="block text-slate-300 hover:bg-slate-800 px-4 py-3 rounded-lg">
                Manage Categories
            </a>

            <a href="/odms/admin/users/index.php"
               class="block text-slate-300 hover:bg-slate-800 px-4 py-3 rounded-lg">
                User Management
            </a>

            <a href="/odms/admin/activity-logs/index.php"
               class="block bg-slate-700 text-white px-4 py-3 rounded-lg">
                Activity Logs
            </a>

        </nav>

        <div class="border-t border-slate-700 p-4">

            <p class="text-sm font-medium">
                <?= e($_SESSION['full_name']) ?>
            </p>

            <p class="text-xs text-slate-400 mt-1">
                Admin Account
            </p>

        </div>

    </aside>

    <!-- MAIN CONTENT -->
    <div class="flex-1 min-w-0">

        <!-- HEADER -->
        <header class="bg-white border-b border-slate-200 px-6 py-5">

            <div class="flex flex-wrap justify-between items-center gap-4">

                <div>
                    <h2 class="text-xl font-bold text-slate-800">
                        Activity Logs
                    </h2>

                    <p class="text-sm text-slate-500 mt-1">
                        Monitor activities performed within ODMS.
                    </p>
                </div>

                <div class="flex items-center gap-4">

                    <div class="text-right hidden sm:block">
                        <p class="text-sm font-semibold text-slate-800">
                            <?= e($_SESSION['full_name']) ?>
                        </p>

                        <p class="text-xs text-slate-500">
                            Administrator
                        </p>
                    </div>

                    <a href="/odms/index.php"
                       class="bg-slate-800 hover:bg-slate-700 text-white px-4 py-2 rounded-lg text-sm">
                        Dashboard
                    </a>

                </div>

            </div>

        </header>

        <!-- PAGE BODY -->
        <main class="p-6">

            <!-- SUMMARY -->
            <div class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 mb-6">

                <p class="text-sm text-slate-500">
                    Matching Activity Records
                </p>

                <p class="text-3xl font-bold text-slate-800 mt-2">
                    <?= number_format($totalRecords) ?>
                </p>

                <p class="text-xs text-slate-400 mt-2">
                    Based on your current search and filters
                </p>

            </div>

            <!-- SEARCH AND FILTERS -->
            <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-6 mb-6">

                <h3 class="text-lg font-bold text-slate-800 mb-4">
                    Search and Filter Activities
                </h3>

                <form method="GET" action="index.php">

                    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-4">

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">
                                Search
                            </label>

                            <input
                                type="text"
                                name="search"
                                value="<?= e($search) ?>"
                                placeholder="Name, username, activity..."
                                class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-slate-500 outline-none"
                            >
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">
                                Activity Type
                            </label>

                            <select
                                name="action"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
                            >

                                <option value="">All Activities</option>

                                <?php foreach ($activityTypes as $type): ?>

                                    <option
                                        value="<?= e($type) ?>"
                                        <?= $actionFilter === $type ? 'selected' : '' ?>
                                    >
                                        <?= e($type) ?>
                                    </option>

                                <?php endforeach; ?>

                            </select>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">
                                Date From
                            </label>

                            <input
                                type="date"
                                name="date_from"
                                value="<?= e($dateFrom) ?>"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
                            >
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-2">
                                Date To
                            </label>

                            <input
                                type="date"
                                name="date_to"
                                value="<?= e($dateTo) ?>"
                                class="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
                            >
                        </div>

                    </div>

                    <div class="flex flex-wrap gap-3 mt-5">

                        <button
                            type="submit"
                            class="bg-slate-800 hover:bg-slate-700 text-white px-5 py-2 rounded-lg text-sm font-medium"
                        >
                            Apply Filters
                        </button>

                        <a
                            href="index.php"
                            class="border border-slate-300 hover:bg-slate-50 text-slate-700 px-5 py-2 rounded-lg text-sm font-medium"
                        >
                            Clear Filters
                        </a>

                    </div>

                </form>

            </section>

            <!-- ACTIVITY TABLE -->
            <section class="bg-white rounded-xl shadow-sm border border-slate-200">

                <div class="p-6 border-b border-slate-200">

                    <h3 class="text-lg font-bold text-slate-800">
                        System Activity Records
                    </h3>

                    <p class="text-sm text-slate-500 mt-1">
                        Latest activities are displayed first.
                    </p>

                </div>

                <div class="overflow-x-auto">

                    <table class="w-full text-sm text-left">

                        <thead class="bg-slate-50 text-slate-500 uppercase text-xs">

                            <tr>
                                <th class="px-6 py-4">User</th>
                                <th class="px-6 py-4">Activity</th>
                                <th class="px-6 py-4">Description</th>
                                <th class="px-6 py-4">Date and Time</th>
                            </tr>

                        </thead>

                        <tbody class="divide-y divide-slate-100">

                            <?php if (count($logs) > 0): ?>

                                <?php foreach ($logs as $log): ?>

                                    <tr class="hover:bg-slate-50">

                                        <td class="px-6 py-4">

                                            <p class="font-medium text-slate-800">
                                                <?= e($log['full_name'] ?? 'Unknown User') ?>
                                            </p>

                                            <p class="text-xs text-slate-400 mt-1">
                                                <?= e($log['username'] ?? 'Account unavailable') ?>
                                            </p>

                                        </td>

                                        <td class="px-6 py-4">

                                            <span class="inline-block bg-blue-50 text-blue-700 px-3 py-1 rounded-full text-xs font-semibold">
                                                <?= e($log['action']) ?>
                                            </span>

                                        </td>

                                        <td class="px-6 py-4 text-slate-600">
                                            <?= e($log['description'] ?? 'No description provided.') ?>
                                        </td>

                                        <td class="px-6 py-4 text-slate-600 whitespace-nowrap">
                                            <?= e(date(
                                                'M d, Y h:i A',
                                                strtotime($log['created_at'])
                                            )) ?>
                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>
                                    <td colspan="4" class="px-6 py-10 text-center text-slate-400">
                                        No activity records found.
                                    </td>
                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

                <!-- PAGINATION -->
                <?php if ($totalPages > 1): ?>

                    <div class="p-6 border-t border-slate-200 flex flex-wrap justify-between items-center gap-3">

                        <p class="text-sm text-slate-500">
                            Page <?= $currentPage ?> of <?= $totalPages ?>
                        </p>

                        <div class="flex gap-2">

                            <?php if ($currentPage > 1): ?>

                                <?php
                                $previousParams = $queryParams;
                                $previousParams['page'] = $currentPage - 1;
                                ?>

                                <a href="?<?= e(http_build_query($previousParams)) ?>"
                                   class="border border-slate-300 px-4 py-2 rounded-lg text-sm hover:bg-slate-50">
                                    Previous
                                </a>

                            <?php endif; ?>

                            <?php if ($currentPage < $totalPages): ?>

                                <?php
                                $nextParams = $queryParams;
                                $nextParams['page'] = $currentPage + 1;
                                ?>

                                <a href="?<?= e(http_build_query($nextParams)) ?>"
                                   class="border border-slate-300 px-4 py-2 rounded-lg text-sm hover:bg-slate-50">
                                    Next
                                </a>

                            <?php endif; ?>

                        </div>

                    </div>

                <?php endif; ?>

            </section>

            <footer class="text-center text-xs text-slate-400 mt-8">
                Operations Document Monitoring System
            </footer>

        </main>

    </div>

</div>

</body>
</html>