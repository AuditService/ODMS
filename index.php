<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';

requireLogin();

// Retrieve notification message, if available.
$toast = $_SESSION['toast'] ?? null;
unset($_SESSION['toast']);

$isAdmin = $_SESSION['role'] === 'admin';

// Get dashboard statistics.
$totalDocuments = (int) $pdo->query(
    "SELECT COUNT(*) FROM documents WHERE deleted_at IS NULL"
)->fetchColumn();

$totalCategories = (int) $pdo->query(
    "SELECT COUNT(*) FROM categories"
)->fetchColumn();

$totalFiles = (int) $pdo->query(
    "SELECT COUNT(*)
     FROM document_files df
     INNER JOIN documents d ON df.document_id = d.document_id
     WHERE d.deleted_at IS NULL"
)->fetchColumn();

$totalUsers = 0;

if ($isAdmin) {
    $totalUsers = (int) $pdo->query(
        "SELECT COUNT(*) FROM users WHERE is_active = 1"
    )->fetchColumn();
}

// Get the latest documents.
$stmt = $pdo->query(
    "SELECT
        d.document_id,
        d.title,
        d.document_month,
        d.document_year,
        d.received_at,
        c.category_name,
        u.full_name,
        (
            SELECT COUNT(*)
            FROM document_files df
            WHERE df.document_id = d.document_id
        ) AS file_count
     FROM documents d
     INNER JOIN categories c ON d.category_id = c.category_id
     INNER JOIN users u ON d.uploaded_by = u.user_id
     WHERE d.deleted_at IS NULL
     ORDER BY d.received_at DESC
     LIMIT 5"
);

$recentDocuments = $stmt->fetchAll();

