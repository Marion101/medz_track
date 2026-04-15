<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

session_start();
require_once 'db.php';
require_once 'auth.php';

ensure_user_table($conn);
require_auth($conn);

if (!isset($_SESSION['user_email'])) {
    header('Location: login.php');
    exit;
}

$email = (string) $_SESSION['user_email'];
$userStmt = $conn->prepare('SELECT name FROM users WHERE email = ? LIMIT 1');
$userStmt->bind_param('s', $email);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc() ?: [];
$userStmt->close();

$displayName = trim((string) ($user['name'] ?? ''));
if ($displayName === '') {
    $displayName = $email;
}

$todayDate = (new DateTimeImmutable('today'))->format('Y-m-d');
$weekStartDate = (new DateTimeImmutable('today -6 days'))->format('Y-m-d');
$monthStartDate = (new DateTimeImmutable('first day of this month'))->format('Y-m-d');

$conn->query("ALTER TABLE medicines ADD COLUMN IF NOT EXISTS added_by_email VARCHAR(255) DEFAULT NULL AFTER user_email");
$conn->query("UPDATE medicines SET added_by_email = user_email WHERE added_by_email IS NULL OR added_by_email = ''");

$totalMedicines = (int) ($conn->query('SELECT COUNT(*) AS total FROM medicines')->fetch_assoc()['total'] ?? 0);
$expired = (int) ($conn->query('SELECT COUNT(*) AS total FROM medicines WHERE expiry_date < CURDATE()')->fetch_assoc()['total'] ?? 0);
$expiringToday = (int) ($conn->query('SELECT COUNT(*) AS total FROM medicines WHERE expiry_date = CURDATE()')->fetch_assoc()['total'] ?? 0);
$expiring7Days = (int) ($conn->query("SELECT COUNT(*) AS total FROM medicines WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)")->fetch_assoc()['total'] ?? 0);
$expiring30Days = (int) ($conn->query("SELECT COUNT(*) AS total FROM medicines WHERE expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)")->fetch_assoc()['total'] ?? 0);
$lowStock = (int) ($conn->query('SELECT COUNT(*) AS total FROM medicines WHERE quantity <= 5')->fetch_assoc()['total'] ?? 0);
$addedToday = (int) ($conn->query('SELECT COUNT(*) AS total FROM medicines WHERE DATE(created_at) = CURDATE()')->fetch_assoc()['total'] ?? 0);
$addedThisWeek = (int) ($conn->query('SELECT COUNT(*) AS total FROM medicines WHERE DATE(created_at) BETWEEN DATE_SUB(CURDATE(), INTERVAL 6 DAY) AND CURDATE()')->fetch_assoc()['total'] ?? 0);
$addedThisMonth = (int) ($conn->query("SELECT COUNT(*) AS total FROM medicines WHERE DATE(created_at) >= DATE_FORMAT(CURDATE(), '%Y-%m-01')")->fetch_assoc()['total'] ?? 0);
$ownersWithMedicines = (int) ($conn->query('SELECT COUNT(DISTINCT user_email) AS total FROM medicines')->fetch_assoc()['total'] ?? 0);

