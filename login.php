<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

session_start();
require_once 'db.php';
require_once 'auth.php';

// Prepare login system
ensure_user_table($conn);
bootstrap_session_from_cookie($conn);

// Send logged-in users to the right page
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

// Handle login form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $rememberMe = isset($_POST['remember_me']);

    if ($email === '' || $password === '') {
        $error = 'Email and password are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } else {
        $stmt = $conn->prepare('SELECT name, password, role, theme_preference, failed_login_attempts, locked_until FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc() ?: [];
        $stmt->close();

        $hashedPassword = (string) ($user['password'] ?? '');
        $displayName = trim((string) ($user['name'] ?? ''));
        $lockedUntil = isset($user['locked_until']) ? (string) $user['locked_until'] : null;

        if ($user !== [] && is_login_locked($lockedUntil)) {
            $error = login_lock_message($lockedUntil);
            log_activity($conn, $email, 'login_blocked', 'Account temporarily locked');
        } elseif (password_matches_or_legacy($password, $hashedPassword)) {
            clear_login_failures($conn, $email);
            upgrade_password_hash_if_legacy($conn, $email, $password, $hashedPassword);

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
        } else {
            if ($user !== []) {
                record_login_failure($conn, $email);
                $lockStmt = $conn->prepare('SELECT locked_until FROM users WHERE email = ? LIMIT 1');
                $lockStmt->bind_param('s', $email);
                $lockStmt->execute();
                $lockData = $lockStmt->get_result()->fetch_assoc() ?: [];
                $lockStmt->close();
                $newLockedUntil = isset($lockData['locked_until']) ? (string) $lockData['locked_until'] : null;

                if (is_login_locked($newLockedUntil)) {
                    $error = login_lock_message($newLockedUntil);
                    log_activity($conn, $email, 'login_blocked', 'Account temporarily locked after failed attempts');
                } else {
                    $error = 'Invalid email or password.';
                }
            } else {
                $error = 'Invalid email or password.';
            }

            log_activity($conn, $email, 'login_failed', 'Invalid email or password');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login-medztrack</title>
    <!-- CSS for this page is in Login.css -->
    <link rel="stylesheet" href="Login.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="container auth-container">
        <!-- Login and sign up tabs -->
        <div class="tabs">
            <a href="login.php" class="tab active">Login</a>
            <a href="register.php" class="tab">Sign Up</a>
        </div>
 
        <div class="form-container">
            <!-- Login form -->
            <form class="form active" id="login-form" action="" method="post" autocomplete="on">
                <h2>Welcome!</h2>

                <!-- Error message -->
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
                        <i class="fas fa-eye-slash"></i>
                    </button>
                </div>

                <div class="remember-forgot">
                    <label class="remember">
                        <input type="checkbox" name="remember_me"> Remember me
                    </label>
                    <a class="forgot" href="forgot_password.php">Forgot password?</a>
                </div>
                <p class="password-help">Use this only on a trusted device. Logging out clears remembered login.</p>

                <button type="submit" class="btn">Login</button>

                <div class="signup-link">
                    <p>Don't have an account? <a href="register.php">Sign up</a></p>
                </div>
            </form>
        </div>
    </div>
    <script>
        // Show or hide the password- the eye icon
        const passwordInput = document.getElementById('login-password');
        const togglePassword = document.getElementById('toggle-password');

        if (passwordInput && togglePassword) {
            togglePassword.addEventListener('click', () => {
                const isHidden = passwordInput.type === 'password';
                passwordInput.type = isHidden ? 'text' : 'password';
                togglePassword.innerHTML = isHidden
                    ? '<i class="fas fa-eye"></i>'
                    : '<i class="fas fa-eye-slash"></i>';
                togglePassword.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
            });
        }
    </script>
</body>
</html>



