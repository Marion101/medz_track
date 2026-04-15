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
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));

    if ($email === '') {
        $error = 'Email is required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } else {
        $stmt = $conn->prepare('SELECT email FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc() ?: [];
        $stmt->close();

        if ($user === []) {
            $error = 'No account found with that email.';
            log_activity($conn, $email, 'password_reset_failed', 'Email not found in forgot password');
        } else {
            $_SESSION['password_reset_email'] = $email;
            $_SESSION['password_reset_requested_at'] = time();
            log_activity($conn, $email, 'password_reset_requested', 'User started forgot password flow');
            header('Location: reset_password.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password-medztrack</title>
    <link rel="stylesheet" href="Login.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container auth-container">
        <div class="tabs">
            <a href="login.php" class="tab">Login</a>
            <a href="forgot_password.php" class="tab active">Forgot Password</a>
        </div>

        <div class="form-container">
            <form class="form active" method="post" action="">
                <h2>Forgot password</h2>

                <?php if ($error !== null): ?>
                    <p class="auth-message error"><?= htmlspecialchars($error) ?></p>
                <?php endif; ?>

                <?php if ($message !== null): ?>
                    <p class="auth-message success"><?= htmlspecialchars($message) ?></p>
                <?php endif; ?>

                <div class="input-group">
                    <i class="fas fa-envelope"></i>
                    <input type="email" name="email" placeholder="Enter your account email" autocomplete="email" autocapitalize="none" spellcheck="false" required>
                </div>

                <button type="submit" class="btn">Continue</button>
                <a href="login.php" class="auth-action-link">Back to login</a>
            </form>
        </div>
    </div>
</body>
</html>
