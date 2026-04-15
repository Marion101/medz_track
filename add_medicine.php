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
$message = null;
$messageType = 'error';
$categoryOptions = ['Pain Relief', 'Cold & Flu', 'Vitamins', 'Digestive', 'Other'];
$todayDate = (new DateTimeImmutable('today'))->format('Y-m-d');

$conn->query(
    "CREATE TABLE IF NOT EXISTS medicines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_email VARCHAR(255) NOT NULL,
        added_by_email VARCHAR(255) DEFAULT NULL,
        medicine_name VARCHAR(255) NOT NULL,
        dosage VARCHAR(100) DEFAULT '',
        quantity INT NOT NULL,
        expiry_date DATE NOT NULL,
        category VARCHAR(100) DEFAULT 'Other',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"
);
$conn->query("ALTER TABLE medicines ADD COLUMN IF NOT EXISTS category VARCHAR(100) DEFAULT 'Other' AFTER expiry_date");
$conn->query("ALTER TABLE medicines ADD COLUMN IF NOT EXISTS added_by_email VARCHAR(255) DEFAULT NULL AFTER user_email");
$conn->query("UPDATE medicines SET added_by_email = user_email WHERE added_by_email IS NULL OR added_by_email = ''");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $medicineName = trim((string) ($_POST['medicine_name'] ?? ''));
    $dosage = trim((string) ($_POST['dosage'] ?? ''));
    $quantity = (int) ($_POST['quantity'] ?? 0);
    $expiryDate = (string) ($_POST['expiry_date'] ?? '');
    $category = trim((string) ($_POST['category'] ?? 'Other'));

    if (!in_array($category, $categoryOptions, true)) {
        $category = 'Other';
    }

    $parsedExpiry = DateTimeImmutable::createFromFormat('Y-m-d', $expiryDate);
    $isValidExpiry = $parsedExpiry !== false && $parsedExpiry->format('Y-m-d') === $expiryDate;

    if ($medicineName === '' || $quantity <= 0 || $expiryDate === '') {
        $message = 'Medicine name, quantity, and expiry date are required.';
    } elseif (!$isValidExpiry) {
        $message = 'Please enter a valid expiry date.';
    } elseif ($expiryDate < $todayDate) {
        $message = 'Expiry date cannot be in the past.';
    } else {
        $stmt = $conn->prepare('INSERT INTO medicines (user_email, added_by_email, medicine_name, dosage, quantity, expiry_date, category) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('ssssiss', $email, $email, $medicineName, $dosage, $quantity, $expiryDate, $category);

        if ($stmt->execute()) {
            log_medicine_addition_alert($conn, $email, $email, $medicineName, $expiryDate);
            log_activity($conn, $email, 'add_medicine', $medicineName . ' | category: ' . $category . ' | qty: ' . $quantity);
            $stmt->close();
            header('Location: dashboard.php?added=1');
            exit;
        }

        $message = 'Could not save medicine. Please try again.';
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Medicine-medztrack</title>
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
                <a href="add_medicine.php" class="nav-item active"><i class="fas fa-plus-circle"></i> Add Medicine</a>
                <a href="my_medicines.php" class="nav-item"><i class="fas fa-list"></i> My Medicines</a>
                <a href="alerts.php" class="nav-item"><i class="fas fa-bell"></i> Alerts</a>
                <a href="user_reports.php" class="nav-item"><i class="fas fa-file-lines"></i> Reports</a>
                <a href="profile.php" class="nav-item"><i class="fas fa-user"></i> Profile</a>
            </nav>
            <form action="logout.php" method="post">
                <button type="submit" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</button>
            </form>
        </aside>

        <main class="main-content">
            <header class="top-header">
                <a href="dashboard.php" class="back-btn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M15 18L9 12L15 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
                <h2>Add Medicine</h2>
            </header>

            <?php if ($message !== null): ?>
                <div class="message <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <section class="medicines-section">
                <form method="post" action="" class="modal-content medicine-form-panel">
                    <div class="form-group">
                        <label for="medicine_name">Medicine Name *</label>
                        <input type="text" id="medicine_name" name="medicine_name" placeholder="e.g., Aspirin" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="dosage">Dosage</label>
                            <input type="text" id="dosage" name="dosage" placeholder="e.g., 500mg">
                        </div>
                        <div class="form-group">
                            <label for="quantity">Quantity *</label>
                            <input type="number" id="quantity" name="quantity" placeholder="e.g., 30" min="1" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="low_stock">Low Stock Alert at</label>
                            <input type="number" id="low_stock" name="low_stock" placeholder="e.g., 5" value="5" disabled>
                        </div>
                        <div class="form-group">
                            <label for="refill_reminder">Refill Reminder (days)</label>
                            <input type="number" id="refill_reminder" name="refill_reminder" placeholder="e.g., 7" value="7" disabled>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="expiry_date">Expiry Date *</label>
                        <input type="date" id="expiry_date" name="expiry_date" min="<?= htmlspecialchars($todayDate) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="category">Category</label>
                        <select id="category" name="category">
                            <?php foreach ($categoryOptions as $option): ?>
                                <option value="<?= htmlspecialchars($option) ?>"><?= htmlspecialchars($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn-submit">Save Medicine</button>
                </form>
            </section>
        </main>
    </div>
</body>
</html>

