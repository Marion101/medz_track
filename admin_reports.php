<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

session_start();
require_once 'db.php';
require_once 'auth.php';

ensure_user_table($conn);
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

$todayDate = (new DateTimeImmutable('today'))->format('Y-m-d');

$usersCount = (int) ($conn->query('SELECT COUNT(*) AS total FROM users')->fetch_assoc()['total'] ?? 0);
$medicinesCount = (int) ($conn->query('SELECT COUNT(*) AS total FROM medicines')->fetch_assoc()['total'] ?? 0);
$expiredCount = (int) ($conn->query('SELECT COUNT(*) AS total FROM medicines WHERE expiry_date < CURDATE()')->fetch_assoc()['total'] ?? 0);
$expiring7Count = (int) ($conn->query("SELECT COUNT(*) AS total FROM medicines WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetch_assoc()['total'] ?? 0);
$expiring30Count = (int) ($conn->query("SELECT COUNT(*) AS total FROM medicines WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetch_assoc()['total'] ?? 0);
$lowStockCount = (int) ($conn->query('SELECT COUNT(*) AS total FROM medicines WHERE quantity <= 5')->fetch_assoc()['total'] ?? 0);

$conn->query("ALTER TABLE medicines ADD COLUMN IF NOT EXISTS added_by_email VARCHAR(255) DEFAULT NULL AFTER user_email");
$conn->query("UPDATE medicines SET added_by_email = user_email WHERE added_by_email IS NULL OR added_by_email = ''");

$recentMedicines = $conn->query(
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
     ORDER BY m.created_at DESC, m.id DESC
     LIMIT 200"
)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Reports-medztrack</title>
    <link rel="stylesheet" href="Dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
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
                <h2>Admin Report, <span id="username"><?= htmlspecialchars($displayName) ?></span></h2>
            </header>

            <div class="admin-report-hero">
                <h3><i class="fas fa-file-lines"></i> Admin report</h3>
                <p>Report date: <strong><?= htmlspecialchars($todayDate) ?></strong>.</p>
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
                    <h3>Recently Added Medicines (Last 200)</h3>
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
                </div>
            </section>
        </main>
    </div>
</body>
</html>
