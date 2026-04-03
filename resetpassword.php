<?php
declare(strict_types=1);

require_once 'db.php';
require_once 'auth.php';

ensure_user_table($conn);

$message = null;
$messageType = 'error';
$token = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
$user = null;

if ($token !== '') {
    $stmt = $conn->prepare('SELECT id, email, reset_token, token_expiry FROM users WHERE reset_token = ? LIMIT 1');
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc() ?: null;
    $stmt->close();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newPassword = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($token === '') {
        $message = 'No reset token provided.';
        $user = null;
    } elseif (!$user || empty($user['reset_token']) || empty($user['token_expiry']) || strtotime((string) $user['token_expiry']) < time()) {
        $message = 'Invalid or expired reset link.';
        $user = null;
    } elseif ($newPassword === '' || $confirmPassword === '') {
        $message = 'Password and confirmation are required.';
    } elseif ($newPassword !== $confirmPassword) {
        $message = 'Passwords do not match.';
    } else {
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $update = $conn->prepare('UPDATE users SET password = ?, reset_token = NULL, token_expiry = NULL WHERE id = ?');
        $update->bind_param('si', $passwordHash, $user['id']);
        $update->execute();
        $update->close();
        log_activity($conn, (string) ($user['email'] ?? null), 'password_reset_success', 'Password reset with token');
        $messageType = 'success';
        $message = 'Password has been reset successfully. You can now log in.';
        $user = null;
    }
} elseif ($token !== '' && (!$user || empty($user['reset_token']) || empty($user['token_expiry']) || strtotime((string) $user['token_expiry']) < time())) {
    $message = 'Invalid or expired reset link.';
    $user = null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="page">
        <div class="container">
            <h2>Reset Password</h2>
            <?php if ($message !== null): ?>
                <div class="message <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <?php if ($user !== null): ?>
                <form action="" method="post">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
                    <div>
                        <label for="password">New Password</label>
                        <input id="password" type="password" name="password" placeholder="New Password" required>
                    </div>
                    <div>
                        <label for="confirm_password">Confirm Password</label>
                        <input id="confirm_password" type="password" name="confirm_password" placeholder="Confirm New Password" required>
                    </div>
                    <button type="submit">Reset Password</button>
                </form>
            <?php else: ?>
                <div class="links">
                    <p><a href="forgotpassword.php">Request a new link</a></p>
                    <p><a href="login.php">Back to login</a></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
