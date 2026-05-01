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

// Make sure the needed database fields exist
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

// Delete a medicine
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

// Get all medicines to show on the page
$medicines = $conn->query(
    'SELECT m.id, m.user_email, m.medicine_name, m.dosage, m.quantity, m.expiry_date, m.category, u.name AS owner_name
     FROM medicines m
     LEFT JOIN users u ON m.user_email = u.email
     ORDER BY m.expiry_date ASC, m.id DESC'
)->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Medicines-medztrack</title>
    <!-- Page styles -->
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
                <a href="dashboard.php" class="nav-item"><i class="fas fa-home"></i> Dashboard</a>
                <a href="add_medicine.php" class="nav-item"><i class="fas fa-plus-circle"></i> Add Medicine</a>
                <a href="my_medicines.php" class="nav-item active"><i class="fas fa-list"></i> My Medicines</a>
                <a href="alerts.php" class="nav-item"><i class="fas fa-bell"></i> Alerts</a>
                <a href="user_calendar.php" class="nav-item"><i class="fas fa-calendar-days"></i> Calendar</a>
                <a href="profile.php" class="nav-item"><i class="fas fa-user"></i> Profile</a>
            </nav>
            <form action="logout.php" method="post">
                <button class="logout-btn" type="submit"><i class="fas fa-sign-out-alt"></i> Logout</button>
            </form>
        </aside>

        <main class="main-content">
            <!-- Page header and search box -->
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

            <!-- Success or error message -->
            <?php if ($message !== null): ?>
                <div class="message <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <!-- Medicine cards -->
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
                                <p><strong>Added by:</strong> <?= htmlspecialchars((string) (($medicine['owner_name'] ?? '') !== '' ? $medicine['owner_name'] : ($medicine['user_email'] ?? ''))) ?></p>
                                <p><strong>Category:</strong> <?= htmlspecialchars($medicine['category'] !== '' ? $medicine['category'] : 'Other') ?></p>
                                <p><strong>Dosage:</strong> <?= htmlspecialchars($medicine['dosage'] !== '' ? $medicine['dosage'] : 'Not set') ?></p>
                                <p><strong>Quantity:</strong> <?= (int) $medicine['quantity'] ?></p>
                                <p><strong>Expiry Date:</strong> <?= htmlspecialchars($medicine['expiry_date']) ?></p>
                                <div class="card-buttons">
                                    <?php if ((string) ($medicine['user_email'] ?? '') === $email): ?>
                                        <a href="edit_medicine.php?id=<?= (int) $medicine['id'] ?>" class="add-btn edit-link" style="text-align:center;"><i class="fas fa-edit"></i> Edit</a>
                                        <form method="post" action="" class="inline-form">
                                            <input type="hidden" name="delete_medicine_id" value="<?= (int) $medicine['id'] ?>">
                                            <button type="submit" class="delete-btn"><i class="fas fa-trash"></i> Delete</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="setting-note">View only</span>
                                    <?php endif; ?>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        </main>
    </div>
    <script>
// Search medicines on this page
function filterMedicines(query) {
    const q = query.toLowerCase().trim();
    document.querySelectorAll('#medicines-list .medicine-card').forEach(card => {
        card.style.display = card.innerText.toLowerCase().includes(q) ? '' : 'none';
    });
}
    </script>
</body>
</html>
