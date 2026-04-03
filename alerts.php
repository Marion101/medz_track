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

$email = $_SESSION['user_email'];

$conn->query(
    "CREATE TABLE IF NOT EXISTS medicines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_email VARCHAR(255) NOT NULL,
        medicine_name VARCHAR(255) NOT NULL,
        dosage VARCHAR(100) DEFAULT '',
        quantity INT NOT NULL,
        expiry_date DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"
);

$stmt = $conn->prepare('SELECT medicine_name, dosage, quantity, expiry_date FROM medicines WHERE user_email = ? ORDER BY expiry_date ASC');
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();
$medicines = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$today = new DateTimeImmutable('today');
$alerts = [];

foreach ($medicines as $medicine) {
    $expiryDate = DateTimeImmutable::createFromFormat('Y-m-d', (string) $medicine['expiry_date']) ?: $today;
    $daysLeft = (int) $today->diff($expiryDate)->format('%r%a');

    if ($daysLeft < 0) {
        $alerts[] = [
            'type' => 'Expired',
            'class' => 'expired',
            'message' => $medicine['medicine_name'] . ' expired on ' . $medicine['expiry_date'] . '.',
        ];
    } elseif ($daysLeft <= 30) {
        $alerts[] = [
            'type' => 'Expiring Soon',
            'class' => 'warning',
            'message' => $medicine['medicine_name'] . ' expires in ' . $daysLeft . ' day(s).',
        ];
    }

    if ((int) $medicine['quantity'] <= 5) {
        $alerts[] = [
            'type' => 'Low Stock',
            'class' => 'low-stock',
            'message' => $medicine['medicine_name'] . ' is running low with only ' . (int) $medicine['quantity'] . ' left.',
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Alerts - Medicine Expiry Tracker</title>
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
                <a href="dashboard.php" class="nav-item"><i class="fas fa-home"></i> Dashboard</a>
                <a href="add_medicine.php" class="nav-item"><i class="fas fa-plus-circle"></i> Add Medicine</a>
                <a href="my_medicines.php" class="nav-item"><i class="fas fa-list"></i> My Medicines</a>
                <a href="alerts.php" class="nav-item active"><i class="fas fa-bell"></i> Alerts</a>
                <a href="profile.php" class="nav-item"><i class="fas fa-user"></i> Profile</a>
            </nav>
            <form action="logout.php" method="post">
                <button class="logout-btn" type="submit"><i class="fas fa-sign-out-alt"></i> Logout</button>
            </form>
        </aside>

        <main class="main-content">
            <header class="top-header">
                <a href="dashboard.php" class="back-btn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M15 18L9 12L15 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
                <h2>Alerts</h2>
            </header>

            <section class="medicines-section">
                <h3>Important Updates</h3>
                <div class="medicines-list">
                    <?php if ($alerts === []): ?>
                        <div class="empty-state">
                            <i class="fas fa-bell-slash"></i>
                            <p>No alerts right now. Everything looks good.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($alerts as $alert): ?>
                            <article class="medicine-card">
                                <h3><?= htmlspecialchars($alert['type']) ?></h3>
                                <p><?= htmlspecialchars($alert['message']) ?></p>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
</body>
</html>

