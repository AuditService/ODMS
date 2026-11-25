<?php

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

$message = '';
$error = '';

if (isset($_GET['success'])) {
    $message = 'Document uploaded successfully!';
}

// Validate dates.
function validReceivedDate(string $value): bool
{
    $date = DateTime::createFromFormat('!Y-m-d', $value);

    return $date && $date->format('Y-m-d') === $value;
}

// Escape output.
function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Get document categories.
$categoryStmt = $pdo->query("
    SELECT category_id, category_name
    FROM categories
    ORDER BY category_name ASC
");

$categories = $categoryStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        // CSRF protection.
        if (!verifyCsrfToken()) {
            throw new RuntimeException(
                'Invalid form request. Please refresh the page.'
            );
        }

        $categoryId = filter_var(
            $_POST['category_id'] ?? '',
            FILTER_VALIDATE_INT
        );

        $description = trim($_POST['title'] ?? '');
        $month = filter_var(
            $_POST['document_month'] ?? '',
            FILTER_VALIDATE_INT
        );

        $year = filter_var(
            $_POST['document_year'] ?? '',
            FILTER_VALIDATE_INT
        );

        $dateCovered = trim($_POST['date_covered'] ?? '');
        $receivedDate = trim($_POST['actual_received_date'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        // Required field validation.
        if (!$categoryId) {
            throw new RuntimeException('Please select a document category.');
        }

        if ($description === '') {
            throw new RuntimeException('Please enter the document description.');
        }

        if (mb_strlen($description) > 200) {
            throw new RuntimeException(
                'Document description must not exceed 200 characters.'
            );
        }

        if (!$month || $month < 1 || $month > 12) {
            throw new RuntimeException('Please select a valid document month.');
        }

        if (!$year || $year < 1900 || $year > 2100) {
            throw new RuntimeException('Please enter a valid document year.');
        }

        if ($dateCovered === '') {
            throw new RuntimeException('Please enter the date covered.');
        }

        if (mb_strlen($dateCovered) > 255) {
            throw new RuntimeException(
                'Date covered must not exceed 255 characters.'
            );
        }

        if (!validReceivedDate($receivedDate)) {
            throw new RuntimeException(
                'Please select a valid actual received date.'
            );
        }

        // Verify category exists.
        $checkCategory = $pdo->prepare("
            SELECT category_id
            FROM categories
            WHERE category_id = ?
        ");

        $checkCategory->execute([$categoryId]);

        if (!$checkCategory->fetch()) {
            throw new RuntimeException('The selected category does not exist.');
        }

        // Check uploaded photos.
        if (
            !isset($_FILES['document_photos']) ||
            !isset($_FILES['document_photos']['name'][0]) ||
            $_FILES['document_photos']['name'][0] === ''
        ) {
            throw new RuntimeException(
                'Please select at least one document photo.'
            );
        }

        $allowedTypes = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp'
        ];

        $maxFileSize = 10 * 1024 * 1024;
        $maxFiles = 20;

        $fileCount = count($_FILES['document_photos']['name']);

        if ($fileCount > $maxFiles) {
            throw new RuntimeException(
                'You can upload a maximum of 20 photos at a time.'
            );
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $validatedFiles = [];

        for ($i = 0; $i < $fileCount; $i++) {

            $fileError = $_FILES['document_photos']['error'][$i];

            if ($fileError !== UPLOAD_ERR_OK) {
                throw new RuntimeException(
                    'One of the selected photos could not be uploaded.'
                );
            }

            $tmpName = $_FILES['document_photos']['tmp_name'][$i];
            $fileSize = (int) $_FILES['document_photos']['size'][$i];

            if (!is_uploaded_file($tmpName)) {
                throw new RuntimeException('Invalid uploaded file.');
            }

            if ($fileSize <= 0 || $fileSize > $maxFileSize) {
                throw new RuntimeException(
                    'Each photo must be smaller than 10 MB.'
                );
            }

            $mimeType = $finfo->file($tmpName);

            if (!isset($allowedTypes[$mimeType])) {
                throw new RuntimeException(
                    'Only JPG, PNG, and WEBP photos are allowed.'
                );
            }

            $validatedFiles[] = [
                'tmp_name' => $tmpName,
                'original_name' => basename(
                    $_FILES['document_photos']['name'][$i]
                ),
                'mime_type' => $mimeType,
                'file_size' => $fileSize,
                'extension' => $allowedTypes[$mimeType]
            ];
        }

        // Create photo storage folder if needed.
        $uploadDirectory = __DIR__ . '/uploads/documents/';

        if (
            !is_dir($uploadDirectory) &&
            !mkdir($uploadDirectory, 0755, true)
        ) {
            throw new RuntimeException(
                'Unable to create the document upload folder.'
            );
        }

        $savedFiles = [];

        try {

            $pdo->beginTransaction();

            // Save document information.
            // received_at and created_at are generated automatically.
            $documentStmt = $pdo->prepare("
                INSERT INTO documents (
                    category_id,
                    title,
                    document_month,
                    document_year,
                    date_covered,
                    actual_received_date,
                    notes,
                    uploaded_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $documentStmt->execute([
                $categoryId,
                $description,
                $month,
                $year,
                $dateCovered,
                $receivedDate,
                $notes !== '' ? $notes : null,
                $_SESSION['user_id']
            ]);

            $documentId = $pdo->lastInsertId();

            // Save photo information.
            $fileStmt = $pdo->prepare("
                INSERT INTO document_files (
                    document_id,
                    file_path,
                    original_filename,
                    mime_type,
                    file_size
                ) VALUES (?, ?, ?, ?, ?)
            ");

            foreach ($validatedFiles as $file) {

                $newFilename = bin2hex(random_bytes(16))
                    . '.' . $file['extension'];

                $destination = $uploadDirectory . $newFilename;

                if (!move_uploaded_file(
                    $file['tmp_name'],
                    $destination
                )) {
                    throw new RuntimeException(
                        'A photo could not be saved.'
                    );
                }

                $savedFiles[] = $destination;

                $relativePath = 'uploads/documents/' . $newFilename;

                $fileStmt->execute([
                    $documentId,
                    $relativePath,
                    $file['original_name'],
                    $file['mime_type'],
                    $file['file_size']
                ]);
            }

            // Record activity.
            $logStmt = $pdo->prepare("
                INSERT INTO activity_logs (
                    user_id,
                    action,
                    description
                ) VALUES (?, ?, ?)
            ");

            $logStmt->execute([
                $_SESSION['user_id'],
                'document_uploaded',
                'Uploaded document: ' . $description
                    . ' (' . count($validatedFiles) . ' photo(s))'
            ]);

            $pdo->commit();

            header('Location: /odms/upload.php?success=1');
            exit;

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            // Remove saved photos if the database transaction fails.
            foreach ($savedFiles as $savedFile) {
                if (is_file($savedFile)) {
                    unlink($savedFile);
                }
            }

            throw $e;
        }

    } catch (Throwable $e) {

        error_log('ODMS Upload Error: ' . $e->getMessage());

        $error = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'An unexpected error occurred. Please check the system and try again.';
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Upload Document | ODMS</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 25px;
            background: #f2f5f9;
            font-family: Arial, sans-serif;
            color: #243247;
        }

        .container {
            max-width: 950px;
            margin: 0 auto;
        }

        .header {
            background: #082b55;
            color: white;
            padding: 22px 28px;
            border-radius: 10px 10px 0 0;
        }

        .header h2 {
            margin: 0 0 6px;
        }

        .header p {
            margin: 0;
            color: #dce7f4;
        }

        .form-card {
            background: white;
            padding: 28px;
            border-radius: 0 0 10px 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,.06);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .full {
            grid-column: 1 / -1;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 8px;
        }

        input, select, textarea {
            width: 100%;
            padding: 11px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 15px;
        }

        textarea {
            resize: vertical;
            min-height: 90px;
        }

        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #0b4a80;
        }

        .help {
            display: block;
            color: #64748b;
            font-size: 12px;
            margin-top: 6px;
        }

        .section-title {
            grid-column: 1 / -1;
            margin: 12px 0 0;
            padding-bottom: 9px;
            border-bottom: 1px solid #e2e8f0;
            color: #082b55;
        }

        .alert {
            padding: 13px;
            margin-bottom: 18px;
            border-radius: 6px;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }

        .actions {
            margin-top: 25px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        button, .back-link {
            display: inline-block;
            padding: 12px 20px;
            border: none;
            border-radius: 6px;
            font-size: 15px;
            text-decoration: none;
            cursor: pointer;
        }

        button {
            background: #082b55;
            color: white;
        }

        button:hover {
            background: #0b4a80;
        }

        button:disabled {
            opacity: .5;
            cursor: not-allowed;
        }

        .back-link {
            background: #e2e8f0;
            color: #243247;
        }

        @media (max-width: 650px) {

            body {
                padding: 12px;
            }

            .form-card {
                padding: 18px;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .full, .section-title {
                grid-column: auto;
            }

        }

    </style>

</head>

<body>

<div class="container">

    <div class="header">

        <h2>Upload Document</h2>

        <p>Office Document Monitoring System</p>

    </div>

    <div class="form-card">

        <?php if ($message): ?>
            <div class="alert success">
                <?= e($message) ?>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert error">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form
            method="POST"
            action="upload.php"
            enctype="multipart/form-data"
        >

            <input
                type="hidden"
                name="csrf_token"
                value="<?= e(generateCsrfToken()) ?>"
            >

            <div class="form-grid">

                <h3 class="section-title">
                    Document Information
                </h3>

                <div class="full">

                    <label for="category_id">
                        Document Category *
                    </label>

                    <select
                        name="category_id"
                        id="category_id"
                        required
                    >

                        <option value="">
                            -- Select Category --
                        </option>

                        <?php foreach ($categories as $category): ?>

                            <option
                                value="<?= e($category['category_id']) ?>"
                                <?= (string)($_POST['category_id'] ?? '') === (string)$category['category_id'] ? 'selected' : '' ?>
                            >
                                <?= e($category['category_name']) ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                    <?php if (!$categories): ?>
                        <small class="help">
                            No categories available. Please add a category first.
                        </small>
                    <?php endif; ?>

                </div>

                <div class="full">

                    <label for="title">
                        Document Description *
                    </label>

                    <input
                        type="text"
                        name="title"
                        id="title"
                        maxlength="200"
                        value="<?= e($_POST['title'] ?? '') ?>"
                        placeholder="Example: Construction Division or Waterworks"
                        required
                    >

                    <small class="help">
                        Enter the division, office, or description identifying the document.
                    </small>

                </div>

                <div>

                    <label for="document_month">
                        Document Month *
                    </label>

                    <select
                        name="document_month"
                        id="document_month"
                        required
                    >

                        <option value="">-- Select Month --</option>

                        <?php

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

                        foreach ($months as $number => $monthName):

                        ?>

                            <option
                                value="<?= $number ?>"
                                <?= (string)($_POST['document_month'] ?? '') === (string)$number ? 'selected' : '' ?>
                            >
                                <?= $monthName ?>
                            </option>

                        <?php endforeach; ?>

                    </select>

                </div>

                <div>

                    <label for="document_year">
                        Document Year *
                    </label>

                    <input
                        type="number"
                        name="document_year"
                        id="document_year"
                        min="1900"
                        max="2100"
                        value="<?= e($_POST['document_year'] ?? date('Y')) ?>"
                        required
                    >

                </div>

                <h3 class="section-title">
                    Document Dates
                </h3>

                <div class="full">

                    <label for="date_covered">
                        Date Covered *
                    </label>

                    <input
                        type="text"
                        name="date_covered"
                        id="date_covered"
                        maxlength="255"
                        value="<?= e($_POST['date_covered'] ?? '') ?>"
                        placeholder="Example: August Week 1 (17-20/2026)"
                        required
                    >

                    <small class="help">
                        Type the date or period exactly as indicated on the document.
                    </small>

                </div>

                <div class="full">

                    <label for="actual_received_date">
                        Actual Received Date *
                    </label>

                    <input
                        type="date"
                        name="actual_received_date"
                        id="actual_received_date"
                        value="<?= e($_POST['actual_received_date'] ?? date('Y-m-d')) ?>"
                        required
                    >

                    <small class="help">
                        Select the actual date your office received the document.
                    </small>

                </div>

                <div class="full">

                    <label for="notes">
                        Notes / Remarks
                    </label>

                    <textarea
                        name="notes"
                        id="notes"
                        placeholder="Additional information about this document..."
                    ><?= e($_POST['notes'] ?? '') ?></textarea>

                </div>

                <h3 class="section-title">
                    Document Photos
                </h3>

                <div class="full">

                    <label for="document_photos">
                        Select Document Photos *
                    </label>

                    <input
                        type="file"
                        name="document_photos[]"
                        id="document_photos"
                        accept=".jpg,.jpeg,.png,.webp"
                        multiple
                        required
                    >

                    <small class="help">
                        You can select multiple JPG, PNG, or WEBP photos.
                        Maximum 20 photos, 10 MB each.
                    </small>

                </div>

            </div>

            <div class="actions">

                <button
                    type="submit"
                    <?= !$categories ? 'disabled' : '' ?>
                >
                    Upload Document
                </button>

                <a href="index.php" class="back-link">
                    Back to Dashboard
                </a>

            </div>

        </form>

    </div>

</div>

</body>
</html>