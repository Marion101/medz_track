<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

session_start();
require_once 'db.php';
require_once 'auth.php';

ensure_user_table($conn);
ensure_activity_log_table($conn);
bootstrap_session_from_cookie($conn);

if (!isset($_SESSION['user_email'])) {
    header('Location: admin_login.php');
    exit;
}

$currentEmail = (string) $_SESSION['user_email'];
$currentStmt = $conn->prepare('SELECT name, role FROM users WHERE email = ? LIMIT 1');
$currentStmt->bind_param('s', $currentEmail);
$currentStmt->execute();
$currentUser = $currentStmt->get_result()->fetch_assoc() ?: [];
$currentStmt->close();

$currentRole = (string) ($currentUser['role'] ?? ($_SESSION['user_role'] ?? 'user'));
if ($currentRole !== 'admin') {
    header('Location: admin_login.php');
    exit;
}

$_SESSION['user_role'] = 'admin';
$displayName = trim((string) ($currentUser['name'] ?? ''));
if ($displayName === '') {
    $displayName = $currentEmail;
}

// Filters
$todayDate = (new DateTimeImmutable('today'))->format('Y-m-d');
$selectedMonth = trim((string) ($_GET['month'] ?? ''));
if ($selectedMonth !== '' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $selectedMonth)) {
    $selectedMonth = '';
}
$selectedDay = trim((string) ($_GET['day'] ?? ''));
if ($selectedDay !== '' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])-[0-3]\d$/', $selectedDay)) {
    $selectedDay = '';
}
if ($selectedMonth !== '' && $selectedDay !== '' && strpos($selectedDay, $selectedMonth . '-') !== 0) {
    $selectedDay = '';
}

$availableMonths = $conn->query(
    "SELECT DISTINCT DATE_FORMAT(expiry_date, '%Y-%m') AS month_key
     FROM medicines
     WHERE expiry_date IS NOT NULL
     ORDER BY month_key DESC"
)->fetch_all(MYSQLI_ASSOC);

$monthLabel = 'All months';
if ($selectedMonth !== '') {
    $monthDate = DateTimeImmutable::createFromFormat('Y-m', $selectedMonth);
    if ($monthDate instanceof DateTimeImmutable) {
        $monthLabel = $monthDate->format('F Y');
    }
}
$dayLabel = 'All days';
if ($selectedDay !== '') {
    $dayDate = DateTimeImmutable::createFromFormat('Y-m-d', $selectedDay);
    if ($dayDate instanceof DateTimeImmutable) {
        $dayLabel = $dayDate->format('d M Y');
    }
}

$availableDaysSql =
    "SELECT DISTINCT DATE(expiry_date) AS day_key
     FROM medicines";
$availableDaysParams = [];
$availableDaysTypes = '';
if ($selectedMonth !== '') {
    $availableDaysSql .= " WHERE DATE_FORMAT(expiry_date, '%Y-%m') = ?";
    $availableDaysTypes = 's';
    $availableDaysParams[] = $selectedMonth;
}
$availableDaysSql .= " ORDER BY day_key DESC";
$availableDaysStmt = $conn->prepare($availableDaysSql);
if ($availableDaysTypes !== '') {
    $availableDaysStmt->bind_param($availableDaysTypes, ...$availableDaysParams);
}
$availableDaysStmt->execute();
$availableDays = $availableDaysStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$availableDaysStmt->close();

$whereParts = [];
$bindTypes = '';
$bindValues = [];
if ($selectedMonth !== '') {
    $whereParts[] = "DATE_FORMAT(expiry_date, '%Y-%m') = ?";
    $bindTypes .= 's';
    $bindValues[] = $selectedMonth;
}
if ($selectedDay !== '') {
    $whereParts[] = 'DATE(expiry_date) = ?';
    $bindTypes .= 's';
    $bindValues[] = $selectedDay;
}
$whereClause = $whereParts === [] ? '' : (' WHERE ' . implode(' AND ', $whereParts));
$whereClauseRecent = str_replace(
    ["DATE_FORMAT(expiry_date, '%Y-%m')", 'DATE(expiry_date)'],
    ["DATE_FORMAT(m.expiry_date, '%Y-%m')", 'DATE(m.expiry_date)'],
    $whereClause
);

// Summary stats
$usersCount = (int) ($conn->query('SELECT COUNT(*) AS total FROM users')->fetch_assoc()['total'] ?? 0);

