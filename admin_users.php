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
    header('Location: admin_users.php');
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

    if ($action === 'reset_demo_password') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $targetStmt = $conn->prepare('SELECT id, email FROM users WHERE id = ? LIMIT 1');
        $targetStmt->bind_param('i', $userId);
        $targetStmt->execute();
        $targetUser = $targetStmt->get_result()->fetch_assoc() ?: [];
        $targetStmt->close();

        if ($targetUser === []) {
            dev_flash('error', 'User not found.');
            dev_redirect();
        }

        if ((string) $targetUser['email'] === $currentEmail) {
            dev_flash('error', 'Use this action for other demo users only.');
            dev_redirect();
        }

        $demoPasswordHash = password_hash('user123', PASSWORD_DEFAULT);
        $resetStmt = $conn->prepare('UPDATE users SET password = ?, reset_token = NULL, token_expiry = NULL WHERE id = ?');
        $resetStmt->bind_param('si', $demoPasswordHash, $userId);
        $resetStmt->execute();
        $resetStmt->close();

        log_activity($conn, $currentEmail, 'demo_password_reset', (string) $targetUser['email'] . ' -> user123');
        dev_flash('success', 'Demo password reset for ' . (string) $targetUser['email'] . '. New password: user123');
        dev_redirect();
    }

    if ($action === 'set_user_password') {
        $userId = (int) ($_POST['user_id'] ?? 0);
        $newPassword = (string) ($_POST['new_password'] ?? '');

        $targetStmt = $conn->prepare('SELECT id, email FROM users WHERE id = ? LIMIT 1');
        $targetStmt->bind_param('i', $userId);
        $targetStmt->execute();
        $targetUser = $targetStmt->get_result()->fetch_assoc() ?: [];
        $targetStmt->close();

        if ($targetUser === []) {
            dev_flash('error', 'User not found.');
            dev_redirect();
        }

        if ((string) $targetUser['email'] === $currentEmail) {
            dev_flash('error', 'Use profile reset flow for your own account.');
            dev_redirect();
        }

        if (strlen($newPassword) < 6) {
            dev_flash('error', 'New password must be at least 6 characters.');
            dev_redirect();
        }

        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $updateStmt = $conn->prepare('UPDATE users SET password = ?, reset_token = NULL, token_expiry = NULL WHERE id = ?');
        $updateStmt->bind_param('si', $passwordHash, $userId);
        $updateStmt->execute();
        $updateStmt->close();

        log_activity($conn, $currentEmail, 'admin_password_set', (string) $targetUser['email'] . ' password changed by admin');
        dev_flash('success', 'Password updated for ' . (string) $targetUser['email'] . '.');
        dev_redirect();
    }

    if ($action === 'delete_medicine') {
        $medicineId = (int) ($_POST['medicine_id'] ?? 0);
        $medStmt = $conn->prepare('SELECT id, medicine_name, user_email, expiry_date FROM medicines WHERE id = ? LIMIT 1');
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

        log_medicine_removal_alert(
            $conn,
            $currentEmail,
            (string) ($medicine['user_email'] ?? ''),
            (string) ($medicine['medicine_name'] ?? ''),
            (string) ($medicine['expiry_date'] ?? '')
        );

        log_activity($conn, $currentEmail, 'medicine_deleted', (string) ($medicine['medicine_name'] ?? '') . ' | owner: ' . (string) ($medicine['user_email'] ?? ''));
        dev_flash('success', 'Medicine deleted.');
        dev_redirect();
    }
}

