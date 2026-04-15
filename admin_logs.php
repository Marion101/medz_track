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
    header('Location: admin_logs.php');
    exit;
}

function bind_query_params(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '' || $params === []) {
        return;
    }

    $refs = [];
    foreach ($params as $index => $value) {
        $refs[$index] = &$params[$index];
    }

    array_unshift($refs, $types);
    $stmt->bind_param(...$refs);
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

$logQ = trim((string) ($_GET['q'] ?? ''));
$logAction = trim((string) ($_GET['action'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
    $dateFrom = '';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
    $dateTo = '';
}

$actionOptions = $conn->query('SELECT DISTINCT action FROM activity_log ORDER BY action ASC')->fetch_all(MYSQLI_ASSOC);

$sql = 'SELECT user_email, action, details, created_at FROM activity_log WHERE 1=1';
$types = '';
$params = [];

if ($logQ !== '') {
    $sql .= ' AND (user_email LIKE ? OR details LIKE ?)';
    $likeQ = '%' . $logQ . '%';
    $types .= 'ss';
    $params[] = $likeQ;
    $params[] = $likeQ;
}

if ($logAction !== '') {
    $sql .= ' AND action = ?';
    $types .= 's';
    $params[] = $logAction;
}

if ($dateFrom !== '') {
    $sql .= " AND created_at >= CONCAT(?, ' 00:00:00')";
    $types .= 's';
    $params[] = $dateFrom;
}

if ($dateTo !== '') {
    $sql .= " AND created_at <= CONCAT(?, ' 23:59:59')";
    $types .= 's';
    $params[] = $dateTo;
}

$sql .= ' ORDER BY created_at DESC, id DESC LIMIT 100';
$logsStmt = $conn->prepare($sql);
bind_query_params($logsStmt, $types, $params);
$logsStmt->execute();
$logs = $logsStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$logsStmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Logs-medztrack</title>
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
                <a href="admin_users.php" class="nav-item"><i class="fas fa-users"></i> Users & Roles</a>
                <a href="admin_medicines.php" class="nav-item"><i class="fas fa-pills"></i> Medicines</a>
                <a href="admin_logs.php" class="nav-item active"><i class="fas fa-clipboard-list"></i> Activity Logs</a>
                <a href="admin_reports.php" class="nav-item"><i class="fas fa-file-lines"></i> Reports</a>
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
                <div class="dev-panel" id="logs">
                    <h3>Activity Logs</h3>
                    <form method="get" action="" class="dev-inline-form" style="margin-bottom: 16px; flex-wrap: wrap; gap: 8px;">
                        <input type="text" name="q" class="dev-select" placeholder="Search user/details" value="<?= htmlspecialchars($logQ) ?>">
                        <select name="action" class="dev-select">
                            <option value="">All actions</option>
                            <?php foreach ($actionOptions as $option): ?>
                                <?php $actionValue = (string) ($option['action'] ?? ''); ?>
                                <option value="<?= htmlspecialchars($actionValue) ?>" <?= $logAction === $actionValue ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($actionValue) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="date" name="date_from" class="dev-select" value="<?= htmlspecialchars($dateFrom) ?>">
                        <input type="date" name="date_to" class="dev-select" value="<?= htmlspecialchars($dateTo) ?>">
                        <button type="submit" class="dev-btn">Filter</button>
                        <a href="admin_logs.php" class="dev-btn" style="text-decoration:none;">Clear</a>
                    </form>
                    <div class="dev-table-wrap">
                        <table class="dev-table">
                            <thead>
                                <tr>
                                    <th>Time</th>
                                    <th>User</th>
                                    <th>Action</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($logs === []): ?>
                                    <tr><td colspan="4" class="empty-cell">No activity logged yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($logs as $row): ?>
                                        <tr>
                                            <td><?= htmlspecialchars((string) ($row['created_at'] ?? '')) ?></td>
                                            <td><?= htmlspecialchars((string) ($row['user_email'] ?? '')) ?></td>
                                            <td><span class="dev-pill"><?= htmlspecialchars((string) ($row['action'] ?? '')) ?></span></td>
                                            <td><?= htmlspecialchars((string) ($row['details'] ?? '')) ?></td>
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