$recentMedicines = $conn->query(
    "SELECT
        m.medicine_name,
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
     LIMIT 20"
)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports-medztrack</title>
    <link rel="stylesheet" href="Dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .report-page {
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
                background: #f9f5f7;
                min-height: 100vh;      
        }
        .report-page .main-content,
        .report-page h2,
        .report-page h3,
        .report-page p,
        .report-page th,
        .report-page td,
        .report-page .stat-label,
        .report-page .stat-value {
            color: #1f1f1f !important;
        }
        .report-page a:hover,
        .report-page button:hover,
        .report-page .add-btn:hover,
        .report-page .export-btn:hover {
            transform: none !important;
            box-shadow: none !important;
            filter: none !important;
            background: inherit !important;
        }
        .report-hero {
            background: linear-gradient(135deg, #ffe3ea 0%, #fff5e8 100%);
            border: 1px solid #f7d4dd;
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 16px;
        }
        .report-hero h3 {
            margin: 0 0 8px;
            color: #4a2634 !important;
        }
        .report-hero p {
            margin: 0;
            color: #2f2f2f !important;
        }
        .report-cards {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 12px;
            margin: 12px 0 18px;
        }
        .report-card {
            background: #fff;
            border: 1px solid #f0e5ea;
            border-radius: 14px;
            padding: 12px;
            box-shadow: 0 8px 20px rgba(0, 0, 0, 0.04);
        }
        .report-card .label {
            margin: 0;
            font-size: 12px;
            color: #3d3338 !important;
        }
        .report-card .value {
            margin: 6px 0 0;
            font-size: 24px;
            font-weight: 700;
            color: #1f1f1f !important;
        }
        .report-table-wrap {
            background: #fff;
            border: 1px solid #f0e5ea;
            border-radius: 16px;
            padding: 8px;
            overflow: auto;
        }
        .report-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 700px;
        }
        .report-table th {
            background: #fff0f5;
            color: #3d2732 !important;
            font-weight: 700;
            text-align: left;
            padding: 12px;
            border-bottom: 1px solid #efdde5;
        }
        .report-table td {
            padding: 12px;
            border-bottom: 1px solid #f5edf1;
            color: #1f1f1f !important;
        }
        .report-table tr:nth-child(even) td {
            background: #fffdfd;
        }
        .pill {
            display: inline-block;
            border-radius: 999px;
            padding: 4px 10px;
            font-size: 12px;
            font-weight: 700;
        }
        .pill.soft-pink { background: #ffe8ef; color: #a24c6c; }
        .pill.soft-yellow { background: #fff5d6; color: #9a7a13; }
        .pill.soft-blue { background: #e8f2ff; color: #2f5f9a; }
        @media (max-width: 1024px) {
            .report-cards { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (max-width: 640px) {
            .report-cards { grid-template-columns: 1fr; }
            .report-card .value { font-size: 22px; }
        }
    </style>
</head>
<body class="<?= htmlspecialchars(theme_body_class()) ?> report-page">
    <div class="dashboard-container">
        <aside class="sidebar">
            <div class="sidebar-header">
                <h1><i class="fas fa-pills"></i> Medz track</h1>
            </div>
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-item"><i class="fas fa-home"></i> Dashboard</a>
                <a href="add_medicine.php" class="nav-item"><i class="fas fa-plus-circle"></i> Add Medicine</a>
                <a href="my_medicines.php" class="nav-item"><i class="fas fa-list"></i> My Medicines</a>
                <a href="alerts.php" class="nav-item"><i class="fas fa-bell"></i> Alerts</a>
                <a href="user_reports.php" class="nav-item active"><i class="fas fa-file-lines"></i> Reports</a>
                <a href="profile.php" class="nav-item"><i class="fas fa-user"></i> Profile</a>
            </nav>
            <form action="logout.php" method="post">
                <button class="logout-btn" type="submit"><i class="fas fa-sign-out-alt"></i> Logout</button>
            </form>
        </aside>

        <main class="main-content">
            <header class="top-header">
                <h2>Your Report, <span id="username"><?= htmlspecialchars($displayName) ?></span></h2>
                <div class="header-actions">
                    <a href="dashboard.php" class="add-btn"><i class="fas fa-home"></i> Dashboard</a>
                </div>
            </header>

            <div class="report-hero">
                <h3><i class="fas fa-file-lines"></i> Your medicine report</h3>
                <p>This report is for <strong><?= htmlspecialchars($todayDate) ?></strong>.</p>
            </div>

            <div class="report-cards">
                <div class="report-card">
                    <p class="label">Total Medicines</p>
                    <p class="value"><?= $totalMedicines ?></p>
                </div>
                <div class="report-card">
                    <p class="label">Added Today</p>
                    <p class="value"><?= $addedToday ?></p>
                </div>
                <div class="report-card">
                    <p class="label">Expire in 30 Days</p>
                    <p class="value"><?= $expiring30Days ?></p>
                </div>
                <div class="report-card">
                    <p class="label">Expired</p>
                    <p class="value"><?= $expired ?></p>
                </div>
            </div>

            <section class="medicines-section">
                <h3>Simple Summary</h3>
                <div class="report-table-wrap">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th>Value</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td>Total Medicines</td><td><span class="pill soft-pink"><?= $totalMedicines ?></span></td><td>All medicines in the system</td></tr>
                            <tr><td>Users With Medicines</td><td><span class="pill soft-blue"><?= $ownersWithMedicines ?></span></td><td>People who have at least 1 medicine</td></tr>
                            <tr><td>Added Today</td><td><span class="pill soft-pink"><?= $addedToday ?></span></td><td><?= htmlspecialchars($todayDate) ?></td></tr>
                            <tr><td>Added Last 7 Days</td><td><span class="pill soft-blue"><?= $addedThisWeek ?></span></td><td>From <?= htmlspecialchars($weekStartDate) ?> to <?= htmlspecialchars($todayDate) ?></td></tr>
                            <tr><td>Added This Month</td><td><span class="pill soft-blue"><?= $addedThisMonth ?></span></td><td>From <?= htmlspecialchars($monthStartDate) ?> to <?= htmlspecialchars($todayDate) ?></td></tr>
                            <tr><td>Expiring Today</td><td><span class="pill soft-yellow"><?= $expiringToday ?></span></td><td>Medicines that expire today</td></tr>
                            <tr><td>Expiring In 7 Days</td><td><span class="pill soft-yellow"><?= $expiring7Days ?></span></td><td>Will expire in the next 7 days</td></tr>
                            <tr><td>Expiring In 30 Days</td><td><span class="pill soft-yellow"><?= $expiring30Days ?></span></td><td>Will expire in the next 30 days</td></tr>
                            <tr><td>Expired</td><td><span class="pill soft-pink"><?= $expired ?></span></td><td>Already past expiry date</td></tr>
                            <tr><td>Low Stock</td><td><span class="pill soft-yellow"><?= $lowStock ?></span></td><td>Amount is 5 or less</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="medicines-section">
                <h3>Recent Medicines</h3>
                <div class="report-table-wrap">
                    <table class="report-table">
                        <thead>
                            <tr>
                                <th>Medicine</th>
                                <th>For User</th>
                                <th>Added By</th>
                                <th>Qty</th>
                                <th>Expiry</th>
                                <th>Added On</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($recentMedicines === []): ?>
                                <tr>
                                    <td colspan="6">No medicines yet.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($recentMedicines as $row): ?>
                                    <?php
                                    $ownerLabel = (string) (($row['owner_name'] ?? '') !== '' ? $row['owner_name'] : ($row['user_email'] ?? ''));
                                    $addedByLabel = (string) (($row['adder_name'] ?? '') !== '' ? $row['adder_name'] : ($row['added_by_email'] ?? ''));
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars((string) ($row['medicine_name'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars($ownerLabel) ?></td>
                                        <td><span class="pill soft-blue"><?= htmlspecialchars($addedByLabel) ?></span></td>
                                        <td><?= (int) ($row['quantity'] ?? 0) ?></td>
                                        <td><?= htmlspecialchars((string) ($row['expiry_date'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars((string) ($row['created_at'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
