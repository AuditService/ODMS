<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

requireRole('admin');

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$errors = [];
$success = '';

$editCategory = null;

// Handle adding, updating, or deleting a category.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!verifyCsrfToken()) {
        $errors[] = 'Invalid security token. Please refresh the page and try again.';
    } else {

        $action = $_POST['action'] ?? 'save';
        $categoryId = (int)($_POST['category_id'] ?? 0);

        // DELETE CATEGORY
        if ($action === 'delete') {

            if ($categoryId <= 0) {
                $errors[] = 'Invalid category selected.';
            } else {

                // Prevent deletion if documents are assigned to this category.
                $check = $pdo->prepare("
                    SELECT COUNT(*)
                    FROM documents
                    WHERE category_id = ?
                ");

                $check->execute([$categoryId]);
                $documentCount = (int)$check->fetchColumn();

                if ($documentCount > 0) {
                    $errors[] = 'This category cannot be deleted because it is being used by existing documents.';
                } else {

                    $stmt = $pdo->prepare("
                        DELETE FROM categories
                        WHERE category_id = ?
                    ");

                    $stmt->execute([$categoryId]);

                    header('Location: index.php?deleted=1');
                    exit;
                }
            }

        } else {

            // ADD OR UPDATE CATEGORY
            $categoryName = trim($_POST['category_name'] ?? '');
            $description = trim($_POST['description'] ?? '');

            if ($categoryName === '') {
                $errors[] = 'Category name is required.';
            } elseif (strlen($categoryName) > 100) {
                $errors[] = 'Category name must not exceed 100 characters.';
            }

            // Check for duplicate category names.
            if (empty($errors)) {

                $checkSql = "
                    SELECT category_id
                    FROM categories
                    WHERE category_name = ?
                ";

                $checkParams = [$categoryName];

                if ($categoryId > 0) {
                    $checkSql .= " AND category_id != ?";
                    $checkParams[] = $categoryId;
                }

                $check = $pdo->prepare($checkSql);
                $check->execute($checkParams);

                if ($check->fetch()) {
                    $errors[] = 'This category already exists.';
                }
            }

            if (empty($errors)) {

                if ($categoryId > 0) {

                    $stmt = $pdo->prepare("
                        UPDATE categories
                        SET category_name = ?, description = ?
                        WHERE category_id = ?
                    ");

                    $stmt->execute([
                        $categoryName,
                        $description !== '' ? $description : null,
                        $categoryId
                    ]);

                    header('Location: index.php?updated=1');
                    exit;

                } else {

                    $stmt = $pdo->prepare("
                        INSERT INTO categories (category_name, description)
                        VALUES (?, ?)
                    ");

                    $stmt->execute([
                        $categoryName,
                        $description !== '' ? $description : null
                    ]);

                    header('Location: index.php?added=1');
                    exit;
                }
            }
        }
    }
}

// Display success messages.
if (isset($_GET['added'])) {
    $success = 'Category added successfully.';
} elseif (isset($_GET['updated'])) {
    $success = 'Category updated successfully.';
} elseif (isset($_GET['deleted'])) {
    $success = 'Category deleted successfully.';
}

// Get category selected for editing.
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];

    if ($editId > 0) {
        $stmt = $pdo->prepare("
            SELECT category_id, category_name, description
            FROM categories
            WHERE category_id = ?
        ");

        $stmt->execute([$editId]);
        $editCategory = $stmt->fetch();

        if (!$editCategory) {
            $errors[] = 'Category not found.';
        }
    }
}

// Preserve submitted values if validation fails.
$formName = $_POST['category_name'] ?? ($editCategory['category_name'] ?? '');
$formDescription = $_POST['description'] ?? ($editCategory['description'] ?? '');
$formId = (int)($_POST['category_id'] ?? ($editCategory['category_id'] ?? 0));

// Search categories.
$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT category_id, category_name, description
    FROM categories
";

$params = [];

if ($search !== '') {
    $sql .= "
        WHERE category_name LIKE ?
        OR description LIKE ?
    ";

    $params[] = "%$search%";
    $params[] = "%$search%";
}

$sql .= " ORDER BY category_name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$categories = $stmt->fetchAll();

$totalCategories = count($categories);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Category Management | ODMS</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <style>
    .odms-toast {
        position: relative;
        display: flex;
        align-items: flex-start;
        gap: 13px;
        padding: 17px 42px 20px 16px;
        background: #ffffff;
        border-radius: 14px;
        box-shadow: 0 10px 35px rgba(15, 23, 42, 0.16);
        overflow: hidden;
        animation: toastSlideIn 0.4s ease forwards;
        transition: opacity 0.4s ease, transform 0.4s ease;
    }

    .success-toast {
        border-left: 5px solid #16a34a;
    }

    .error-toast {
        border-left: 5px solid #dc2626;
    }

    .toast-icon {
        width: 38px;
        height: 38px;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
    }

    .success-icon {
        background: #dcfce7;
        color: #15803d;
    }

    .error-icon {
        background: #fee2e2;
        color: #b91c1c;
    }

    .toast-content {
        flex: 1;
        min-width: 0;
        padding-top: 1px;
    }

    .toast-content strong {
        display: block;
        color: #1e293b;
        font-size: 14px;
        font-weight: 700;
        margin-bottom: 4px;
    }

    .toast-content p {
        color: #64748b;
        font-size: 13px;
        line-height: 1.5;
        overflow-wrap: anywhere;
    }

    .toast-close {
        position: absolute;
        top: 10px;
        right: 12px;
        color: #94a3b8;
        font-size: 24px;
        line-height: 1;
        cursor: pointer;
        border: none;
        background: transparent;
    }

    .toast-close:hover {
        color: #334155;
    }

    .toast-progress {
        position: absolute;
        bottom: 0;
        left: 0;
        height: 4px;
        width: 100%;
        transform-origin: left;
        animation: toastProgress 4s linear forwards;
    }

    .success-progress {
        background: #16a34a;
    }

    .error-progress {
        background: #dc2626;
    }

    .toast-hiding {
        opacity: 0;
        transform: translateX(30px);
    }

    @keyframes toastSlideIn {
        from {
            opacity: 0;
            transform: translateX(35px);
        }
        to {
            opacity: 1;
            transform: translateX(0);
        }
    }

    @keyframes toastProgress {
        from {
            transform: scaleX(1);
        }
        to {
            transform: scaleX(0);
        }
    }

    @media (max-width: 480px) {
        #toastContainer {
            top: 12px;
            right: 12px;
            width: calc(100% - 24px);
        }
    }
</style>
</head>

<body class="bg-slate-100 min-h-screen">

<div class="max-w-7xl mx-auto px-4 py-8">

    <div class="flex flex-wrap justify-between items-center gap-4 mb-8">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">
                Category Management
            </h1>
            <p class="text-slate-500 mt-1">
                Manage document categories in the ODMS.
            </p>
        </div>

        <a href="/odms/index.php"
           class="px-4 py-2 rounded-lg bg-slate-700 text-white hover:bg-slate-800">
            Back to Dashboard
        </a>
    </div>

    <!-- TOAST NOTIFICATIONS -->

<div id="toastContainer"
     class="fixed top-5 right-5 z-50 flex flex-col gap-3 w-[calc(100%-2rem)] max-w-sm">

    <?php if ($success): ?>

        <div class="odms-toast success-toast"
             role="status">

            <div class="toast-icon success-icon">
                <svg xmlns="http://www.w3.org/2000/svg"
                     width="24" height="24"
                     viewBox="0 0 24 24"
                     fill="none" stroke="currentColor"
                     stroke-width="2.5"
                     stroke-linecap="round"
                     stroke-linejoin="round">
                    <path d="M20 6 9 17l-5-5"/>
                </svg>
            </div>

            <div class="toast-content">
                <strong>Success!</strong>
                <p><?= e($success) ?></p>
            </div>

            <button type="button"
                    class="toast-close"
                    aria-label="Close notification">
                &times;
            </button>

            <div class="toast-progress success-progress"></div>

        </div>

    <?php endif; ?>


    <?php foreach ($errors as $error): ?>

        <div class="odms-toast error-toast"
             role="alert">

            <div class="toast-icon error-icon">
                <svg xmlns="http://www.w3.org/2000/svg"
                     width="24" height="24"
                     viewBox="0 0 24 24"
                     fill="none" stroke="currentColor"
                     stroke-width="2.5"
                     stroke-linecap="round"
                     stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/>
                    <path d="M12 8v4"/>
                    <path d="M12 16h.01"/>
                </svg>
            </div>

            <div class="toast-content">
                <strong>Something went wrong</strong>
                <p><?= e($error) ?></p>
            </div>

            <button type="button"
                    class="toast-close"
                    aria-label="Close notification">
                &times;
            </button>

            <div class="toast-progress error-progress"></div>

        </div>

    <?php endforeach; ?>

</div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        <!-- Add or Edit Category -->
        <div class="bg-white rounded-xl shadow-sm p-6">

            <h2 class="text-lg font-semibold text-slate-800 mb-5">
                <?= $formId > 0 ? 'Edit Category' : 'Add New Category' ?>
            </h2>

            <form method="POST" action="<?= $formId > 0
                ? 'index.php?edit=' . $formId
                : 'index.php' ?>">

                <input type="hidden"
                       name="csrf_token"
                       value="<?= e(generateCsrfToken()) ?>">

                <input type="hidden"
                       name="category_id"
                       value="<?= $formId ?>">

                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 mb-2">
                        Category Name
                    </label>

                    <input
                        type="text"
                        name="category_name"
                        value="<?= e($formName) ?>"
                        maxlength="100"
                        required
                        placeholder="Enter category name"
                        class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 outline-none"
                    >
                </div>

                <div class="mb-5">
                    <label class="block text-sm font-medium text-slate-700 mb-2">
                        Description
                        <span class="text-slate-400">(Optional)</span>
                    </label>

                    <textarea
                        name="description"
                        rows="4"
                        placeholder="Enter category description"
                        class="w-full border border-slate-300 rounded-lg px-4 py-3 focus:ring-2 focus:ring-blue-500 outline-none"
                    ><?= e($formDescription) ?></textarea>
                </div>

                <button
                    type="submit"
                    class="w-full py-3 rounded-lg bg-blue-700 text-white font-medium hover:bg-blue-800"
                >
                    <?= $formId > 0 ? 'Update Category' : 'Save Category' ?>
                </button>

                <?php if ($formId > 0): ?>
                    <a href="index.php"
                       class="block text-center mt-3 py-2 text-slate-600 hover:text-slate-900">
                        Cancel Editing
                    </a>
                <?php endif; ?>

            </form>
        </div>

        <!-- Category List -->
        <div class="lg:col-span-2 bg-white rounded-xl shadow-sm p-6">

            <div class="flex flex-wrap justify-between items-center gap-3 mb-5">
                <div>
                    <h2 class="text-lg font-semibold text-slate-800">
                        Document Categories
                    </h2>

                    <p class="text-sm text-slate-500">
                        Total categories: <?= $totalCategories ?>
                    </p>
                </div>
            </div>

            <form method="GET" class="flex gap-2 mb-5">
                <input
                    type="text"
                    name="search"
                    value="<?= e($search) ?>"
                    placeholder="Search categories..."
                    class="flex-1 min-w-0 border border-slate-300 rounded-lg px-4 py-2"
                >

                <button
                    type="submit"
                    class="px-4 py-2 rounded-lg bg-slate-800 text-white hover:bg-slate-900"
                >
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
                            <th class="px-4 py-3">Category Name</th>
                            <th class="px-4 py-3">Description</th>
                            <th class="px-4 py-3">Action</th>
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-slate-100">

                    <?php if (empty($categories)): ?>

                        <tr>
                            <td colspan="3"
                                class="px-4 py-8 text-center text-slate-500">
                                No categories found.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($categories as $category): ?>

                            <tr class="hover:bg-slate-50">

                                <td class="px-4 py-4 font-medium text-slate-800">
                                    <?= e($category['category_name']) ?>
                                </td>

                                <td class="px-4 py-4 text-slate-600">
                                    <?= e($category['description'] ?: 'No description') ?>
                                </td>

                               <td class="px-4 py-4 whitespace-nowrap">

    <a
        href="index.php?edit=<?= (int)$category['category_id'] ?>"
        class="text-blue-700 font-medium hover:underline mr-3"
    >
        Edit
    </a>

    <form method="POST"
          action="index.php"
          class="inline"
          onsubmit="return confirm('Are you sure you want to delete this category?');">

        <input type="hidden"
               name="csrf_token"
               value="<?= e(generateCsrfToken()) ?>">

        <input type="hidden"
               name="category_id"
               value="<?= (int)$category['category_id'] ?>">

        <input type="hidden"
               name="action"
               value="delete">

        <button type="submit"
                class="text-red-600 font-medium hover:underline">
            Delete
        </button>

    </form>

</td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                    </tbody>
                </table>
            </div>

        </div>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function () {

    const toasts = document.querySelectorAll('.odms-toast');

    toasts.forEach(function (toast) {

        let removed = false;

        function closeToast() {
            if (removed) return;

            removed = true;
            toast.classList.add('toast-hiding');

            setTimeout(function () {
                toast.remove();
            }, 400);
        }

        const closeButton = toast.querySelector('.toast-close');

        closeButton.addEventListener('click', closeToast);

        // Automatically disappear after 4 seconds.
        setTimeout(closeToast, 4000);

    });

});
</script>
</body>
</html>