function monthName(int $month): string
{
    return date('F', mktime(0, 0, 0, $month, 1));
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>ODMS | Dashboard</title>

    <script src="https://cdn.tailwindcss.com"></script>

    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/toastify-js/src/toastify.min.css">

    <style>

        :root {
            --navy: #082f5c;
            --deep-navy: #061b35;
            --gold: #e8bd42;
            --light-bg: #f1f5f9;
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            width: 100%;
            height: 100%;
            margin: 0;
        }

        body {
            overflow: hidden;
            font-family: Arial, Helvetica, sans-serif;
            background: var(--light-bg);
        }

        /* MAIN APP LAYOUT */

        .app-layout {
            display: flex;
            width: 100%;
            height: 100vh;
            height: 100dvh;
            overflow: hidden;
        }

        /* FIXED SIDEBAR */

        .sidebar {
            width: 265px;
            min-width: 265px;
            height: 100%;
            flex-shrink: 0;

            display: flex;
            flex-direction: column;

            color: white;

            background: linear-gradient(
                180deg,
                #0b2342 0%,
                #071a32 55%,
                #04152a 100%
            );

            border-right: 1px solid rgba(255,255,255,.07);
            overflow-y: auto;
            scrollbar-width: thin;
            scrollbar-color: #334b68 #071a32;
        }

        .sidebar-brand {
    position: relative;
    padding: 27px 19px 25px;
    border-bottom: 1px solid rgba(255,255,255,.12);
}

.brand-lockup {
    display: grid;
    grid-template-columns: 66px minmax(0, 1fr);
    align-items: center;
    gap: 13px;
}

.brand-logo {
    width: 66px;
    height: 66px;
    display: block;
    flex-shrink: 0;
    object-fit: contain;
    border-radius: 50%;
    background: #fff;
    padding: 4px;
    box-shadow: 0 3px 12px rgba(0,0,0,.22);
}

.brand-name {
    color: white;
    font-size: 19px;
    font-weight: 800;
    letter-spacing: 1px;
    line-height: 1.1;
    margin: 0;
}

.brand-subtitle {
    color: #b8c8da;
    font-size: 11px;
    line-height: 1.5;
    margin-top: 6px;
}

.brand-office {
    color: #f1cb59;
    font-size: 7px;
    font-weight: 800;
    letter-spacing: 1px;
    line-height: 1.5;
    margin-top: 7px;
}


        .sidebar-nav {
            flex: 1;
            padding: 0px 14px;
        }

        .nav-heading {
            color: #8295ad;
            font-size: 10px;
            font-weight: 700;
            letter-spacing: 1.6px;
            text-transform: uppercase;

            padding: 20px 12px 9px;
        }

        .nav-link {
            display: flex;
            align-items: center;
            gap: 12px;

            padding: 12px 14px;
            margin-bottom: 6px;

            color: #cbd5e1;
            text-decoration: none;

            font-size: 13px;
            font-weight: 500;

            border-radius: 9px;
            border: 1px solid transparent;

            transition: .2s;
        }

        .nav-link:hover {
            color: white;
            background: rgba(255,255,255,.09);
        }

        .nav-link.active {
            color: white;
            background: linear-gradient(
                100deg,
                #294665,
                #344e6b
            );

            border-color: rgba(255,255,255,.08);
            box-shadow: 0 4px 12px rgba(0,0,0,.12);
        }

        .nav-icon {
            width: 22px;
            text-align: center;
            font-size: 17px;
        }

        .sidebar-footer {
            padding: 15px;
            border-top: 1px solid rgba(255,255,255,.12);
        }

        .user-avatar {
            width: 39px;
            height: 39px;
            flex-shrink: 0;

            display: flex;
            align-items: center;
            justify-content: center;

            color: #082f5c;
            background: linear-gradient(135deg, #ffe58a, #d9a82e);

            border-radius: 50%;
            font-weight: 800;
            font-size: 15px;
        }

        /* RIGHT SIDE - ONLY THIS PART SCROLLS */

        .main-panel {
            flex: 1;
            min-width: 0;
            height: 100%;

            overflow-y: auto;
            overflow-x: hidden;

            background: #f1f5f9;
            scroll-behavior: smooth;
        }

        /* TOP HEADER */

        .top-header {
            position: sticky;
            top: 0;
            z-index: 20;

            min-height: 88px;

            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;

            padding: 17px 30px;

            background: rgba(255,255,255,.96);
            border-bottom: 1px solid #e2e8f0;

            backdrop-filter: blur(12px);
        }

        .header-title {
            color: #102b4b;
            font-size: 21px;
            font-weight: 800;
        }

        .header-subtitle {
            color: #64748b;
            font-size: 12px;
            margin-top: 5px;
        }

        .logout-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;

            padding: 10px 17px;

            color: white;
            background: #102b4b;

            border-radius: 8px;
            font-size: 12px;
            font-weight: 700;

            transition: .2s;
        }

        .logout-button:hover {
            background: #1d4773;
        }

        /* MAIN CONTENT */

        .dashboard-content {
            max-width: 1600px;
            margin: 0 auto;
            padding: 29px 30px 35px;
        }

        /* WELCOME BANNER */

        .welcome-banner {
            position: relative;
            overflow: hidden;

            padding: 28px 30px;
            margin-bottom: 25px;

            color: white;

            background: linear-gradient(
                115deg,
                #0c477c,
                #082f5c 65%,
                #061b35
            );

            border-radius: 15px;
            box-shadow: 0 8px 20px rgba(8,47,92,.13);
        }

        .welcome-banner::before {
            content: "";
            position: absolute;

            width: 240px;
            height: 240px;

            top: -145px;
            right: 60px;

            border: 2px solid rgba(232,189,66,.45);
            border-radius: 50%;
            box-shadow: 0 0 0 15px rgba(232,189,66,.06);
        }

        .welcome-banner::after {
            content: "";
            position: absolute;

            width: 160px;
            height: 160px;

            right: -55px;
            bottom: -110px;

            border: 2px solid rgba(255,255,255,.14);
            border-radius: 50%;
        }

        .welcome-label {
            color: #f8d66e;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 2px;
            text-transform: uppercase;
        }

        .welcome-title {
            margin-top: 9px;
            color: white;
            font-size: clamp(21px, 2.5vw, 28px);
            font-weight: 800;
        }

        .welcome-description {
            margin-top: 7px;
            color: #d5e1ef;
            font-size: 13px;
        }

        /* STATISTICS */

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 17px;
            margin-bottom: 25px;
        }

        .stat-card {
            position: relative;
            overflow: hidden;

            min-width: 0;
            padding: 20px;

            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 13px;

            box-shadow: 0 3px 10px rgba(15,23,42,.035);

            transition: transform .2s, box-shadow .2s;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 9px 20px rgba(15,23,42,.08);
        }

        .stat-card::before {
            content: "";
            position: absolute;

            left: 0;
            top: 0;

            width: 4px;
            height: 100%;

            background: var(--gold);
        }

        .stat-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .stat-icon {
            width: 39px;
            height: 39px;

            display: flex;
            align-items: center;
            justify-content: center;

            color: #0b4779;
            background: #eaf2fa;

            border-radius: 10px;
            font-size: 19px;
        }

        .stat-label {
            color: #64748b;
            font-size: 12px;
            font-weight: 600;
        }

        .stat-number {
            margin-top: 13px;
            color: #102b4b;
            font-size: 30px;
            font-weight: 800;
            line-height: 1;
        }

        .stat-description {
            margin-top: 9px;
            color: #94a3b8;
            font-size: 10px;
        }

        /* GENERAL PANELS */

        .dashboard-panel {
            background: white;
            border: 1px solid #e2e8f0;
            border-radius: 13px;

            box-shadow: 0 3px 10px rgba(15,23,42,.035);
        }

        .panel-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;

            padding: 20px 23px;
            border-bottom: 1px solid #edf1f5;
        }

        .panel-title {
            color: #102b4b;
            font-size: 16px;
            font-weight: 800;
        }

        .panel-description {
            color: #94a3b8;
            font-size: 11px;
            margin-top: 5px;
        }

        /* QUICK ACTIONS */

        .quick-actions {
            padding: 21px 23px;
            margin-bottom: 25px;
        }

        .action-list {
            display: flex;
            flex-wrap: wrap;
            gap: 11px;
            margin-top: 17px;
        }

        .action-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;

            padding: 12px 17px;

            border-radius: 8px;
            text-decoration: none;

            font-size: 12px;
            font-weight: 700;

            transition: .2s;
        }

        .action-primary {
            color: white;
            background: #082f5c;
        }

        .action-primary:hover {
            background: #164d80;
            box-shadow: 0 4px 12px rgba(8,47,92,.15);
        }

        .action-secondary {
            color: #29445f;
            background: #fff;
            border: 1px solid #cbd5e1;
        }

        .action-secondary:hover {
            background: #f1f5f9;
            border-color: #94a3b8;
        }

        /* RECENT DOCUMENTS TABLE */

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        .documents-table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            white-space: nowrap;
        }

        .documents-table thead {
            background: #f8fafc;
        }

        .documents-table th {
            padding: 14px 20px;

            color: #64748b;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .6px;
            text-transform: uppercase;
        }

        .documents-table td {
            padding: 16px 20px;

            color: #526174;
            font-size: 12px;

            border-top: 1px solid #f1f5f9;
        }

        .documents-table tbody tr {
            transition: background .15s;
        }

        .documents-table tbody tr:hover {
            background: #f8fafc;
        }

        .document-title {
            color: #102b4b !important;
            font-weight: 700;
        }

        .category-label {
            display: inline-block;

            padding: 5px 9px;

            color: #164d80;
            background: #eff6ff;

            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
        }

        .file-count {
            display: inline-block;

            min-width: 28px;
            padding: 5px 8px;

            color: #475569;
            background: #f1f5f9;

            border-radius: 6px;
            text-align: center;
            font-weight: 700;
        }

        .empty-state {
            padding: 40px 15px !important;
            color: #94a3b8 !important;
            text-align: center;
        }

        /* FOOTER */

        .dashboard-footer {
            padding: 24px 10px 8px;
            color: #727274;
            font-size: 10px;
            text-align: center;
        }

        .dashboard-footer span {
            color: #b38a28;
            font-weight: 700;
        }

        /* MOBILE NAVIGATION */

        .mobile-navigation {
            display: none;
        }

        /* RESPONSIVE */

        @media (max-width: 1100px) {
            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 767px) {

            .sidebar {
                display: none;
            }

            .main-panel {
                width: 100%;
            }

            .top-header {
                min-height: 75px;
                padding: 13px 17px;
            }

            .header-title {
                font-size: 18px;
            }

            .dashboard-content {
                padding: 18px 15px 25px;
            }

            .welcome-banner {
                padding: 23px 20px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 11px;
            }

            .stat-card {
                padding: 15px;
            }

            .stat-number {
                font-size: 26px;
            }

            .quick-actions {
                padding: 18px;
            }

            .action-list {
                flex-direction: column;
            }

            .action-button {
                width: 100%;
            }

            .mobile-navigation {
                display: flex;
                flex-wrap: wrap;
                gap: 7px;

                padding: 12px 15px;

                background: #fff;
                border-bottom: 1px solid #e2e8f0;
            }

            .mobile-navigation a {
                padding: 8px 11px;

                color: #29445f;
                background: #f1f5f9;

                border-radius: 7px;
                text-decoration: none;
                font-size: 11px;
                font-weight: 700;
            }

            .mobile-navigation a.active {
                color: white;
                background: #082f5c;
            }

            .panel-header {
                padding: 17px;
            }

            .documents-table th,
            .documents-table td {
                padding: 13px 15px;
            }
        }

        @media (max-width: 380px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .top-header {
                padding: 12px;
            }

            .logout-button {
                padding: 9px 12px;
            }
        }

    </style>

