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

$email = $_SESSION['user_email'];
$message = null;
$messageType = 'error';

$stmt = $conn->prepare('SELECT name, email, password FROM users WHERE email = ?');
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc() ?: ['name' => '', 'email' => $email, 'password' => ''];
$stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? 'update_profile');

    if ($action === 'update_profile') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $newEmail = strtolower(trim((string) ($_POST['email'] ?? '')));
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $currentEmail = (string) ($user['email'] ?? $email);
        $changingSensitive = ($newEmail !== strtolower($currentEmail));

        if ($name === '' || $newEmail === '') {
            $message = 'Please fill in name and email.';
        } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            $message = 'Enter a valid email address.';
        } elseif ($changingSensitive && $currentPassword === '') {
            $message = 'Enter your current password to change email.';
        } elseif ($changingSensitive && !password_verify($currentPassword, (string) ($user['password'] ?? ''))) {
            $message = 'Current password is incorrect.';
        } else {
            $existsStmt = $conn->prepare('SELECT id FROM users WHERE email = ? AND email <> ? LIMIT 1');
            $existsStmt->bind_param('ss', $newEmail, $currentEmail);
            $existsStmt->execute();
            $exists = $existsStmt->get_result()->fetch_assoc() ?: [];
            $existsStmt->close();

            if ($exists !== []) {
                $message = 'Another account already uses that email.';
            } else {
                $conn->begin_transaction();
                try {
                    $update = $conn->prepare('UPDATE users SET name = ?, email = ? WHERE email = ?');
                    $update->bind_param('sss', $name, $newEmail, $currentEmail);
                    $ok = $update->execute();
                    $update->close();

                    if (!$ok) {
                        throw new RuntimeException('User update failed.');
                    }

                    if ($newEmail !== $currentEmail) {
                        $medUpdate = $conn->prepare('UPDATE medicines SET user_email = ? WHERE user_email = ?');
                        $medUpdate->bind_param('ss', $newEmail, $currentEmail);
                        $medUpdate->execute();
                        $medUpdate->close();
                    }

                    $conn->commit();
                    $_SESSION['user_email'] = $newEmail;
                    $_SESSION['user_name'] = $name;
                    $email = $newEmail;
                    $user['name'] = $name;
                    $user['email'] = $newEmail;
                    $messageType = 'success';
                    $message = 'Profile updated successfully.';
                    $details = 'Profile updated';
                    if ($changingSensitive) {
                        $details .= ' (email changed with password confirmation)';
                    }
                    log_activity($conn, $newEmail, 'profile_update', $details);
                } catch (Throwable $e) {
                    $conn->rollback();
                    $message = 'Could not update your profile. Please try again.';
                }
            }
        }
    }

    if ($action === 'change_password') {
        $currentPassword = (string) ($_POST['current_password'] ?? '');
        $newPassword = (string) ($_POST['new_password'] ?? '');
        $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

        $isStrong = strlen($newPassword) >= 8
            && preg_match('/[A-Z]/', $newPassword)
            && preg_match('/[a-z]/', $newPassword)
            && preg_match('/[0-9]/', $newPassword)
            && preg_match('/[^A-Za-z0-9]/', $newPassword);

        if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $message = 'Please fill all password fields.';
        } elseif (!password_verify($currentPassword, (string) ($user['password'] ?? ''))) {
            $message = 'Current password is incorrect.';
        } elseif ($newPassword !== $confirmPassword) {
            $message = 'New passwords do not match.';
        } elseif (!$isStrong) {
            $message = 'New password must be 8+ chars with uppercase, lowercase, number, and symbol.';
        } else {
            $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
            $updatePassword = $conn->prepare('UPDATE users SET password = ?, reset_token = NULL, token_expiry = NULL WHERE email = ?');
            $updatePassword->bind_param('ss', $newPasswordHash, $email);

            if ($updatePassword->execute()) {
                log_activity($conn, $email, 'password_changed', 'Changed from profile page');
                $messageType = 'success';
                $message = 'Password changed successfully.';
                $user['password'] = $newPasswordHash;
            } else {
                $message = 'Could not update your profile. Please try again.';
            }

            $updatePassword->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Edit Profile-medztrack</title>
    <!-- CSS for this page is in Dashboard.css -->
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
                <a href="alerts.php" class="nav-item"><i class="fas fa-bell"></i> Alerts</a>
                <a href="user_calendar.php" class="nav-item"><i class="fas fa-calendar-days"></i> Calendar</a>
                <a href="profile.php" class="nav-item active"><i class="fas fa-user"></i> Profile</a>
            </nav>
            <form action="logout.php" method="post">
                <button type="submit" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</button>
            </form>
        </aside>

        <main class="main-content">
            <header class="top-header">
                <a href="profile.php" class="back-btn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M15 18L9 12L15 6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
                <h2>Edit Profile</h2>
            </header>

            <?php if ($message !== null): ?>
                <div class="message <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <section class="medicines-section">
                <form method="post" action="" class="modal-content medicine-form-panel">
                    <input type="hidden" name="action" value="update_profile">
                    <div class="form-group">
                        <label for="name">Display Name *</label>
                        <input type="text" id="name" name="name" value="<?= htmlspecialchars((string) ($user['name'] ?? '')) ?>" placeholder="Your name" required>
                    </div>
                    <div class="form-group">
                        <label for="email">Email *</label>
                        <input type="email" id="email" name="email" value="<?= htmlspecialchars((string) ($user['email'] ?? $email)) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="profile_current_password">Current Password (required for email changes)</label>
                        <input type="password" id="profile_current_password" name="current_password" autocomplete="current-password">
                    </div>
                    <button type="submit" class="btn-submit">Save Changes</button>
                </form>

                <form method="post" action="" class="modal-content medicine-form-panel" style="margin-top:16px;" autocomplete="on">
                    <input type="hidden" name="action" value="change_password">
                    <input type="hidden" name="username" value="<?= htmlspecialchars((string) ($user['email'] ?? $email)) ?>" autocomplete="username">
                    <div class="form-group">
                        <label for="current_password">Current Password *</label>
                        <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
                    </div>
                    <div class="form-group">
                        <label for="new_password">New Password *</label>
                        <input type="password" id="new_password" name="new_password" autocomplete="new-password" minlength="8" required>
                    </div>
                    <div class="form-group">
                        <label for="confirm_password">Confirm New Password *</label>
                        <input type="password" id="confirm_password" name="confirm_password" autocomplete="new-password" minlength="8" required>
                    </div>
                    <button type="submit" class="btn-submit">Change Password</button>
                </form>
            </section>
        </main>
    </div>
</body>
</html>