$statsSql =
    "SELECT
        COUNT(*) AS medicines_total,
        SUM(CASE WHEN expiry_date < CURDATE() THEN 1 ELSE 0 END) AS expired_total,
        SUM(CASE WHEN expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS expiring7_total,
        SUM(CASE WHEN expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS expiring30_total,
        SUM(CASE WHEN quantity <= 5 THEN 1 ELSE 0 END) AS low_stock_total
     FROM medicines" . $whereClause;
$statsStmt = $conn->prepare($statsSql);
bind_stmt_params($statsStmt, $bindTypes, $bindValues);
$statsStmt->execute();
$statsRow = $statsStmt->get_result()->fetch_assoc() ?: [];
$statsStmt->close();

$medicinesCount = (int) ($statsRow['medicines_total'] ?? 0);
$expiredCount = (int) ($statsRow['expired_total'] ?? 0);
$expiring7Count = (int) ($statsRow['expiring7_total'] ?? 0);
$expiring30Count = (int) ($statsRow['expiring30_total'] ?? 0);
$lowStockCount = (int) ($statsRow['low_stock_total'] ?? 0);
$ownersSql = 'SELECT COUNT(DISTINCT user_email) AS total FROM medicines' . $whereClause;
$ownersStmt = $conn->prepare($ownersSql);
bind_stmt_params($ownersStmt, $bindTypes, $bindValues);
$ownersStmt->execute();
$ownersWithMedicines = (int) ($ownersStmt->get_result()->fetch_assoc()['total'] ?? 0);
$ownersStmt->close();

$addedTodaySql = "SELECT COUNT(*) AS total FROM medicines" . $whereClause;
$addedTodayTypes = $bindTypes;
$addedTodayValues = $bindValues;
if ($whereClause === '') {
    $addedTodaySql .= ' WHERE DATE(created_at) = CURDATE()';
} else {
    $addedTodaySql .= ' AND DATE(created_at) = CURDATE()';
}
$addedTodayStmt = $conn->prepare($addedTodaySql);
bind_stmt_params($addedTodayStmt, $addedTodayTypes, $addedTodayValues);
$addedTodayStmt->execute();
$addedToday = (int) ($addedTodayStmt->get_result()->fetch_assoc()['total'] ?? 0);
$addedTodayStmt->close();

$conn->query("ALTER TABLE medicines ADD COLUMN IF NOT EXISTS added_by_email VARCHAR(255) DEFAULT NULL AFTER user_email");
$conn->query("UPDATE medicines SET added_by_email = user_email WHERE added_by_email IS NULL OR added_by_email = ''");

// Medicines table
$recentSql =
    "SELECT
        m.medicine_name,
        m.category,
        m.quantity,
        m.expiry_date,
        m.created_at,
        m.user_email,
        m.added_by_email,
        owner.name AS owner_name,
        adder.name AS adder_name
     FROM medicines m
     LEFT JOIN users owner ON owner.email = m.user_email
     LEFT JOIN users adder ON adder.email = m.added_by_email
     " . $whereClauseRecent . "
     ORDER BY m.created_at DESC, m.id DESC
     LIMIT 200";
$recentStmt = $conn->prepare($recentSql);
bind_stmt_params($recentStmt, $bindTypes, $bindValues);
$recentStmt->execute();
$recentMedicines = $recentStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$recentStmt->close();

$activityWhere = [];
$activityTypes = '';
$activityValues = [];
if ($selectedMonth !== '') {
    $activityWhere[] = "DATE_FORMAT(created_at, '%Y-%m') = ?";
    $activityTypes .= 's';
    $activityValues[] = $selectedMonth;
}
if ($selectedDay !== '') {
    $activityWhere[] = 'DATE(created_at) = ?';
    $activityTypes .= 's';
    $activityValues[] = $selectedDay;
}
$activitySql = 'SELECT user_email, action, details, created_at FROM activity_log';
if ($activityWhere !== []) {
    $activitySql .= ' WHERE ' . implode(' AND ', $activityWhere);
}
$activitySql .= ' ORDER BY created_at DESC, id DESC LIMIT 100';
$activityStmt = $conn->prepare($activitySql);
bind_stmt_params($activityStmt, $activityTypes, $activityValues);
$activityStmt->execute();
$activityLogs = $activityStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$activityStmt->close();

$expiryWhere = [];
$expiryTypes = '';
$expiryValues = [];
if ($selectedMonth !== '') {
    $expiryWhere[] = "DATE_FORMAT(m.expiry_date, '%Y-%m') = ?";
    $expiryTypes .= 's';
    $expiryValues[] = $selectedMonth;
}
if ($selectedDay !== '') {
    $expiryWhere[] = 'm.expiry_date = ?';
    $expiryTypes .= 's';
    $expiryValues[] = $selectedDay;
}
$expirySql =
    "SELECT
        m.medicine_name,
        m.expiry_date,
        m.quantity,
        m.user_email,
        owner.name AS owner_name
     FROM medicines m
     LEFT JOIN users owner ON owner.email = m.user_email
     WHERE m.expiry_date IS NOT NULL";
if ($expiryWhere !== []) {
    $expirySql .= ' AND ' . implode(' AND ', $expiryWhere);
}
$expirySql .= ' ORDER BY m.expiry_date ASC, m.created_at DESC, m.id DESC LIMIT 300';
$expiryStmt = $conn->prepare($expirySql);
bind_stmt_params($expiryStmt, $expiryTypes, $expiryValues);
$expiryStmt->execute();
$expiryCalendarRows = $expiryStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$expiryStmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Reports-medztrack</title>
    <!-- Main CSS for this page is in Dashboard.css -->
    <link rel="stylesheet" href="Dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Extra CSS only for this report page -->
    <style>
        /* Report page base text and color resets */
        .report-page {
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            color: #1a1a1a !important;
        }
        .report-page .main-content,
        .report-page h2,
        .report-page h3,
        .report-page p,
        .report-page .stat-label,
        .report-page .stat-value {
            color: #1a1a1a !important;
        }
        .report-page .stats-section .stat-card {
            background: #ffffff !important;
            border: 1px solid #ecdde3 !important;
        }
        .report-page .stats-section .stat-label {
            color: #3a2a31 !important;
            font-weight: 600 !important;
        }
        .report-page .stats-section .stat-value {
            color: #1a1a1a !important;
            font-weight: 700 !important;
        }
        .report-page .admin-section h3,
        .report-page .dev-panel h3 {
            color: #2a1a21 !important;
        }

        /* Disable hover effects inherited from shared dashboard styles */
        .report-page a:hover,
        .report-page button:hover,
        .report-page .add-btn:hover,
        .report-page .export-btn:hover,
        .report-page .dev-btn:hover {
            transform: none !important;
            box-shadow: none !important;
            filter: none !important;
            background: inherit !important;
        }

        /* Top report intro panel */
        .admin-report-hero {
            background: linear-gradient(135deg, #ffe3ea 0%, #fff5e8 100%);
            border: 1px solid #f7d4dd;
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 16px;
        }
        .admin-report-hero h3 {
            margin: 0 0 8px;
            color: #7a3f54;
        }
        .admin-report-hero p {
            margin: 0;
            color: #2f2f2f !important;
        }

        /* Report table container and table colors */
        .admin-report-wrap {
            background: #ffffff !important;
            border: 1px solid #f0e5ea;
            border-radius: 16px;
            padding: 8px;
            overflow: auto;
        }
        .admin-report-wrap .dev-table {
            background: #ffffff !important;
        }
        .admin-report-wrap .dev-table th {
            background: #fff0f5;
            color: #2f1b25 !important;
            border-bottom: 1px solid #efdde5;
        }
        .admin-report-wrap .dev-table td {
            border-bottom: 1px solid #f5edf1;
            color: #1a1a1a !important;
        }
        .admin-report-wrap .dev-table tr:nth-child(even) td {
            background: #fffdfd;
        }
        .admin-report-wrap .dev-table tbody tr:hover,
        .admin-report-wrap .dev-table tbody tr:hover td {
            background: #ffffff !important;
        }

        /* Month and day filter controls */
        .month-filter-form {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-top: 12px;
            flex-wrap: wrap;
        }
        .month-filter-form label {
            color: #2f2f2f !important;
            font-weight: 600;
        }
        .month-filter-form select {
            padding: 8px 10px;
            border: 1px solid #e0d4da;
            border-radius: 8px;
            background: #fff;
            color: #1a1a1a;
        }
        .month-filter-form .add-btn {
            border: none;
            cursor: pointer;
        }
        .month-meta {
            margin-top: 8px;
            color: #3b2b32 !important;
            font-size: 14px;
        }

        /* Print button */
        .print-btn {
            text-decoration: none;
            border: none;
            cursor: pointer;
        }

        /* Small status color helpers */
        .soft-pink { background: #ffe8ef; color: #a24c6c; }
        .soft-yellow { background: #fff5d6; color: #9a7a13; }
        .soft-blue { background: #e8f2ff; color: #2f5f9a; }

        /* Rounded status label */
        .pill {
            display: inline-block;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 12px;
            font-weight: 700;
        }

        /* Keep report print/export areas light even when dark theme is active */
        body.dark-theme.report-page .main-content,
        body.dark-theme.report-page .dev-panel,
        body.dark-theme.report-page .stat-card,
        body.dark-theme.report-page .admin-report-wrap,
        body.dark-theme.report-page .admin-report-wrap .dev-table {
            background: #ffffff !important;
            border-color: #ecdde3 !important;
        }
        body.dark-theme.report-page .top-header h2,
        body.dark-theme.report-page .dev-panel h3,
        body.dark-theme.report-page .stat-label,
        body.dark-theme.report-page .stat-value,
        body.dark-theme.report-page .dev-table th,
        body.dark-theme.report-page .dev-table td,
        body.dark-theme.report-page .sidebar-note {
            color: #1a1a1a !important;
        }
        body.dark-theme.report-page .dev-table tbody tr:hover,
        body.dark-theme.report-page .dev-table tbody tr:hover td {
            background: #ffffff !important;
        }

        /* Print-only layout cleanup */
        @media print {
            body.report-page {
                background: #fff !important;
                color: #000 !important;
            }
            .sidebar,
            .logout-btn,
            .month-filter-form,
            .print-hide {
                display: none !important;
            }
            .dashboard-container,
            .main-content,
            .admin-report-wrap {
                width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                border: none !important;
                box-shadow: none !important;
            }
            .dev-table th,
            .dev-table td {
                color: #000 !important;
                border-color: #ddd !important;
            }
        }
    </style>
</head>
<body class="<?= htmlspecialchars(theme_body_class('admin-console')) ?> report-page">
    <div class="dashboard-container">
        <aside class="sidebar">
            <div class="sidebar-header">
                <h1><i class="fas fa-shield-halved"></i> Admin</h1>
                <p class="sidebar-note">Manage users, medicines, roles, and logs.</p>
            </div>
            <nav class="sidebar-nav">
                <a href="admin.php" class="nav-item"><i class="fas fa-chart-pie"></i> Overview</a>
                <a href="admin_users.php" class="nav-item"><i class="fas fa-users"></i> Users & Roles</a>
                <a href="admin_medicines.php" class="nav-item"><i class="fas fa-pills"></i> Medicines</a>
                <a href="admin_logs.php" class="nav-item"><i class="fas fa-clipboard-list"></i> Activity Logs</a>
                <a href="admin_reports.php" class="nav-item active"><i class="fas fa-file-lines"></i> Reports</a>
            </nav>
            <form action="logout.php" method="post">
                <button type="submit" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</button>
            </form>
        </aside>

        <main class="main-content">
            <header class="top-header">
                <h2>Admin Report</h2>
            </header>

            <div class="admin-report-hero">
                <h3><i class="fas fa-file-lines"></i> Admin report</h3>
                <p>Report date: <strong><?= htmlspecialchars($todayDate) ?></strong>.</p>
                <form method="get" action="admin_reports.php" class="month-filter-form">
                    <label for="month">View month:</label>
                    <select name="month" id="month" onchange="this.form.submit()">
                        <option value="">All months</option>
                        <?php foreach ($availableMonths as $monthRow): ?>
                            <?php
                            $monthKey = (string) ($monthRow['month_key'] ?? '');
                            if ($monthKey === '') {
                                continue;
                            }
                            $monthDate = DateTimeImmutable::createFromFormat('Y-m', $monthKey);
                            $monthText = $monthDate instanceof DateTimeImmutable ? $monthDate->format('F Y') : $monthKey;
                            ?>
                            <option value="<?= htmlspecialchars($monthKey) ?>" <?= $selectedMonth === $monthKey ? 'selected' : '' ?>>
                                <?= htmlspecialchars($monthText) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <label for="day">View day:</label>
                    <select name="day" id="day" onchange="this.form.submit()">
                        <option value="">All days</option>
                        <?php foreach ($availableDays as $dayRow): ?>
                            <?php
                            $dayKey = (string) ($dayRow['day_key'] ?? '');
                            if ($dayKey === '') {
                                continue;
                            }
                            $dayDate = DateTimeImmutable::createFromFormat('Y-m-d', $dayKey);
                            $dayText = $dayDate instanceof DateTimeImmutable ? $dayDate->format('d M Y') : $dayKey;
                            ?>
                            <option value="<?= htmlspecialchars($dayKey) ?>" <?= $selectedDay === $dayKey ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dayText) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($selectedMonth !== '' || $selectedDay !== ''): ?>
                        <a href="admin_reports.php" class="export-btn"><i class="fas fa-rotate-left"></i> Clear</a>
                    <?php endif; ?>
                    <button type="button" class="add-btn print-btn print-hide" onclick="window.print()"><i class="fas fa-print"></i> Print / PDF</button>
                </form>
                <p class="month-meta">Showing data for: <strong><?= htmlspecialchars($monthLabel) ?></strong> | <strong><?= htmlspecialchars($dayLabel) ?></strong></p>
            </div>

            <section class="stats-section">
                <div class="stat-card">
                    <div class="stat-icon" style="background: #F4C9D6;"><i class="fas fa-users"></i></div>
                    <div class="stat-info">
                        <p class="stat-label">Total Users</p>
                        <p class="stat-value"><?= $usersCount ?></p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: #6bcf7f;"><i class="fas fa-pills"></i></div>
                    <div class="stat-info">
                        <p class="stat-label">Total Medicines</p>
                        <p class="stat-value"><?= $medicinesCount ?></p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: #ffd93d;"><i class="fas fa-hourglass-half"></i></div>
                    <div class="stat-info">
                        <p class="stat-label">Expire in 7 Days</p>
                        <p class="stat-value"><?= $expiring7Count ?></p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: #ffbf69;"><i class="fas fa-clock"></i></div>
                    <div class="stat-info">
                        <p class="stat-label">Expire in 30 Days</p>
                        <p class="stat-value"><?= $expiring30Count ?></p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: #ff6b6b;"><i class="fas fa-triangle-exclamation"></i></div>
                    <div class="stat-info">
                        <p class="stat-label">Expired</p>
                        <p class="stat-value"><?= $expiredCount ?></p>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: #8ec5ff;"><i class="fas fa-box-open"></i></div>
                    <div class="stat-info">
                        <p class="stat-label">Low Stock (5 or less)</p>
                        <p class="stat-value"><?= $lowStockCount ?></p>
                    </div>
                </div>
            </section>

            <section class="admin-section">
                <div class="dev-panel">
                    <h3>Summary Report</h3>
                    <div class="dev-table-wrap admin-report-wrap" style="margin-bottom: 16px;">
                        <table class="dev-table">
                            <thead>
                                <tr>
                                    <th>Item</th>
                                    <th>Value</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr><td>Total Medicines</td><td><span class="pill soft-pink"><?= $medicinesCount ?></span></td><td>All medicines in selected scope</td></tr>
                                <tr><td>Users With Medicines</td><td><span class="pill soft-blue"><?= $ownersWithMedicines ?></span></td><td>Users with at least one medicine</td></tr>
                                <tr><td>Added Today</td><td><span class="pill soft-pink"><?= $addedToday ?></span></td><td><?= htmlspecialchars($todayDate) ?></td></tr>
                                <tr><td>Expire in 7 Days</td><td><span class="pill soft-yellow"><?= $expiring7Count ?></span></td><td>From today to next 7 days</td></tr>
                                <tr><td>Expire in 30 Days</td><td><span class="pill soft-yellow"><?= $expiring30Count ?></span></td><td>From today to next 30 days</td></tr>
                                <tr><td>Expired</td><td><span class="pill soft-pink"><?= $expiredCount ?></span></td><td>Past expiry date</td></tr>
                                <tr><td>Low Stock</td><td><span class="pill soft-yellow"><?= $lowStockCount ?></span></td><td>Quantity 5 or less</td></tr>
                            </tbody>
                        </table>
                    </div>

                    <h3>Recently Added Medicines (max 200)</h3>
                    <div class="dev-table-wrap admin-report-wrap">
                        <table class="dev-table">
                            <thead>
                                <tr>
                                    <th>Medicine</th>
                                    <th>For User</th>
                                    <th>Added By</th>
                                    <th>Qty</th>
                                    <th>Expiry</th>
                                    <th>Status</th>
                                    <th>Date Added</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($recentMedicines === []): ?>
                                    <tr><td colspan="8" class="empty-cell">No medicine records yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($recentMedicines as $row): ?>
                                        <?php
                                        $expiry = (string) ($row['expiry_date'] ?? '');
                                        $status = 'Good';
                                        if ($expiry !== '' && $expiry < $todayDate) {
                                            $status = 'Expired';
                                        } elseif ($expiry !== '' && $expiry <= (new DateTimeImmutable('today +30 days'))->format('Y-m-d')) {
                                            $status = 'Expiring Soon';
                                        }
                                        $ownerLabel = (string) (($row['owner_name'] ?? '') !== '' ? $row['owner_name'] : ($row['user_email'] ?? ''));
                                        $addedByLabel = (string) (($row['adder_name'] ?? '') !== '' ? $row['adder_name'] : ($row['added_by_email'] ?? ''));
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string) ($row['medicine_name'] ?? '')) ?></td>
                                            <td><?= htmlspecialchars($ownerLabel) ?></td>
                                            <td><?= htmlspecialchars($addedByLabel) ?></td>
                                            <td><?= (int) ($row['quantity'] ?? 0) ?></td>
                                            <td><?= htmlspecialchars($expiry) ?></td>
                                            <td><?= htmlspecialchars($status) ?></td>
                                            <td><?= htmlspecialchars((string) ($row['created_at'] ?? '')) ?></td>
                                            <td><?= htmlspecialchars((string) ($row['category'] ?? 'Other')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <h3 style="margin-top: 18px;">User Activity Report (max 100)</h3>
                    <div class="dev-table-wrap admin-report-wrap">
                        <table class="dev-table">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>User</th>
                                    <th>Action</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($activityLogs === []): ?>
                                    <tr><td colspan="4" class="empty-cell">No activity logs in this filter.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($activityLogs as $log): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string) ($log['created_at'] ?? '')) ?></td>
                                            <td><?= htmlspecialchars((string) ($log['user_email'] ?? '')) ?></td>
                                            <td><span class="pill soft-blue"><?= htmlspecialchars((string) ($log['action'] ?? '')) ?></span></td>
                                            <td><?= htmlspecialchars((string) ($log['details'] ?? '')) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <h3 style="margin-top: 18px;">Expiry Calendar (max 300)</h3>
                    <div class="dev-table-wrap admin-report-wrap">
                        <table class="dev-table">
                            <thead>
                                <tr>
                                    <th>Expiry Date</th>
                                    <th>Medicine</th>
                                    <th>For User</th>
                                    <th>Qty</th>
                                    <th>Days Left</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($expiryCalendarRows === []): ?>
                                    <tr><td colspan="6" class="empty-cell">No expiry records in this filter.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($expiryCalendarRows as $item): ?>
                                        <?php
                                        $expiryDate = (string) ($item['expiry_date'] ?? '');
                                        $ownerLabel = (string) (($item['owner_name'] ?? '') !== '' ? $item['owner_name'] : ($item['user_email'] ?? ''));
                                        $status = 'Good';
                                        $daysLeftLabel = '-';
                                        if ($expiryDate !== '') {
                                            $todayObj = new DateTimeImmutable($todayDate);
                                            $expiryObj = new DateTimeImmutable($expiryDate);
                                            $daysLeft = (int) $todayObj->diff($expiryObj)->format('%r%a');
                                            $daysLeftLabel = (string) $daysLeft;
                                            if ($daysLeft < 0) {
                                                $status = 'Expired';
                                            } elseif ($daysLeft <= 30) {
                                                $status = 'Expiring Soon';
                                            }
                                        }
                                        ?>
                                        <tr>
                                            <td><?= htmlspecialchars($expiryDate) ?></td>
                                            <td><?= htmlspecialchars((string) ($item['medicine_name'] ?? '')) ?></td>
                                            <td><?= htmlspecialchars($ownerLabel) ?></td>
                                            <td><?= (int) ($item['quantity'] ?? 0) ?></td>
                                            <td><?= htmlspecialchars($daysLeftLabel) ?></td>
                                            <td>
                                                <span class="pill <?= $status === 'Expired' ? 'soft-pink' : ($status === 'Expiring Soon' ? 'soft-yellow' : 'soft-blue') ?>">
                                                    <?= htmlspecialchars($status) ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