</head>

<body>

<div class="app-layout">

    <!-- =====================================
         FIXED LEFT SIDEBAR
    ====================================== -->

    <aside class="sidebar">

       <!-- CITY BRANDING -->

<div class="sidebar-brand">

    <div class="brand-lockup">

        <img
            src="/odms/assets/images/city-of-bais-seal.jpg"
            alt="City of Bais Official Seal"
            class="brand-logo"
        >

        <div class="min-w-0">

            <h1 class="brand-name">City of Bais</h1>

            <p class="brand-subtitle">
                Operations Document<br>
                Monitoring System
            </p>

            <p class="brand-office">
                INTERNAL AUDIT SERVICE
            </p>

        </div>

    </div>

</div>
        <!-- SIDEBAR NAVIGATION -->

        <nav class="sidebar-nav">

            <p class="nav-heading">Main Menu</p>

            <a href="/odms/index.php"
               class="nav-link active">

                <span class="nav-icon">▦</span>
                <span>Dashboard</span>

            </a>

            <a href="/odms/documents/index.php"
               class="nav-link">

                <span class="nav-icon">▤</span>
                <span>Documents</span>

            </a>

            <a href="/odms/upload.php"
               class="nav-link">

                <span class="nav-icon">⇧</span>
                <span>Upload Document</span>

            </a>

            <?php if ($isAdmin): ?>

                <p class="nav-heading">Administration</p>

                <a href="/odms/admin/categories/index.php"
                   class="nav-link">

                    <span class="nav-icon">▧</span>
                    <span>Manage Categories</span>

                </a>

                <a href="/odms/admin/users/index.php"
                   class="nav-link">

                    <span class="nav-icon">♙</span>
                    <span>User Management</span>

                </a>

                <a href="/odms/admin/activity-logs/index.php"
                   class="nav-link">

                    <span class="nav-icon">◷</span>
                    <span>Activity Logs</span>

                </a>

            <?php endif; ?>

        </nav>

        <!-- SIDEBAR USER INFORMATION -->

        <div class="sidebar-footer">

            <div class="flex items-center gap-3">

                <div class="user-avatar">
                    <?= htmlspecialchars(
                        strtoupper(substr($_SESSION['full_name'], 0, 1))
                    ) ?>
                </div>

                <div class="min-w-0">

                    <p class="text-sm font-bold text-white truncate">
                        <?= htmlspecialchars($_SESSION['full_name']) ?>
                    </p>

                    <p class="text-xs text-slate-400 mt-1">
                        <?= htmlspecialchars(ucfirst($_SESSION['role'])) ?>
                        Account
                    </p>

                </div>

            </div>

        </div>

    </aside>


    <!-- =====================================
         RIGHT SIDE - SCROLLABLE CONTENT
    ====================================== -->

    <div class="main-panel">

        <!-- TOP HEADER -->

        <header class="top-header">

            <div>

                <h2 class="header-title">
                    Dashboard
                </h2>

                <p class="header-subtitle">
                    Document monitoring overview
                </p>

            </div>

            <div class="flex items-center gap-4">

                <div class="text-right hidden sm:block">

                    <p class="text-sm font-bold text-slate-800">
                        <?= htmlspecialchars($_SESSION['full_name']) ?>
                    </p>

                    <p class="text-xs text-slate-500 mt-1">
                        <?= htmlspecialchars(ucfirst($_SESSION['role'])) ?>
                    </p>

                </div>

                <form action="/odms/logout.php" method="POST">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= htmlspecialchars(generateCsrfToken()) ?>"
                    >

                    <button type="submit" class="logout-button">
                        ↪ &nbsp; Logout
                    </button>

                </form>

            </div>

        </header>


        <!-- MOBILE NAVIGATION -->

        <nav class="mobile-navigation">

            <a href="/odms/index.php" class="active">
                Dashboard
            </a>

            <a href="/odms/documents/index.php">
                Documents
            </a>

            <a href="/odms/upload.php">
                Upload
            </a>

            <?php if ($isAdmin): ?>

                <a href="/odms/admin/categories/index.php">
                    Categories
                </a>

                <a href="/odms/admin/users/index.php">
                    Users
                </a>

                <a href="/odms/admin/activity-logs/index.php">
                    Activity Logs
                </a>

            <?php endif; ?>

        </nav>


        <!-- DASHBOARD CONTENT -->

        <main class="dashboard-content">


            <!-- WELCOME BANNER -->

            <section class="welcome-banner">

                <div class="relative z-10">

                    <p class="welcome-label">
                        City of Bais · Internal Audit Service
                    </p>

                    <h3 class="welcome-title">
                        Good day, <?= htmlspecialchars($_SESSION['full_name']) ?>!
                    </h3>

                    <p class="welcome-description">
                        Here is the current overview of monitored documents
                        and system records.
                    </p>

                </div>

            </section>


            <!-- STATISTICS -->

            <section class="stats-grid">

                <!-- TOTAL DOCUMENTS -->

                <div class="stat-card">

                    <div class="stat-top">

                        <p class="stat-label">
                            Total Documents
                        </p>

                        <div class="stat-icon">▤</div>

                    </div>

                    <p class="stat-number">
                        <?= number_format($totalDocuments) ?>
                    </p>

                    <p class="stat-description">
                        Active document records
                    </p>

                </div>


                <!-- CATEGORIES -->

                <div class="stat-card">

                    <div class="stat-top">

                        <p class="stat-label">
                            Document Categories
                        </p>

                        <div class="stat-icon">▧</div>

                    </div>

                    <p class="stat-number">
                        <?= number_format($totalCategories) ?>
                    </p>

                    <p class="stat-description">
                        Registered categories
                    </p>

                </div>


                <!-- UPLOADED FILES -->

                <div class="stat-card">

                    <div class="stat-top">

                        <p class="stat-label">
                            Uploaded Files
                        </p>

                        <div class="stat-icon">▣</div>

                    </div>

                    <p class="stat-number">
                        <?= number_format($totalFiles) ?>
                    </p>

                    <p class="stat-description">
                        Document images and pages
                    </p>

                </div>


                <!-- ACTIVE USERS -->

                <?php if ($isAdmin): ?>

                    <div class="stat-card">

                        <div class="stat-top">

                            <p class="stat-label">
                                Active Users
                            </p>

                            <div class="stat-icon">♙</div>

                        </div>

                        <p class="stat-number">
                            <?= number_format($totalUsers) ?>
                        </p>

                        <p class="stat-description">
                            Authorized system accounts
                        </p>

                    </div>

                <?php endif; ?>

            </section>


            <!-- QUICK ACTIONS -->

            <section class="dashboard-panel quick-actions">

                <div>

                    <h3 class="panel-title">
                        Quick Actions
                    </h3>

                    <p class="panel-description">
                        Quickly access frequently used system features.
                    </p>

                </div>

                <div class="action-list">

                    <a href="/odms/upload.php"
                       class="action-button action-primary">

                        <span>＋</span>
                        Upload New Document

                    </a>

                    <a href="/odms/documents/index.php"
                       class="action-button action-secondary">

                        <span>▤</span>
                        View All Documents

                    </a>

                    <?php if ($isAdmin): ?>

                        <a href="/odms/admin/users/index.php"
                           class="action-button action-secondary">

                            <span>♙</span>
                            Manage Users

                        </a>

                    <?php endif; ?>

                </div>

            </section>


            <!-- RECENT DOCUMENTS -->

            <section class="dashboard-panel">

                <div class="panel-header">

                    <div>

                        <h3 class="panel-title">
                            Recently Received Documents
                        </h3>

                        <p class="panel-description">
                            Latest five active document records
                        </p>

                    </div>

                    <a
                        href="/odms/documents/index.php"
                       
                    >
                    <button type="submit" class="logout-button">
                        View All >>
                    </a>



                </div>


                <div class="table-wrapper">

                    <table class="documents-table">

                        <thead>

                            <tr>

                                <th>Document</th>
                                <th>Category</th>
                                <th>Document Period</th>
                                <th>Uploaded By</th>
                                <th>Files</th>

                            </tr>

                        </thead>


                        <tbody>

                            <?php if (count($recentDocuments) > 0): ?>

                                <?php foreach ($recentDocuments as $document): ?>

                                    <tr>

                                        <td class="document-title">

                                            <?= htmlspecialchars(
                                                $document['title'] ?: $document['category_name']
                                            ) ?>

                                        </td>

                                        <td>

                                            <span class="category-label">
                                                <?= htmlspecialchars($document['category_name']) ?>
                                            </span>

                                        </td>

                                        <td>

                                            <?= htmlspecialchars(
                                                monthName((int) $document['document_month'])
                                                . ' ' . $document['document_year']
                                            ) ?>

                                        </td>

                                        <td>

                                            <?= htmlspecialchars($document['full_name']) ?>

                                        </td>

                                        <td>

                                            <span class="file-count">
                                                <?= number_format((int) $document['file_count']) ?>
                                            </span>

                                        </td>

                                    </tr>

                                <?php endforeach; ?>

                            <?php else: ?>

                                <tr>

                                    <td colspan="5" class="empty-state">

                                        <div class="text-2xl mb-2">▤</div>

                                        No documents have been uploaded yet.

                                    </td>

                                </tr>

                            <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </section>


            <!-- FOOTER -->

            <footer class="dashboard-footer">

                Internal Audit Service • City of Bais

            </footer>

        </main>

    </div>

