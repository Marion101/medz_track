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
$categoryOptions = ['Pain Relief', 'Cold & Flu', 'Vitamins', 'Digestive', 'Analgesic', 'Antibiotic', 'Antacid', 'Antihistamine', 'Antifungal', 'Other'];

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: my_medicines.php');
    exit;
}

$stmt = $conn->prepare('SELECT id, medicine_name, dosage, quantity, expiry_date, category FROM medicines WHERE id = ? AND user_email = ? LIMIT 1');
$stmt->bind_param('is', $id, $email);
$stmt->execute();
$medicine = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$medicine) {
    header('Location: my_medicines.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $medicineName = trim((string) ($_POST['medicine_name'] ?? ''));
    $dosage       = trim((string) ($_POST['dosage'] ?? ''));
    $quantity     = (int) ($_POST['quantity'] ?? 0);
    $expiryDate   = (string) ($_POST['expiry_date'] ?? '');
    $category     = trim((string) ($_POST['category'] ?? 'Other'));

    if (!in_array($category, $categoryOptions, true)) {
        $category = 'Other';
    }

    if ($medicineName === '' || $quantity <= 0 || $expiryDate === '') {
        $message = 'Medicine name, quantity, and expiry date are required.';
        $messageType = 'error';
    } else {
        $update = $conn->prepare('UPDATE medicines SET medicine_name = ?, dosage = ?, quantity = ?, expiry_date = ?, category = ? WHERE id = ? AND user_email = ?');
        $update->bind_param('ssissis', $medicineName, $dosage, $quantity, $expiryDate, $category, $id, $email);

        if ($update->execute()) {
            log_activity($conn, $email, 'medicine_updated', $medicineName . ' | qty: ' . $quantity . ' | expiry: ' . $expiryDate);
            $update->close();
            header('Location: my_medicines.php?updated=1');
            exit;
        }

        $message = 'Could not update medicine. Please try again.';
        $update->close();
    }

    $medicine['medicine_name'] = $medicineName;
    $medicine['dosage']        = $dosage;
    $medicine['quantity']      = $quantity;
    $medicine['expiry_date']   = $expiryDate;
    $medicine['category']      = $category;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Medicine - Medicine Expiry Tracker</title>
    <link rel="stylesheet" href="Dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
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
                <button type="submit" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</button>
            </form>
        </aside>

        <main class="main-content">
            <header class="top-header">
                <a href="my_medicines.php" class="back-btn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
                        <path d="M15 18L9 12L15 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
                <h2>Edit Medicine</h2>
            </header>

            <?php if ($message !== null): ?>
                <div class="message <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <section class="medicines-section">
                <form method="post" action="" class="modal-content medicine-form-panel">
                    <div class="form-group">
                        <label for="medicine_name">Medicine Name *</label>
                        <input type="text" id="medicine_name" name="medicine_name"
                               value="<?= htmlspecialchars((string) $medicine['medicine_name']) ?>"
                               placeholder="e.g., Aspirin" required>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="dosage">Dosage</label>
                            <input type="text" id="dosage" name="dosage"
                                   value="<?= htmlspecialchars((string) ($medicine['dosage'] ?? '')) ?>"
                                   placeholder="e.g., 500mg">
                        </div>
                        <div class="form-group">
                            <label for="quantity">Quantity *</label>
                            <input type="number" id="quantity" name="quantity"
                                   value="<?= (int) $medicine['quantity'] ?>" min="1" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="expiry_date">Expiry Date *</label>
                        <input type="date" id="expiry_date" name="expiry_date"
                               value="<?= htmlspecialchars((string) $medicine['expiry_date']) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="category">Category</label>
                        <select id="category" name="category">
                            <?php foreach ($categoryOptions as $option): ?>
                                <option value="<?= htmlspecialchars($option) ?>"
                                    <?= ($medicine['category'] ?? 'Other') === $option ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($option) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div style="display:flex; gap:12px; margin-top:8px;">
                        <button type="submit" class="btn-submit" style="flex:1;">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                        <a href="my_medicines.php" class="btn-submit"
                           style="flex:1; text-align:center; text-decoration:none; background:linear-gradient(135deg,#aaa 0%,#ccc 100%); box-shadow:none;">
                            Cancel
                        </a>
                    </div>
                </form>
            </section>
        </main>
    </div>
</body>
</html>