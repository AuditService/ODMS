<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Month names.
$months = [
    1 => 'January',
    2 => 'February',
    3 => 'March',
    4 => 'April',
    5 => 'May',
    6 => 'June',
    7 => 'July',
    8 => 'August',
    9 => 'September',
    10 => 'October',
    11 => 'November',
    12 => 'December'
];

// Read filters.
$search = trim($_GET['search'] ?? '');
$categoryId = filter_var(
    $_GET['category'] ?? '',
    FILTER_VALIDATE_INT
);
$month = filter_var(
    $_GET['month'] ?? '',
    FILTER_VALIDATE_INT
);
$year = filter_var(
    $_GET['year'] ?? '',
    FILTER_VALIDATE_INT
);

// Pagination.
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 10;
$offset = ($page - 1) * $perPage;

// Load categories for the filter.
$categoryStmt = $pdo->query("
    SELECT category_id, category_name
    FROM categories
    ORDER BY category_name ASC
");

$categories = $categoryStmt->fetchAll();

// Load available document years.
$yearStmt = $pdo->query("
    SELECT DISTINCT document_year
    FROM documents
    WHERE deleted_at IS NULL
    ORDER BY document_year DESC
");

$years = $yearStmt->fetchAll();

// Build search conditions.
$where = ["d.deleted_at IS NULL"];
$params = [];

if ($search !== '') {
    $where[] = "(
        d.title LIKE ?
        OR d.date_covered LIKE ?
        OR d.notes LIKE ?
        OR c.category_name LIKE ?
        OR u.full_name LIKE ?
    )";

    $keyword = '%' . $search . '%';

    $params[] = $keyword;
    $params[] = $keyword;
    $params[] = $keyword;
    $params[] = $keyword;
    $params[] = $keyword;
}

if ($categoryId !== false && $categoryId > 0) {
    $where[] = "d.category_id = ?";
    $params[] = $categoryId;
}

if ($month !== false && $month >= 1 && $month <= 12) {
    $where[] = "d.document_month = ?";
    $params[] = $month;
}

if ($year !== false && $year >= 1900 && $year <= 2100) {
    $where[] = "d.document_year = ?";
    $params[] = $year;
}

$whereSql = implode(' AND ', $where);

// Count matching records.
$countStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM documents d
    INNER JOIN categories c
        ON d.category_id = c.category_id
    INNER JOIN users u
        ON d.uploaded_by = u.user_id
    WHERE $whereSql
");

$countStmt->execute($params);

$totalRecords = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRecords / $perPage));

// Get matching documents.
$sql = "
    SELECT
        d.document_id,
        d.title,
        d.document_month,
        d.document_year,
        d.date_covered,
        d.actual_received_date,
        d.received_at,
        c.category_name,
        u.full_name,
        (
            SELECT COUNT(*)
            FROM document_files df
            WHERE df.document_id = d.document_id
        ) AS file_count
    FROM documents d
    INNER JOIN categories c
        ON d.category_id = c.category_id
    INNER JOIN users u
        ON d.uploaded_by = u.user_id
    WHERE $whereSql
    ORDER BY d.received_at DESC
    LIMIT ? OFFSET ?
";

$stmt = $pdo->prepare($sql);

// Bind search and filter parameters.
foreach ($params as $index => $value) {
    $stmt->bindValue($index + 1, $value);
}

// Bind pagination parameters.
$stmt->bindValue(count($params) + 1, $perPage, PDO::PARAM_INT);
$stmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);

$stmt->execute();

$documents = $stmt->fetchAll();

// Preserve filters when changing pages.
$queryFilters = [
    'search' => $search,
    'category' => $categoryId ?: '',
    'month' => $month ?: '',
    'year' => $year ?: ''
];

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Document Records | ODMS</title>

    <script src="https://cdn.tailwindcss.com"></script>
</head>

<body class="min-h-screen bg-slate-100">

