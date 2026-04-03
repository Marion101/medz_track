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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $rememberMe = isset($_POST['remember_me']);

    if ($email === '' || $password === '') {
        $error = 'Email and password are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } else {
        $stmt = $conn->prepare('SELECT name, password, role, theme_preference FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc() ?: [];
        $stmt->close();

        $hashedPassword = (string) ($user['password'] ?? '');
        $displayName = trim((string) ($user['name'] ?? ''));

        if ($hashedPassword !== '' && password_verify($password, $hashedPassword)) {
            $_SESSION['user_email'] = $email;
            $_SESSION['user_name'] = $displayName !== '' ? $displayName : $email;
            $_SESSION['user_role'] = (string) ($user['role'] ?? 'user');
            $_SESSION['theme'] = normalize_theme_preference((string) ($user['theme_preference'] ?? 'light'));

            if ($rememberMe) {
                store_remember_me($conn, $email);
            } else {
                clear_remember_me($conn, $email);
                set_session_cookie_session_only();
            }

            log_activity($conn, $email, 'login_success', $rememberMe ? 'Remember me checked' : 'Standard login');
            header('Location: dashboard.php');
            exit;
        }

        log_activity($conn, $email, 'login_failed', 'Invalid email or password');
        $error = 'Invalid email or password.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Medicine Expiry Tracker</title>
    <link rel="stylesheet" href="Login.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container auth-container">
        <div class="tabs">
            <a href="login.php" class="tab active">Login</a>
            <a href="register.php" class="tab">Sign Up</a>
        </div>
 
        <div class="form-container">
            <form class="form active" id="login-form" action="" method="post">
                <h2>Welcome!</h2>

                <?php if ($error !== null): ?>
                    <p class="auth-message error"><?= htmlspecialchars($error) ?></p>
                <?php endif; ?>

                <div class="input-group">
                    <i class="fas fa-envelope"></i>
                    <input type="email" id="login-email" name="email" placeholder="Email" autocomplete="username" autocapitalize="none" spellcheck="false" required>
                </div>

                <div class="input-group password-group">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="login-password" name="password" placeholder="Password" autocomplete="current-password" required>
                    <button type="button" class="password-toggle" id="toggle-password" aria-label="Show password">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>

                <div class="remember-forgot">
                    <label class="remember">
                        <input type="checkbox" name="remember_me"> Remember me
                    </label>
                    <a href="forgotpassword.php" class="forgot">Forgot Password?</a>
                </div>

                <button type="submit" class="btn">Login</button>

                <div class="signup-link">
                    <p>Don't have an account? <a href="register.php">Sign up</a></p>
                    <p><a href="admin_login.php">Admin Login</a></p>
                </div>
            </form>
        </div>
    </div>
    <script>
        const passwordInput = document.getElementById('login-password');
        const togglePassword = document.getElementById('toggle-password');

        if (passwordInput && togglePassword) {
            togglePassword.addEventListener('click', () => {
                const isHidden = passwordInput.type === 'password';
                passwordInput.type = isHidden ? 'text' : 'password';
                togglePassword.innerHTML = isHidden
                    ? '<i class="fas fa-eye-slash"></i>'
                    : '<i class="fas fa-eye"></i>';
                togglePassword.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
            });
        }
    </script>
</body>
</html>