</div>


<!-- =====================================
     EXISTING DASHBOARD TOAST NOTIFICATION
====================================== -->

<script src="https://cdn.jsdelivr.net/npm/toastify-js"></script>

<?php if ($toast): ?>

<script>

document.addEventListener('DOMContentLoaded', function () {

    const toastMessage = <?= json_encode(
        $toast['message'],
        JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
    ) ?>;

    Toastify({

        text: "⚠  " + toastMessage,

        duration: 3000,
        gravity: "top",
        position: "center",

        close: false,
        stopOnFocus: false,

        offset: {
            y: 20
        },

        className: "odms-toast",

        style: {
            background: "linear-gradient(135deg, #b91c1c, #dc2626)",
            borderRadius: "12px",
            padding: "15px 22px",
            fontSize: "14px",
            fontWeight: "500",
            lineHeight: "1.5",
            width: "380px",
            maxWidth: "calc(100vw - 30px)",
            boxShadow: "0 8px 25px rgba(15, 23, 42, 0.20)"
        }

    }).showToast();

});

</script>

<style>

.odms-toast {
    animation: odmsSlideDown 0.35s ease-out both;
}

@keyframes odmsSlideDown {

    from {
        opacity: 0;
        transform: translateY(-18px);
    }

    to {
        opacity: 1;
        transform: translateY(0);
    }

}

.toastify {
    z-index: 99999;
}

</style>

<?php endif; ?>

</body>
</html>