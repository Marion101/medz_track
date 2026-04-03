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

$stmt = $conn->prepare('SELECT medicine_name, dosage, quantity, expiry_date, category FROM medicines WHERE user_email = ? ORDER BY expiry_date ASC');
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();
$medicines = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalMedicines = count($medicines);
$expiringSoon = 0;
$expired = 0;
$goodStock = 0;
$today = new DateTimeImmutable('today');

foreach ($medicines as $medicine) {
    $expiryDate = DateTimeImmutable::createFromFormat('Y-m-d', (string) $medicine['expiry_date']) ?: $today;
    $daysLeft = (int) $today->diff($expiryDate)->format('%r%a');

    if ($daysLeft < 0) {
        $expired++;
    } elseif ($daysLeft <= 30) {
        $expiringSoon++;
    }

    if ((int) $medicine['quantity'] > 5) {
        $goodStock++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Medicine Expiry Tracker</title>
    <link rel="stylesheet" href="Dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="<?= htmlspecialchars(theme_body_class()) ?>">
    <div class="dashboard-container">
        <aside class="sidebar">
            <div class="sidebar-header">
                <h1><i class="fas fa-pills"></i> Medz track</h1>
            </div>
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-item active"><i class="fas fa-home"></i> Dashboard</a>
                <a href="add_medicine.php" class="nav-item"><i class="fas fa-plus-circle"></i> Add Medicine</a>
                <a href="my_medicines.php" class="nav-item"><i class="fas fa-list"></i> My Medicines</a>
                <a href="alerts.php" class="nav-item"><i class="fas fa-bell"></i> Alerts</a>
                <a href="profile.php" class="nav-item"><i class="fas fa-user"></i> Profile</a>
            </nav>
            <form action="logout.php" method="post">
                <button class="logout-btn" type="submit"><i class="fas fa-sign-out-alt"></i> Logout</button>
            </form>
        </aside>

        <main class="main-content">
            <header class="top-header">
                <h2>Welcome back, <span id="username"><?= htmlspecialchars($displayName) ?></span>!</h2>
                <div class="header-actions">
                    <a href="add_medicine.php" class="add-btn"><i class="fas fa-plus"></i> Add Medicine</a>
                   <a href="export.php" class="export-btn"><i class="fas fa-download"></i> Export</a>
                </div>
            </header>

            <?php if ($message !== null): ?>
                <div class="message <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <div class="dashboard-note">
                <i class="fas fa-circle-info"></i>
                <span>
                    You have <strong><?= $totalMedicines ?></strong> medicines, with
                    <strong><?= $expiringSoon ?></strong> expiring soon and
                    <strong><?= $expired ?></strong> expired.
                </span>
            </div>

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

                <div class="stat-card">
                    <div class="stat-icon" style="background: #6bcf7f;">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stat-info">
                        <p class="stat-label">Good Stock</p>
                        <p class="stat-value"><?= $goodStock ?></p>
                    </div>
                </div>
            </section>

            <section class="medicines-section">
                <h3>Your Medicines</h3>
                <div class="medicines-list" id="medicines-list">
                    <?php if ($medicines === []): ?>
                        <div class="empty-state">
                            <i class="fas fa-inbox"></i>
                            <p>No medicines added yet. Click "Add Medicine" to get started!</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($medicines as $medicine): ?>
                            <article class="medicine-card">
                                <h3><?= htmlspecialchars($medicine['medicine_name']) ?></h3>
                                <p><strong>Category:</strong> <?= htmlspecialchars($medicine['category'] !== '' ? $medicine['category'] : 'Other') ?></p>
                                <p><strong>Dosage:</strong> <?= htmlspecialchars($medicine['dosage'] !== '' ? $medicine['dosage'] : 'Not set') ?></p>
                                <p><strong>Quantity:</strong> <?= (int) $medicine['quantity'] ?></p>
                                <p><strong>Expiry Date:</strong> <?= htmlspecialchars($medicine['expiry_date']) ?></p>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
