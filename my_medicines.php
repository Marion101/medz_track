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
$message = null;
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_medicine_id'])) {
    $deleteId = (int) $_POST['delete_medicine_id'];
    $lookup = $conn->prepare('SELECT medicine_name, expiry_date FROM medicines WHERE id = ? AND user_email = ? LIMIT 1');
    $lookup->bind_param('is', $deleteId, $email);
    $lookup->execute();
    $medicineRow = $lookup->get_result()->fetch_assoc() ?: [];
    $lookup->close();

    $deleteStmt = $conn->prepare('DELETE FROM medicines WHERE id = ? AND user_email = ?');
    $deleteStmt->bind_param('is', $deleteId, $email);
    $deleteStmt->execute();
    $deleteStmt->close();
    if ($medicineRow !== []) {
        log_medicine_removal_alert(
            $conn,
            $email,
            $email,
            (string) ($medicineRow['medicine_name'] ?? ''),
            (string) ($medicineRow['expiry_date'] ?? '')
        );
        log_activity($conn, $email, 'medicine_deleted', (string) ($medicineRow['medicine_name'] ?? ''));
    }
    header('Location: my_medicines.php?deleted=1');
    exit;
}

if (isset($_GET['deleted'])) {
    $message = 'Medicine deleted successfully.';
}

if (isset($_GET['updated'])) {
    $message = 'Medicine updated successfully.';
    $messageType = 'success';
}

$stmt = $conn->prepare('SELECT id, medicine_name, dosage, quantity, expiry_date, category FROM medicines WHERE user_email = ? ORDER BY expiry_date ASC');
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();
$medicines = $result->fetch_all(MYSQLI_ASSOC);
$stmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Medicines - Medicine Expiry Tracker</title>
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
                <a href="my_medicines.php" class="nav-item active"><i class="fas fa-list"></i> My Medicines</a>
                <a href="alerts.php" class="nav-item"><i class="fas fa-bell"></i> Alerts</a>
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
                <h2>My Medicines</h2>
                <div class="header-actions">
                    <input type="text" class="search-box" id="search-input" placeholder="Search medicines..." oninput="filterMedicines(this.value)">
                </div>
            </header>

            <?php if ($message !== null): ?>
                <div class="message <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <section class="medicines-section">
                <h3>All Saved Medicines</h3>
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
                                <div class="card-buttons">
                                  <a href="edit_medicine.php?id=<?= (int) $medicine['id'] ?>" class="add-btn edit-link" style="text-align:center;"><i class="fas fa-edit"></i> Edit</a>
                                    <form method="post" action="" class="inline-form">
                                        <input type="hidden" name="delete_medicine_id" value="<?= (int) $medicine['id'] ?>">
                                        <button type="submit" class="delete-btn"><i class="fas fa-trash"></i> Delete</button>
                                    </form>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
    <script>
function filterMedicines(query) {
    const q = query.toLowerCase().trim();
    document.querySelectorAll('#medicines-list .medicine-card').forEach(card => {
        card.style.display = card.innerText.toLowerCase().includes(q) ? '' : 'none';
    });
}
    </script>
</body>
</html>
