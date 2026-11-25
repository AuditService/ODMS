<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';

requireLogin();
$isAdmin = $_SESSION['role'] === 'admin';

// Escape output safely.
function h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// Validate document ID.
$documentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$documentId || $documentId < 1) {
    http_response_code(400);
    exit('Invalid document ID.');
}

// Retrieve document information.
$stmt = $pdo->prepare("
    SELECT
        d.document_id,
        d.title,
        d.document_month,
        d.document_year,
        d.date_covered,
        d.document_date,
        d.coverage_start_date,
        d.coverage_end_date,
        d.actual_received_date,
        d.received_at,
        d.notes,
        c.category_name,
        u.full_name
    FROM documents d
    INNER JOIN categories c
        ON d.category_id = c.category_id
    INNER JOIN users u
        ON d.uploaded_by = u.user_id
    WHERE d.document_id = ?
      AND d.deleted_at IS NULL
    LIMIT 1
");

$stmt->execute([$documentId]);

$document = $stmt->fetch();

if (!$document) {
    http_response_code(404);
    exit('Document record not found.');
}

// Retrieve all photos belonging to this document.
$fileStmt = $pdo->prepare("
    SELECT
        file_id,
        file_path,
        original_filename,
        mime_type,
        file_size
    FROM document_files
    WHERE document_id = ?
    ORDER BY file_id ASC
");

$fileStmt->execute([$documentId]);

$files = $fileStmt->fetchAll();

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

$monthName = $months[(int) $document['document_month']] ?? 'Unknown';

$documentPeriod = $monthName . ' ' . $document['document_year'];

function displayDate($date): string
{
    if (!$date || $date === '0000-00-00') {
        return 'Not specified';
    }

    return date('F j, Y', strtotime($date));
}

function displayDateTime($date): string
{
    if (!$date) {
        return 'Not available';
    }

    return date('F j, Y - h:i A', strtotime($date));
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Document Details | ODMS</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 25px;
            background: #f1f5f9;
            font-family: Arial, sans-serif;
            color: #243247;
        }

        .container {
            max-width: 1050px;
            margin: auto;
        }

        .header {
            background: #082b55;
            color: white;
            padding: 24px;
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

        .section-title {
            color: #082b55;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 10px;
            margin: 25px 0 18px;
        }

        .section-title:first-child {
            margin-top: 0;
        }

        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }

        .detail {
            padding: 14px;
            background: #f8fafc;
            border-radius: 7px;
            border: 1px solid #e2e8f0;
            overflow-wrap: anywhere;
        }

        .detail.full {
            grid-column: 1 / -1;
        }

        .detail-label {
            color: #64748b;
            font-size: 13px;
            margin-bottom: 7px;
        }

        .detail-value {
            font-weight: 600;
            color: #243247;
        }

        .photo-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
            gap: 18px;
        }

        .photo-card {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            overflow: hidden;
            background: white;
        }

        .photo-card img {
            width: 100%;
            height: 220px;
            object-fit: contain;
            background: #f1f5f9;
            display: block;
        }

        .photo-info {
            padding: 12px;
        }

        .photo-name {
            font-size: 13px;
            overflow-wrap: anywhere;
            margin-bottom: 10px;
        }

        .photo-link {
            color: #0b4a80;
            font-size: 13px;
            font-weight: bold;
            text-decoration: none;
        }

        .photo-link:hover {
            text-decoration: underline;
        }

        .empty {
            padding: 25px;
            text-align: center;
            background: #f8fafc;
            color: #64748b;
            border-radius: 8px;
        }

        .actions {
            margin-top: 28px;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .button {
            display: inline-block;
            padding: 12px 20px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 14px;
            font-weight: bold;
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

            .details-grid {
                grid-template-columns: 1fr;
            }

            .detail.full {
                grid-column: auto;
            }
        }
    </style>
</head>

<body>

<div class="container">

    <div class="header">
        <h2>Document Details</h2>
        <p>Operations Document Monitoring System</p>
    </div>

    <div class="content">

        <h3 class="section-title">Document Information</h3>

        <div class="details-grid">

            <div class="detail">
                <div class="detail-label">Document Description</div>
                <div class="detail-value">
                    <?= h($document['title'] ?: 'Not specified') ?>
                </div>
            </div>

            <div class="detail">
                <div class="detail-label">Document Category</div>
                <div class="detail-value">
                    <?= h($document['category_name']) ?>
                </div>
            </div>

            <div class="detail">
                <div class="detail-label">Document Period</div>
                <div class="detail-value">
                    <?= h($documentPeriod) ?>
                </div>
            </div>

            <div class="detail">
                <div class="detail-label">Date Covered</div>
                <div class="detail-value">
                    <?= h($document['date_covered'] ?: 'Not specified') ?>
                </div>
            </div>

            <div class="detail">
                <div class="detail-label">Actual Received Date</div>
                <div class="detail-value">
                    <?= h(displayDate($document['actual_received_date'])) ?>
                </div>
            </div>

            <div class="detail">
                <div class="detail-label">System Upload Date</div>
                <div class="detail-value">
                    <?= h(displayDateTime($document['received_at'])) ?>
                </div>
            </div>

            <div class="detail">
                <div class="detail-label">Uploaded By</div>
                <div class="detail-value">
                    <?= h($document['full_name']) ?>
                </div>
            </div>

            <div class="detail">
                <div class="detail-label">Number of Photos</div>
                <div class="detail-value">
                    <?= count($files) ?> photo(s)
                </div>
            </div>

            <div class="detail full">
                <div class="detail-label">Notes / Remarks</div>
                <div class="detail-value">
                    <?= nl2br(h($document['notes'] ?: 'No additional notes.')) ?>
                </div>
            </div>

        </div>

        <h3 class="section-title">Uploaded Document Photos</h3>

        <?php if ($files): ?>

            <div class="photo-grid">

                <?php foreach ($files as $file): ?>

                    <?php
                    // Only display files stored in the expected upload folder.
                    $filename = basename($file['file_path']);
                    $relativePath = 'uploads/documents/' . $filename;
                    $imageUrl = '/odms/' . $relativePath;

                    $isImage = in_array(
                        $file['mime_type'],
                        ['image/jpeg', 'image/png', 'image/webp'],
                        true
                    );

                    $fileExists = $isImage &&
                        is_file(__DIR__ . '/../' . $relativePath);
                    ?>

                    <div class="photo-card">

                        <?php if ($fileExists): ?>

                            <a href="<?= h($imageUrl) ?>" target="_blank" rel="noopener">
                                <img
                                    src="<?= h($imageUrl) ?>"
                                    alt="<?= h($file['original_filename']) ?>"
                                >
                            </a>

                        <?php else: ?>

                            <div class="empty">
                                Photo unavailable
                            </div>

                        <?php endif; ?>

                        <div class="photo-info">

                            <div class="photo-name">
                                <?= h($file['original_filename']) ?>
                            </div>

                            <?php if ($fileExists): ?>
                                <a
                                    class="photo-link"
                                    href="<?= h($imageUrl) ?>"
                                    target="_blank"
                                    rel="noopener"
                                >
                                    View Full Photo
                                </a>
                            <?php endif; ?>

                        </div>

                    </div>

                <?php endforeach; ?>

            </div>

        <?php else: ?>

            <div class="empty">
                No photos are attached to this document.
            </div>

        <?php endif; ?>

        <div class="actions">

    <?php if ($isAdmin): ?>
        <a href="edit.php?id=<?= (int)$documentId ?>"
           class="button"
           style="background:#082b55;color:white;">
            Edit Document
        </a>
    <?php endif; ?>

    <a href="index.php" class="button back">
        Back to Documents
    </a>

</div>

    </div>

</div>

</body>
</html>