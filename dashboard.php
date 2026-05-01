<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

session_start();
require_once 'db.php';
require_once 'auth.php';

// Check login
ensure_user_table($conn);
require_auth($conn);

if (!isset($_SESSION['user_email'])) {
    header('Location: login.php');
    exit;
}

$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS name VARCHAR(100) DEFAULT NULL AFTER id");
$conn->query(
    "CREATE TABLE IF NOT EXISTS medicines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_email VARCHAR(255) NOT NULL,
        medicine_name VARCHAR(255) NOT NULL,
        dosage VARCHAR(100) DEFAULT '',
        quantity INT NOT NULL,
        expiry_date DATE NOT NULL,
        category VARCHAR(100) DEFAULT 'Other',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"
);
$conn->query("ALTER TABLE medicines ADD COLUMN IF NOT EXISTS category VARCHAR(100) DEFAULT 'Other' AFTER expiry_date");

$email = $_SESSION['user_email'];
$message = isset($_GET['added']) ? 'Medicine added successfully.' : null;
$messageType = 'success';

// Get current user name
$userStmt = $conn->prepare('SELECT name FROM users WHERE email = ?');
$userStmt->bind_param('s', $email);
$userStmt->execute();
$userResult = $userStmt->get_result();
$user = $userResult->fetch_assoc() ?: [];
$userStmt->close();

$displayName = trim((string) ($user['name'] ?? ''));
if ($displayName === '') {
    $displayName = $email;
}

// Get medicine dates for the dashboard totals
$medicines = $conn->query(
    'SELECT m.expiry_date
     FROM medicines m
     ORDER BY m.expiry_date ASC, m.id DESC'
)->fetch_all(MYSQLI_ASSOC);

$totalMedicines = count($medicines);
$expiringSoon = 0;
$expired = 0;
$lowStock = (int) ($conn->query('SELECT COUNT(*) AS total FROM medicines WHERE quantity <= 5')->fetch_assoc()['total'] ?? 0);
$addedToday = (int) ($conn->query('SELECT COUNT(*) AS total FROM medicines WHERE DATE(created_at) = CURDATE()')->fetch_assoc()['total'] ?? 0);
$today = new DateTimeImmutable('today');

// Count expired and expiring medicines
foreach ($medicines as $medicine) {
    $expiryDate = DateTimeImmutable::createFromFormat('Y-m-d', (string) $medicine['expiry_date']) ?: $today;
    $daysLeft = (int) $today->diff($expiryDate)->format('%r%a');

    if ($daysLeft < 0) {
        $expired++;
    } elseif ($daysLeft <= 30) {
        $expiringSoon++;
    }

}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard-medztrack</title>
    <!-- CSS for this page is in Dashboard.css -->
    <link rel="stylesheet" href="Dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="<?= htmlspecialchars(theme_body_class()) ?>">
    <div class="dashboard-container">
        <!-- Sidebar menu -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h1><i class="fas fa-pills"></i> Medz track</h1>
            </div>
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-item active"><i class="fas fa-home"></i> Dashboard</a>
                <a href="add_medicine.php" class="nav-item"><i class="fas fa-plus-circle"></i> Add Medicine</a>
                <a href="my_medicines.php" class="nav-item"><i class="fas fa-list"></i> My Medicines</a>
                <a href="alerts.php" class="nav-item"><i class="fas fa-bell"></i> Alerts</a>
                <a href="user_calendar.php" class="nav-item"><i class="fas fa-calendar-days"></i> Calendar</a>
                <a href="profile.php" class="nav-item"><i class="fas fa-user"></i> Profile</a>
            </nav>
            <form action="logout.php" method="post">
                <button class="logout-btn" type="submit"><i class="fas fa-sign-out-alt"></i> Logout</button>
            </form>
        </aside>

        <main class="main-content">
            <!-- Page header -->
            <header class="top-header">
        <h2>Hey, <span id="username"><?= htmlspecialchars($displayName) ?></span>!</h2>
                <div class="header-actions">
                    <a href="add_medicine.php" class="add-btn"><i class="fas fa-plus"></i> Add Medicine</a>
                </div>
            </header>

            <!-- Success or error message -->
            <?php if ($message !== null): ?>
                <div class="message <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <!-- Main dashboard summary -->
            <div class="dashboard-note">
                <i class="fas fa-circle-info"></i>
                <span>
                    You have <strong><?= $totalMedicines ?></strong> medicines, with
                    <strong><?= $expiringSoon ?></strong> expiring soon and
                    <strong><?= $expired ?></strong> expired.
                </span>
            </div>

            <!-- Main statistics -->
            <section class="stats-section">
                <div class="stat-card">
                    <div class="stat-icon" style="background: #F4C9D6;">
                        <i class="fas fa-capsules"></i>
                    </div>
                    <div class="stat-info">
                        <p class="stat-label">Total Medicines</p>
                        <p class="stat-value"><?= $totalMedicines ?></p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon" style="background: #ffd93d;">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="stat-info">
                        <p class="stat-label">Expiring Soon</p>
                        <p class="stat-value"><?= $expiringSoon ?></p>
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon" style="background: #ff6b6b;">
                        <i class="fas fa-times-circle"></i>
                    </div>
                    <div class="stat-info">
                        <p class="stat-label">Expired</p>
                        <p class="stat-value"><?= $expired ?></p>
                    </div>
                </div>

            </section>

            <!-- Quick report cards -->
            <section class="medicines-section" style="margin-top: 10px;">
                <h3>Quick Report</h3>
                <p class="setting-note" style="margin-bottom: 14px;">Simple summary for today.</p>
                <div class="stats-section">
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #c9f4de;">
                            <i class="fas fa-calendar-day"></i>
                        </div>
                        <div class="stat-info">
                            <p class="stat-label">Added Today</p>
                            <p class="stat-value"><?= $addedToday ?></p>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background: #8ec5ff;">
                            <i class="fas fa-box-open"></i>
                        </div>
                        <div class="stat-info">
                            <p class="stat-label">Low Stock</p>
                            <p class="stat-value"><?= $lowStock ?></p>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
