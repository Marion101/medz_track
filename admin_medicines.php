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

if (!isset($_SESSION['user_email'])) {
    header('Location: admin_login.php');
    exit;
}

$currentEmail = (string) $_SESSION['user_email'];
$currentStmt = $conn->prepare('SELECT id, name, role FROM users WHERE email = ? LIMIT 1');
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

$flash = $_SESSION['dev_flash'] ?? null;
unset($_SESSION['dev_flash']);

function dev_flash(string $type, string $message): void
{
    $_SESSION['dev_flash'] = [
        'type' => $type,
        'message' => $message,
    ];
}

function dev_redirect(): void
{
    header('Location: admin_medicines.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'update_role') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $newRole = (string) ($_POST['role'] ?? 'user');
        $allowedRoles = ['user', 'admin'];

        if (!in_array($newRole, $allowedRoles, true)) {
            dev_flash('error', 'Invalid role selected.');
            dev_redirect();
        }

        $targetStmt = $conn->prepare('SELECT id, email, role FROM users WHERE id = ? LIMIT 1');
        $targetStmt->bind_param('i', $userId);
        $targetStmt->execute();
        $targetUser = $targetStmt->get_result()->fetch_assoc() ?: [];
        $targetStmt->close();

        if ($targetUser === []) {
            dev_flash('error', 'User not found.');
            dev_redirect();
        }

        if ((string) $targetUser['email'] === $currentEmail) {
            dev_flash('error', 'You cannot change your own role from this page.');
            dev_redirect();
        }

        $update = $conn->prepare('UPDATE users SET role = ? WHERE id = ?');
        $update->bind_param('si', $newRole, $userId);
        $update->execute();
        $update->close();

        log_activity($conn, $currentEmail, 'role_updated', (string) $targetUser['email'] . ' -> ' . $newRole);
        dev_flash('success', 'User role updated.');
        dev_redirect();
    }

    if ($action === 'delete_user') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $targetStmt = $conn->prepare('SELECT id, email, role FROM users WHERE id = ? LIMIT 1');
        $targetStmt->bind_param('i', $userId);
        $targetStmt->execute();
        $targetUser = $targetStmt->get_result()->fetch_assoc() ?: [];
        $targetStmt->close();

        if ($targetUser === []) {
            dev_flash('error', 'User not found.');
            dev_redirect();
        }

        if ((string) $targetUser['email'] === $currentEmail) {
            dev_flash('error', 'You cannot delete your own account from here.');
            dev_redirect();
        }

        $medicineDelete = $conn->prepare('DELETE FROM medicines WHERE user_email = ?');
        $medicineDelete->bind_param('s', $targetUser['email']);
        $medicineDelete->execute();
        $medicineDelete->close();

        $userDelete = $conn->prepare('DELETE FROM users WHERE id = ?');
        $userDelete->bind_param('i', $userId);
        $userDelete->execute();
        $userDelete->close();

        log_activity($conn, $currentEmail, 'user_deleted', (string) $targetUser['email']);
        dev_flash('success', 'User deleted.');
        dev_redirect();
    }

    if ($action === 'delete_medicine') {
        $medicineId = (int) ($_POST['medicine_id'] ?? 0);
        $medStmt = $conn->prepare('SELECT id, medicine_name, user_email FROM medicines WHERE id = ? LIMIT 1');
        $medStmt->bind_param('i', $medicineId);
        $medStmt->execute();
        $medicine = $medStmt->get_result()->fetch_assoc() ?: [];
        $medStmt->close();

        if ($medicine === []) {
            dev_flash('error', 'Medicine not found.');
            dev_redirect();
        }

        $delete = $conn->prepare('DELETE FROM medicines WHERE id = ?');
        $delete->bind_param('i', $medicineId);
        $delete->execute();
        $delete->close();

        log_activity($conn, $currentEmail, 'medicine_deleted', (string) ($medicine['medicine_name'] ?? '') . ' | owner: ' . (string) ($medicine['user_email'] ?? ''));
        dev_flash('success', 'Medicine deleted.');
        dev_redirect();
    }

    if ($action === 'add_medicine') {
        $userEmail = trim((string) ($_POST['user_email'] ?? ''));
        $medicineName = trim((string) ($_POST['medicine_name'] ?? ''));
        $dosage = trim((string) ($_POST['dosage'] ?? ''));
        $quantity = (int) ($_POST['quantity'] ?? 0);
        $expiryDate = (string) ($_POST['expiry_date'] ?? '');
        $category = trim((string) ($_POST['category'] ?? 'Other'));

        $categoryOptions = ['Pain Relief', 'Cold & Flu', 'Vitamins', 'Digestive', 'Other'];
        if (!in_array($category, $categoryOptions, true)) {
            $category = 'Other';
        }

        if ($userEmail === '' || $medicineName === '' || $quantity <= 0 || $expiryDate === '') {
            dev_flash('error', 'User, medicine name, quantity, and expiry date are required.');
            dev_redirect();
        }

        // Check if user exists
        $userCheck = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $userCheck->bind_param('s', $userEmail);
        $userCheck->execute();
        $userExists = $userCheck->get_result()->fetch_assoc();
        $userCheck->close();

        if (!$userExists) {
            dev_flash('error', 'Selected user does not exist.');
            dev_redirect();
        }

        $stmt = $conn->prepare('INSERT INTO medicines (user_email, medicine_name, dosage, quantity, expiry_date, category) VALUES (?, ?, ?, ?, ?, ?)');
        $stmt->bind_param('sssiss', $userEmail, $medicineName, $dosage, $quantity, $expiryDate, $category);

        if ($stmt->execute()) {
            $stmt->close();
            log_activity($conn, $currentEmail, 'admin_add_medicine', $medicineName . ' for ' . $userEmail . ' | category: ' . $category . ' | qty: ' . $quantity);
            dev_flash('success', 'Medicine added successfully.');
            dev_redirect();
        }

        dev_flash('error', 'Could not add medicine. Please try again.');
        dev_redirect();
    }
}