$usersCount = (int) ($conn->query('SELECT COUNT(*) AS total FROM users')->fetch_assoc()['total'] ?? 0);
$medicinesCount = (int) ($conn->query('SELECT COUNT(*) AS total FROM medicines')->fetch_assoc()['total'] ?? 0);
$expiredCount = (int) ($conn->query('SELECT COUNT(*) AS total FROM medicines WHERE expiry_date < CURDATE()')->fetch_assoc()['total'] ?? 0);
$lowStockCount = (int) ($conn->query('SELECT COUNT(*) AS total FROM medicines WHERE quantity <= 5')->fetch_assoc()['total'] ?? 0);
$adminCount = (int) ($conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'admin'")->fetch_assoc()['total'] ?? 0);

$users = $conn->query('SELECT id, name, email, phone, role, created_at FROM users ORDER BY created_at DESC, id DESC LIMIT 25')->fetch_all(MYSQLI_ASSOC);
$medicines = $conn->query('SELECT id, user_email, medicine_name, category, quantity, expiry_date, created_at FROM medicines ORDER BY created_at DESC, id DESC LIMIT 25')->fetch_all(MYSQLI_ASSOC);
$logs = $conn->query('SELECT user_email, action, details, created_at FROM activity_log ORDER BY created_at DESC, id DESC LIMIT 25')->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Users - Medz track</title>
    <link rel="stylesheet" href="Dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="<?= htmlspecialchars(theme_body_class('admin-console')) ?>">
    <div class="dashboard-container">
                <aside class="sidebar">
            <div class="sidebar-header">
                <h1><i class="fas fa-shield-halved"></i> Admin</h1>
                <p class="sidebar-note">Manage users, medicines, roles, and logs.</p>
            </div>
            <nav class="sidebar-nav">
                <a href="admin.php" class="nav-item"><i class="fas fa-chart-pie"></i> Overview</a>
                <a href="admin_users.php" class="nav-item active"><i class="fas fa-users"></i> Users & Roles</a>
                <a href="admin_medicines.php" class="nav-item"><i class="fas fa-pills"></i> Medicines</a>
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
                <div class="dev-panel" id="users">
                    <h3>Manage Users & Roles</h3>
                    <div class="dev-table-wrap">
                        <table class="dev-table">
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Role</th>
                                    <th>Joined</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($users === []): ?>
                                    <tr><td colspan="6" class="empty-cell">No users yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($users as $row): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string) ($row['name'] ?? '')) ?></td>
                                            <td><?= htmlspecialchars((string) ($row['email'] ?? '')) ?></td>
                                            <td><?= htmlspecialchars((string) ($row['phone'] ?? '')) ?></td>
                                            <td>
                                                <span class="dev-pill"><?= htmlspecialchars((string) ($row['role'] ?? 'user')) ?></span>
                                            </td>
                                            <td><?= htmlspecialchars((string) ($row['created_at'] ?? '')) ?></td>
                                            <td>
                                                <div class="dev-actions">
                                                    <?php if ((string) ($row['email'] ?? '') !== $currentEmail): ?>
                                                        <form method="post" action="" class="dev-inline-form">
                                                            <input type="hidden" name="action" value="update_role">
                                                            <input type="hidden" name="user_id" value="<?= (int) $row['id'] ?>">
                                                            <select name="role" class="dev-select">
                                                                <option value="user" <?= (($row['role'] ?? 'user') === 'user') ? 'selected' : '' ?>>User</option>
                                                                <option value="admin" <?= (($row['role'] ?? 'user') === 'admin') ? 'selected' : '' ?>>Admin</option>
                                                            </select>
                                                            <button type="submit" class="dev-btn">Save</button>
                                                        </form>
                                                        <form method="post" action="" class="dev-inline-form">
                                                            <input type="hidden" name="action" value="delete_user">
                                                            <input type="hidden" name="user_id" value="<?= (int) $row['id'] ?>">
                                                            <button type="submit" class="dev-btn dev-btn-danger" onclick="return confirm('Delete this user and their medicines?');">Delete</button>
                                                        </form>
                                                        <form method="post" action="" class="dev-inline-form">
                                                            <input type="hidden" name="action" value="reset_demo_password">
                                                            <input type="hidden" name="user_id" value="<?= (int) $row['id'] ?>">
                                                            <button type="submit" class="dev-btn" onclick="return confirm('Reset this user password to user123?');">Reset Demo Password</button>
                                                        </form>
                                                        <form method="post" action="" class="dev-inline-form">
                                                            <input type="hidden" name="action" value="set_user_password">
                                                            <input type="hidden" name="user_id" value="<?= (int) $row['id'] ?>">
                                                            <input type="text" name="new_password" class="dev-select" placeholder="New password" minlength="6" required>
                                                            <button type="submit" class="dev-btn" onclick="return confirm('Set this new password for user?');">Set Password</button>
                                                        </form>
                                                    <?php else: ?>
                                                        <span class="setting-note">Current account</span>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
</div>
</div>
            </section>
        </main>
    </div>
</body>
</html>

