<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

session_start();
require_once 'db.php';
require_once 'auth.php';

ensure_user_table($conn);
bootstrap_session_from_cookie($conn);

if (isset($_SESSION['user_email'])) {
    $sessionRole = (string) ($_SESSION['user_role'] ?? 'user');
    if ($sessionRole === 'admin') {
        header('Location: admin.php');
        exit;
    }

    header('Location: dashboard.php');
    exit;
}

$error = null;
$success = null;

$resetEmail = strtolower(trim((string) ($_SESSION['password_reset_email'] ?? '')));
$requestedAt = (int) ($_SESSION['password_reset_requested_at'] ?? 0);
$isRequestValid = $resetEmail !== '' && $requestedAt > 0 && (time() - $requestedAt) <= 900;

if (!$isRequestValid) {
    unset($_SESSION['password_reset_email'], $_SESSION['password_reset_requested_at']);
    $error = 'Reset session expired. Please start again.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isRequestValid) {
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($password === '' || $confirmPassword === '') {
        $error = 'Please fill in all fields.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } else {
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE users SET password = ?, reset_token = NULL, token_expiry = NULL, failed_login_attempts = 0, locked_until = NULL WHERE email = ?');
        $stmt->bind_param('ss', $passwordHash, $resetEmail);
        $updated = $stmt->execute();
        $stmt->close();

        if ($updated) {
            unset($_SESSION['password_reset_email'], $_SESSION['password_reset_requested_at']);
            log_activity($conn, $resetEmail, 'password_reset_success', 'Password reset completed from reset page');
            $success = 'Password updated successfully. You can now log in.';
        } else {
            $error = 'Could not reset password. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password-medztrack</title>
    <!-- CSS for this page is in Login.css -->
    <link rel="stylesheet" href="Login.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container auth-container">
        <div class="tabs">
            <a href="login.php" class="tab">Login</a>
            <a href="reset_password.php" class="tab active">Reset Password</a>
        </div>

        <div class="form-container">
            <form class="form active" method="post" action="">
                <h2>Reset password</h2>

                <?php if ($error !== null): ?>
                    <p class="auth-message error"><?= htmlspecialchars($error) ?></p>
                <?php endif; ?>

                <?php if ($success !== null): ?>
                    <p class="auth-message success"><?= htmlspecialchars($success) ?></p>
                    <a href="login.php" class="auth-action-link">Back to login</a>
                <?php else: ?>
                    <div class="input-group password-group">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="reset-password" name="password" placeholder="New password" autocomplete="new-password" minlength="6" required>
                        <button type="button" class="password-toggle" id="toggle-reset-password" aria-label="Show new password">
                            <i class="fas fa-eye-slash"></i>
                        </button>
                    </div>

                    <div class="input-group password-group">
                        <i class="fas fa-lock"></i>
                        <input type="password" id="reset-confirm-password" name="confirm_password" placeholder="Confirm new password" autocomplete="new-password" minlength="6" required>
                        <button type="button" class="password-toggle" id="toggle-reset-confirm-password" aria-label="Show confirm password">
                            <i class="fas fa-eye-slash"></i>
                        </button>
                    </div>

                    <button type="submit" class="btn">Reset Password</button>
                    <a href="forgot_password.php" class="auth-action-link">Start reset again</a>
                <?php endif; ?>
            </form>
        </div>
    </div>
    <script>
        const resetPasswordInput = document.getElementById('reset-password');
        const toggleResetPassword = document.getElementById('toggle-reset-password');
        const resetConfirmPasswordInput = document.getElementById('reset-confirm-password');
        const toggleResetConfirmPassword = document.getElementById('toggle-reset-confirm-password');

        if (resetPasswordInput && toggleResetPassword) {
            toggleResetPassword.addEventListener('click', () => {
                const isHidden = resetPasswordInput.type === 'password';
                resetPasswordInput.type = isHidden ? 'text' : 'password';
                toggleResetPassword.innerHTML = isHidden
                    ? '<i class="fas fa-eye"></i>'
                    : '<i class="fas fa-eye-slash"></i>';
                toggleResetPassword.setAttribute('aria-label', isHidden ? 'Hide new password' : 'Show new password');
            });
        }

        if (resetConfirmPasswordInput && toggleResetConfirmPassword) {
            toggleResetConfirmPassword.addEventListener('click', () => {
                const isHidden = resetConfirmPasswordInput.type === 'password';
                resetConfirmPasswordInput.type = isHidden ? 'text' : 'password';
                toggleResetConfirmPassword.innerHTML = isHidden
                    ? '<i class="fas fa-eye"></i>'
                    : '<i class="fas fa-eye-slash"></i>';
                toggleResetConfirmPassword.setAttribute('aria-label', isHidden ? 'Hide confirm password' : 'Show confirm password');
            });
        }
    </script>
</body>
</html>