$usersCount = (int) ($conn->query('SELECT COUNT(*) AS total FROM users')->fetch_assoc()['total'] ?? 0);
$medicinesCount = (int) ($conn->query('SELECT COUNT(*) AS total FROM medicines')->fetch_assoc()['total'] ?? 0);
$expiredCount = (int) ($conn->query('SELECT COUNT(*) AS total FROM medicines WHERE expiry_date < CURDATE()')->fetch_assoc()['total'] ?? 0);
$lowStockCount = (int) ($conn->query('SELECT COUNT(*) AS total FROM medicines WHERE quantity <= 5')->fetch_assoc()['total'] ?? 0);
$adminCount = (int) ($conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'admin'")->fetch_assoc()['total'] ?? 0);

$users = $conn->query('SELECT id, name, email, phone, role, created_at FROM users ORDER BY created_at DESC, id DESC LIMIT 25')->fetch_all(MYSQLI_ASSOC);
$medicines = $conn->query('SELECT m.id, m.user_email, m.medicine_name, m.category, m.quantity, m.expiry_date, m.created_at, u.name AS user_name FROM medicines m LEFT JOIN users u ON m.user_email = u.email ORDER BY m.created_at DESC, m.id DESC LIMIT 25')->fetch_all(MYSQLI_ASSOC);
$logs = $conn->query('SELECT user_email, action, details, created_at FROM activity_log ORDER BY created_at DESC, id DESC LIMIT 25')->fetch_all(MYSQLI_ASSOC);
$allUsers = $conn->query('SELECT email, name FROM users ORDER BY name ASC, email ASC')->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Medicines - Medz track</title>
    <link rel="stylesheet" href="Dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="admin-console">
    <div class="dashboard-container">
                <aside class="sidebar">
            <div class="sidebar-header">
                <h1><i class="fas fa-shield-halved"></i> Admin</h1>
                <p class="sidebar-note">Manage users, medicines, roles, and logs.</p>
            </div>
            <nav class="sidebar-nav">
                <a href="admin.php" class="nav-item"><i class="fas fa-chart-pie"></i> Overview</a>
                <a href="admin_users.php" class="nav-item"><i class="fas fa-users"></i> Users & Roles</a>
                <a href="admin_medicines.php" class="nav-item active"><i class="fas fa-pills"></i> Medicines</a>
                <a href="admin_logs.php" class="nav-item"><i class="fas fa-clipboard-list"></i> Activity Logs</a>
            </nav>
            <form action="logout.php" method="post">
                <button type="submit" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</button>
            </form>
        </aside>
<main class="main-content">
            <header class="top-header" id="overview">
                <h2>Admin, <span id="username"><?= htmlspecialchars($displayName) ?></span></h2>
            </header>

            <?php if ($flash !== null): ?>
                <div class="message <?= htmlspecialchars((string) $flash['type']) ?>"><?= htmlspecialchars((string) $flash['message']) ?></div>
            <?php endif; ?>

            <section class="admin-section">
                <div class="dev-panel" id="medicines">
                    <h3>Manage Medicines</h3>
                    <div class="dev-form-section">
                        <h4>Add New Medicine</h4>
                        <form method="post" action="" class="medicine-form-panel">
                            <input type="hidden" name="action" value="add_medicine">
                            <div class="form-row">
                                <div class="form-group">
                                    <label for="user_email">User *</label>
                                    <select id="user_email" name="user_email" required>
                                        <option value="">Select User</option>
                                        <?php foreach ($allUsers as $user): ?>
                                            <option value="<?= htmlspecialchars((string) $user['email']) ?>">
                                                <?= htmlspecialchars((string) ($user['name'] ?: $user['email'])) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label for="medicine_name">Medicine Name *</label>
                                    <input type="text" id="medicine_name" name="medicine_name" placeholder="e.g., Aspirin" required>
                                </div>
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
                                    <label for="expiry_date">Expiry Date *</label>
                                    <input type="date" id="expiry_date" name="expiry_date" required>
                                </div>
                                <div class="form-group">
                                    <label for="category">Category</label>
                                    <select id="category" name="category">
                                        <option value="Pain Relief">Pain Relief</option>
                                        <option value="Cold & Flu">Cold & Flu</option>
                                        <option value="Vitamins">Vitamins</option>
                                        <option value="Digestive">Digestive</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                            </div>
                            <button type="submit" class="btn-submit">Add Medicine</button>
                        </form>
                    </div>
                    <div class="dev-table-wrap">
                        <table class="dev-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Medicine</th>
                                    <th>Category</th>
                                    <th>Qty</th>
                                    <th>Expiry</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($medicines === []): ?>
                                    <tr><td colspan="6" class="empty-cell">No medicines yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($medicines as $row): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string) ($row['user_name'] ?: $row['user_email'])) ?></td>
                                            <td><?= htmlspecialchars((string) ($row['medicine_name'] ?? '')) ?></td>
                                            <td><?= htmlspecialchars((string) ($row['category'] ?? 'Other')) ?></td>
                                            <td><?= (int) ($row['quantity'] ?? 0) ?></td>
                                            <td><?= htmlspecialchars((string) ($row['expiry_date'] ?? '')) ?></td>
                                            <td>
                                                <form method="post" action="" class="dev-inline-form">
                                                    <input type="hidden" name="action" value="delete_medicine">
                                                    <input type="hidden" name="medicine_id" value="<?= (int) $row['id'] ?>">
                                                    <button type="submit" class="dev-btn dev-btn-danger" onclick="return confirm('Delete this medicine?');">Delete</button>
                                                </form>
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
