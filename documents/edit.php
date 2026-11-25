<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireRole('admin');

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function validReceivedDate(string $value): bool
{
    $date = DateTime::createFromFormat('!Y-m-d', $value);

    return $date && $date->format('Y-m-d') === $value;
}

// Validate document ID.
$documentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$documentId || $documentId < 1) {
    http_response_code(400);
    exit('Invalid document ID.');
}

// Retrieve document.
$stmt = $pdo->prepare("
    SELECT
        document_id,
        category_id,
        title,
        document_month,
        document_year,
        date_covered,
        actual_received_date,
        notes
    FROM documents
    WHERE document_id = ?
      AND deleted_at IS NULL
    LIMIT 1
");

$stmt->execute([$documentId]);
$document = $stmt->fetch();

if (!$document) {
    http_response_code(404);
    exit('Document record not found.');
}

// Retrieve categories.
$categoryStmt = $pdo->query("
    SELECT category_id, category_name
    FROM categories
    ORDER BY category_name ASC
");

$categories = $categoryStmt->fetchAll();

$error = '';
$message = isset($_GET['success'])
    ? 'Document updated successfully!'
    : '';

// Handle form submission.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $savedFiles = [];

    try {

        if (!verifyCsrfToken()) {
            throw new RuntimeException(
                'Your session expired. Please refresh the page.'
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

        // Validate required information.
        if (!$categoryId) {
            throw new RuntimeException(
                'Please select a document category.'
            );
        }

        if ($description === '' || mb_strlen($description) > 200) {
            throw new RuntimeException(
                'Please enter a valid document description.'
            );
        }

        if (!$month || $month < 1 || $month > 12) {
            throw new RuntimeException(
                'Please select a valid document month.'
            );
        }

        if (!$year || $year < 1900 || $year > 2100) {
            throw new RuntimeException(
                'Please enter a valid document year.'
            );
        }

        if ($dateCovered === '' || mb_strlen($dateCovered) > 255) {
            throw new RuntimeException(
                'Please enter a valid date covered.'
            );
        }

        if (!validReceivedDate($receivedDate)) {
            throw new RuntimeException(
                'Please select a valid actual received date.'
            );
        }

        // Verify category.
        $checkCategory = $pdo->prepare("
            SELECT category_id
            FROM categories
            WHERE category_id = ?
        ");

        $checkCategory->execute([$categoryId]);

        if (!$checkCategory->fetch()) {
            throw new RuntimeException(
                'The selected category does not exist.'
            );
        }

        // Validate any newly selected photos.
        $validatedFiles = [];

        if (
            isset($_FILES['document_photos']) &&
            is_array($_FILES['document_photos']['name'])
        ) {

            $allowedTypes = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp'
            ];

            $maxFileSize = 10 * 1024 * 1024;
            $maxFiles = 20;

            $fileCount = count($_FILES['document_photos']['name']);

            $finfo = new finfo(FILEINFO_MIME_TYPE);

            for ($i = 0; $i < $fileCount; $i++) {

                $originalName = $_FILES['document_photos']['name'][$i];

                // Ignore empty file inputs.
                if ($originalName === '') {
                    continue;
                }

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
                    'original_name' => basename($originalName),
                    'mime_type' => $mimeType,
                    'file_size' => $fileSize,
                    'extension' => $allowedTypes[$mimeType]
                ];
            }

            if (count($validatedFiles) > $maxFiles) {
                throw new RuntimeException(
                    'You can add a maximum of 20 photos at a time.'
                );
            }
        }

        // Create upload directory if necessary.
        $uploadDirectory = __DIR__ . '/../uploads/documents/';

        if (
            !is_dir($uploadDirectory) &&
            !mkdir($uploadDirectory, 0755, true)
        ) {
            throw new RuntimeException(
                'Unable to access the document upload folder.'
            );
        }

        try {

            $pdo->beginTransaction();

            // Update document information.
            $updateStmt = $pdo->prepare("
                UPDATE documents
                SET
                    category_id = ?,
                    title = ?,
                    document_month = ?,
                    document_year = ?,
                    date_covered = ?,
                    actual_received_date = ?,
                    notes = ?
                WHERE document_id = ?
                  AND deleted_at IS NULL
            ");

            $updateStmt->execute([
                $categoryId,
                $description,
                $month,
                $year,
                $dateCovered,
                $receivedDate,
                $notes !== '' ? $notes : null,
                $documentId
            ]);

            // Attach new photos without touching existing photos.
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
                        'A new photo could not be saved.'
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
                'DOCUMENT_UPDATED',
                'Updated document: ' . $description
                    . ' (' . count($validatedFiles)
                    . ' new photo(s) added)'
            ]);

            $pdo->commit();

            header(
                'Location: edit.php?id=' . $documentId . '&success=1'
            );
            exit;

        } catch (Throwable $e) {

            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            // Remove newly saved files if the transaction fails.
            foreach ($savedFiles as $savedFile) {
                if (is_file($savedFile)) {
                    unlink($savedFile);
                }
            }

            throw $e;
        }

    } catch (Throwable $e) {

        error_log('ODMS Edit Document Error: ' . $e->getMessage());

        $error = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'An unexpected error occurred. Please try again.';
    }
}

// Retrieve current photos.
$fileStmt = $pdo->prepare("
    SELECT file_id, original_filename
    FROM document_files
    WHERE document_id = ?
    ORDER BY file_id ASC
");

$fileStmt->execute([$documentId]);
$files = $fileStmt->fetchAll();

// Use submitted values after validation errors.
$form = [
    'category_id' => $_POST['category_id'] ?? $document['category_id'],
    'title' => $_POST['title'] ?? $document['title'],
    'document_month' => $_POST['document_month'] ?? $document['document_month'],
    'document_year' => $_POST['document_year'] ?? $document['document_year'],
    'date_covered' => $_POST['date_covered'] ?? $document['date_covered'],
    'actual_received_date' => $_POST['actual_received_date'] ?? $document['actual_received_date'],
    'notes' => $_POST['notes'] ?? $document['notes']
];

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

?>

<!DOCTYPE html>
<html lang="en">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Edit Document | ODMS</title>

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
            margin: auto;
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

        .content {
            background: white;
            padding: 28px;
            border-radius: 0 0 10px 10px;
            box-shadow: 0 4px 15px rgba(0,0,0,.06);
        }

        .grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }

        .full {
            grid-column: 1 / -1;
        }

        .section-title {
            grid-column: 1 / -1;
            color: #082b55;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 9px;
            margin: 12px 0 0;
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

        .help {
            display: block;
            color: #64748b;
            font-size: 12px;
            margin-top: 6px;
        }

        .photo-list {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            padding: 15px;
            border-radius: 7px;
        }

        .photo-list p {
            margin: 7px 0;
            font-size: 14px;
            overflow-wrap: anywhere;
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

        button, .back {
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

        .back {
            background: #e2e8f0;
            color: #243247;
        }

        @media (max-width: 650px) {
            body {
                padding: 12px;
            }

            .content {
                padding: 18px;
            }

            .grid {
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
        <h2>Edit Document</h2>
        <p>Update document information and add photos</p>
    </div>

    <div class="content">

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

        <form method="POST"
              action="edit.php?id=<?= (int)$documentId ?>"
              enctype="multipart/form-data">

            <input type="hidden"
                   name="csrf_token"
                   value="<?= e(generateCsrfToken()) ?>">

            <div class="grid">

                <h3 class="section-title">Document Information</h3>

                <div class="full">
                    <label for="category_id">Document Category *</label>

                    <select name="category_id" id="category_id" required>
                        <option value="">-- Select Category --</option>

                        <?php foreach ($categories as $category): ?>
                            <option
                                value="<?= (int)$category['category_id'] ?>"
                                <?= (string)$form['category_id'] === (string)$category['category_id'] ? 'selected' : '' ?>>
                                <?= e($category['category_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="full">
                    <label for="title">Document Description *</label>

                    <input
                        type="text"
                        name="title"
                        id="title"
                        maxlength="200"
                        value="<?= e($form['title']) ?>"
                        required>
                </div>

                <div>
                    <label for="document_month">Document Month *</label>

                    <select name="document_month" id="document_month" required>
                        <?php foreach ($months as $number => $monthName): ?>
                            <option
                                value="<?= $number ?>"
                                <?= (string)$form['document_month'] === (string)$number ? 'selected' : '' ?>>
                                <?= e($monthName) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="document_year">Document Year *</label>

                    <input
                        type="number"
                        name="document_year"
                        id="document_year"
                        min="1900"
                        max="2100"
                        value="<?= e($form['document_year']) ?>"
                        required>
                </div>

                <h3 class="section-title">Document Dates</h3>

                <div class="full">
                    <label for="date_covered">Date Covered *</label>

                    <input
                        type="text"
                        name="date_covered"
                        id="date_covered"
                        maxlength="255"
                        value="<?= e($form['date_covered']) ?>"
                        required>
                </div>

                <div class="full">
                    <label for="actual_received_date">Actual Received Date *</label>

                    <input
                        type="date"
                        name="actual_received_date"
                        id="actual_received_date"
                        value="<?= e($form['actual_received_date']) ?>"
                        required>
                </div>

                <div class="full">
                    <label for="notes">Notes / Remarks</label>

                    <textarea
                        name="notes"
                        id="notes"><?= e($form['notes']) ?></textarea>
                </div>

                <h3 class="section-title">Existing Document Photos</h3>

                <div class="full photo-list">

                    <?php if ($files): ?>

                        <strong>
                            <?= count($files) ?> existing photo(s)
                        </strong>

                        <?php foreach ($files as $index => $file): ?>
                            <p>
                                <?= ($index + 1) ?>.
                                <?= e($file['original_filename']) ?>
                            </p>
                        <?php endforeach; ?>

                        <small class="help">
                            Existing photos will be preserved when you save changes.
                        </small>

                    <?php else: ?>

                        No photos are currently attached.

                    <?php endif; ?>

                </div>

                <h3 class="section-title">Add More Photos</h3>

                <div class="full">

                    <label for="document_photos">
                        Select Additional Photos
                    </label>

                    <input
                        type="file"
                        name="document_photos[]"
                        id="document_photos"
                        accept=".jpg,.jpeg,.png,.webp"
                        multiple>

                    <small class="help">
                        Select only the additional pictures you want to attach.
                        Existing pictures will not be deleted.
                        JPG, PNG, and WEBP are accepted, maximum 10 MB each.
                    </small>

                </div>

            </div>

            <div class="actions">

                <button type="submit">
                    Save Changes
                </button>

                <a href="view.php?id=<?= (int)$documentId ?>"
                   class="back">
                    Cancel
                </a>

            </div>

        </form>

    </div>

</div>

</body>
</html>