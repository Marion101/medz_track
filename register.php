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
    header('Location: dashboard.php');
    exit;
}

$error = null;

function isStrongPassword(string $password): bool
{
    return strlen($password) >= 8
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[a-z]/', $password)
        && preg_match('/[0-9]/', $password)
        && preg_match('/[^A-Za-z0-9]/', $password);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $phone = normalize_phone((string) ($_POST['phone'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($name === '' || $phone === '' || $email === '' || $password === '' || $confirmPassword === '') {
        $error = 'Please fill in all fields.';
    } elseif (!preg_match('/^\+?[0-9]{10,15}$/', $phone)) {
        $error = 'Enter a valid phone number with 10 to 15 digits.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } elseif (!isStrongPassword($password)) {
        $error = 'Password must be at least 8 characters and include uppercase, lowercase, a number, and a symbol.';
    } else {
        $check = $conn->prepare('SELECT id FROM users WHERE email = ? OR phone = ?');
        $check->bind_param('ss', $email, $phone);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();

        if ($exists) {
            $error = 'That email or phone number is already registered.';
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conn->prepare('INSERT INTO users (name, phone, email, password) VALUES (?, ?, ?, ?)');
            $stmt->bind_param('ssss', $name, $phone, $email, $hashedPassword);

            if ($stmt->execute()) {
                $_SESSION['user_email'] = $email;
                $_SESSION['user_name'] = $name;
                $_SESSION['user_role'] = 'user';
                log_activity($conn, $email, 'register_success', 'New account created');
                header('Location: dashboard.php');
                exit;
            }

            $error = 'Registration failed. Please try again.';
            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign Up - Medicine Expiry Tracker</title>
    <link rel="stylesheet" href="Login.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container auth-container">
        <div class="tabs">
            <a href="login.php" class="tab">Login</a>
            <a href="register.php" class="tab active">Sign Up</a>
        </div>

        <div class="form-container">
            <form class="form active" id="signup-form" method="post" action="">
                <h2>Create an account</h2>

                <?php if ($error !== null): ?>
                    <p class="auth-message error"><?= htmlspecialchars($error) ?></p>
                <?php endif; ?>

                <div class="input-group">
                    <i class="fas fa-user"></i>
                    <input type="text" id="signup-name" name="name" placeholder="Full name" required>
                </div>

                <div class="input-group">
                    <i class="fas fa-phone"></i>
                    <input type="tel" id="signup-phone" name="phone" placeholder="Phone number" autocomplete="tel" inputmode="tel" required>
                </div>

                <div class="input-group">
                    <i class="fas fa-envelope"></i>
                    <input type="email" id="signup-email" name="email" placeholder="Email address" autocomplete="email" autocapitalize="none" spellcheck="false" required>
                </div>

                <div class="input-group">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="signup-password" name="password" placeholder="Password" autocomplete="new-password" minlength="8" pattern="(?=.*[a-z])(?=.*[A-Z])(?=.*\\d)(?=.*[^A-Za-z0-9]).{8,}" title="Use at least 8 characters with uppercase, lowercase, a number, and a symbol." required>
                </div>
                <p class="password-help">Use 8+ characters with uppercase, lowercase, a number, and a symbol.</p>

                <div class="input-group">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="signup-confirm-password" name="confirm_password" placeholder="Confirm Password" autocomplete="new-password" minlength="8" required>
                </div>

                <div class="remember-forgot">
                    <label class="remember">
                        <input type="checkbox"> Remember me
                    </label>
                </div>

                <button type="submit" class="btn">Sign up</button>

                <div class="signup-link">
                    <p>Already have an account? <a href="login.php">Login here</a></p>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