<div class="min-h-screen">

    <!-- HEADER -->
    <header class="bg-slate-900 text-white px-6 py-5">
        <div class="max-w-7xl mx-auto flex flex-wrap justify-between items-center gap-4">

            <div>
                <h1 class="text-2xl font-bold">Document Records</h1>
                <p class="text-sm text-slate-300 mt-1">
                    Operations Document Monitoring System
                </p>
            </div>

            <div class="flex gap-3 flex-wrap">

                <a href="/odms/index.php"
                   class="bg-slate-700 hover:bg-slate-600 px-4 py-2 rounded-lg text-sm">
                    Dashboard
                </a>

                <a href="/odms/upload.php"
                   class="bg-blue-600 hover:bg-blue-700 px-4 py-2 rounded-lg text-sm">
                    + Upload Document
                </a>

            </div>

        </div>
    </header>

    <main class="max-w-7xl mx-auto p-5 md:p-7">

        <!-- PAGE INTRODUCTION -->
        <div class="mb-6">

            <h2 class="text-2xl font-bold text-slate-800">
                All Documents
            </h2>

            <p class="text-slate-500 mt-1">
                Search, filter, and monitor received office documents.
            </p>

        </div>

        <!-- FILTERS -->
        <section class="bg-white rounded-xl border border-slate-200 shadow-sm p-5 mb-6">

            <form method="GET" action="index.php">

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4">

                    <div class="lg:col-span-2">

                        <label class="block text-sm font-medium text-slate-700 mb-2">
                            Search Documents
                        </label>

                        <input
                            type="text"
                            name="search"
                            value="<?= e($search) ?>"
                            placeholder="Description, category, date..."
                            class="w-full border border-slate-300 rounded-lg px-3 py-2.5"
                        >

                    </div>

                    <div>

                        <label class="block text-sm font-medium text-slate-700 mb-2">
                            Category
                        </label>

                        <select
                            name="category"
                            class="w-full border border-slate-300 rounded-lg px-3 py-2.5"
                        >

                            <option value="">All Categories</option>

                            <?php foreach ($categories as $category): ?>

                                <option
                                    value="<?= e($category['category_id']) ?>"
                                    <?= (string)$categoryId === (string)$category['category_id'] ? 'selected' : '' ?>
                                >
                                    <?= e($category['category_name']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div>

                        <label class="block text-sm font-medium text-slate-700 mb-2">
                            Month
                        </label>

                        <select
                            name="month"
                            class="w-full border border-slate-300 rounded-lg px-3 py-2.5"
                        >

                            <option value="">All Months</option>

                            <?php foreach ($months as $number => $monthName): ?>

                                <option
                                    value="<?= $number ?>"
                                    <?= (string)$month === (string)$number ? 'selected' : '' ?>
                                >
                                    <?= e($monthName) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                    <div>

                        <label class="block text-sm font-medium text-slate-700 mb-2">
                            Year
                        </label>

                        <select
                            name="year"
                            class="w-full border border-slate-300 rounded-lg px-3 py-2.5"
                        >

                            <option value="">All Years</option>

                            <?php foreach ($years as $availableYear): ?>

                                <option
                                    value="<?= e($availableYear['document_year']) ?>"
                                    <?= (string)$year === (string)$availableYear['document_year'] ? 'selected' : '' ?>
                                >
                                    <?= e($availableYear['document_year']) ?>
                                </option>

                            <?php endforeach; ?>

                        </select>

                    </div>

                </div>

                <div class="flex flex-wrap gap-3 mt-5">

                    <button
                        type="submit"
                        class="bg-slate-900 hover:bg-slate-700 text-white px-5 py-2.5 rounded-lg text-sm font-medium"
                    >
                        Apply Filters
                    </button>

                    <a
                        href="index.php"
                        class="bg-slate-100 hover:bg-slate-200 text-slate-700 px-5 py-2.5 rounded-lg text-sm font-medium"
                    >
                        Clear Filters
                    </a>

                </div>

            </form>

        </section>

        <!-- RECORD COUNT -->
        <div class="mb-4 flex flex-wrap justify-between items-center gap-2">

            <p class="text-sm text-slate-600">
                Found
                <strong><?= number_format($totalRecords) ?></strong>
                document record(s).
            </p>

            <p class="text-xs text-slate-500">
                Showing <?= $totalRecords > 0 ? $offset + 1 : 0 ?>
                to <?= min($offset + $perPage, $totalRecords) ?>
                of <?= number_format($totalRecords) ?>
            </p>

        </div>

        <!-- DOCUMENT TABLE -->
        <section class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">

            <div class="overflow-x-auto">

                <table class="w-full text-sm text-left">

                    <thead class="bg-slate-50 text-slate-600 uppercase text-xs">

                        <tr>
                            <th class="px-5 py-4">Document Description</th>
                            <th class="px-5 py-4">Category</th>
                            <th class="px-5 py-4">Document Period</th>
                            <th class="px-5 py-4">Date Covered</th>
                            <th class="px-5 py-4">Actual Received</th>
                            <th class="px-5 py-4">Uploaded By</th>
                            <th class="px-5 py-4">Photos</th>
                            <th class="px-5 py-4">Recorded At</th>
                        
<th class="px-5 py-4 text-left">Action</th>
                    </thead>

                    <tbody class="divide-y divide-slate-100">

                        <?php if ($documents): ?>

                           <?php foreach ($documents as $document): ?>

    <tr class="hover:bg-slate-50">

        <td class="px-5 py-4 font-medium text-slate-800">
            <?= e($document['title'] ?: 'No description') ?>
        </td>

        <td class="px-5 py-4 text-slate-600">
            <?= e($document['category_name']) ?>
        </td>

        <td class="px-5 py-4 text-slate-600">
            <?= e(
                ($months[(int)$document['document_month']] ?? 'Unknown')
                . ' ' . $document['document_year']
            ) ?>
        </td>

        <td class="px-5 py-4 text-slate-600">
            <?= e($document['date_covered'] ?: 'Not specified') ?>
        </td>

        <td class="px-5 py-4 text-slate-600">
            <?= $document['actual_received_date']
                ? e(date('M d, Y', strtotime($document['actual_received_date'])))
                : 'Not specified' ?>
        </td>

        <td class="px-5 py-4 text-slate-600">
            <?= e($document['full_name']) ?>
        </td>

        <td class="px-5 py-4 text-slate-600">
            <?= number_format((int)$document['file_count']) ?>
        </td>

        <td class="px-5 py-4 text-slate-600 whitespace-nowrap">
            <?= e(date('M d, Y g:i A', strtotime($document['received_at']))) ?>
        </td>

        <td class="px-5 py-4 whitespace-nowrap">
            <a
                href="/odms/documents/view.php?id=<?= (int)$document['document_id'] ?>"
                class="inline-flex items-center px-3 py-2 rounded-lg bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 transition"
            >
                View Details
            </a>
        </td>

    </tr>

<?php endforeach; ?>

                        <?php else: ?>

                            <tr>
                                <td colspan="8" class="px-6 py-12 text-center">

                                    <p class="font-semibold text-slate-600">
                                        No documents found.
                                    </p>

                                    <p class="text-slate-400 text-sm mt-1">
                                        Try changing your search or filters.
                                    </p>

                                </td>
                            </tr>

                        <?php endif; ?>

                    </tbody>

                </table>

            </div>

        </section>

        <!-- PAGINATION -->
        <?php if ($totalPages > 1): ?>

            <div class="flex flex-wrap justify-center items-center gap-2 mt-6">

                <?php if ($page > 1): ?>

                    <a
                        href="?<?= e(http_build_query(array_merge($queryFilters, ['page' => $page - 1]))) ?>"
                        class="px-4 py-2 bg-white border border-slate-300 rounded-lg text-sm hover:bg-slate-50"
                    >
                        Previous
                    </a>

                <?php endif; ?>

                <span class="text-sm text-slate-600 px-3">
                    Page <?= $page ?> of <?= $totalPages ?>
                </span>

                <?php if ($page < $totalPages): ?>

                    <a
                        href="?<?= e(http_build_query(array_merge($queryFilters, ['page' => $page + 1]))) ?>"
                        class="px-4 py-2 bg-white border border-slate-300 rounded-lg text-sm hover:bg-slate-50"
                    >
                        Next
                    </a>

                <?php endif; ?>

            </div>

        <?php endif; ?>

        <footer class="text-center text-xs text-slate-400 mt-8">
            Operations Document Monitoring System
        </footer>

    </main>

</div>

</body>
</html>