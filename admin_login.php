<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

session_start();
require_once 'db.php';
require_once 'auth.php';

ensure_user_table($conn);
bootstrap_session_from_cookie($conn);

if (isset($_SESSION['user_email']) && (string) ($_SESSION['user_role'] ?? 'user') === 'admin') {
    header('Location: admin.php');
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
        $stmt = $conn->prepare('SELECT name, password, role FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc() ?: [];
        $stmt->close();

        $hashedPassword = (string) ($user['password'] ?? '');
        $role = (string) ($user['role'] ?? 'user');

        if ($hashedPassword !== '' && password_verify($password, $hashedPassword)) {
        if ($role !== 'admin') {
            $error = 'This account is not allowed to access the admin console.';
        } else {
            $_SESSION['user_email'] = $email;
                $_SESSION['user_name'] = trim((string) ($user['name'] ?? '')) !== '' ? (string) $user['name'] : $email;
                $_SESSION['user_role'] = 'admin';

                if ($rememberMe) {
                    store_remember_me($conn, $email);
                } else {
                    clear_remember_me($conn, $email);
                    set_session_cookie_session_only();
                }

                header('Location: admin.php');
                exit;
            }
        } else {
            $error = 'Invalid email or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login - Medicine Expiry Tracker</title>
    <link rel="stylesheet" href="Login.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="admin-login">
    <div class="container auth-container">
        <div class="form-container">
            <form class="form active" id="admin-login-form" action="" method="post">
                <div class="admin-badge">ADMIN ACCESS</div>
                <h2>Admin Console</h2>
                <p class="admin-subtitle">Restricted access for the admin team.</p>

                <?php if ($error !== null): ?>
                    <p class="auth-message error"><?= htmlspecialchars($error) ?></p>
                <?php endif; ?>

                <div class="input-group">
                    <i class="fas fa-envelope"></i>
                    <input type="email" id="admin-email" name="email" placeholder="Email" autocomplete="username" autocapitalize="none" spellcheck="false" required>
                </div>

                <div class="input-group password-group">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="admin-password" name="password" placeholder="Password" autocomplete="current-password" required>
                    <button type="button" class="password-toggle" id="toggle-admin-password" aria-label="Show password">
                        <i class="fas fa-eye"></i>
                    </button>
                </div>

                <div class="remember-forgot">
                    <label class="remember">
                        <input type="checkbox" name="remember_me"> Remember me
                    </label>
                    <a href="forgotpassword.php" class="forgot">Forgot Password?</a>
                </div>

                <button type="submit" class="btn">Admin Login</button>

            </form>
        </div>
    </div>
    <script>
        const adminPasswordInput = document.getElementById('admin-password');
        const toggleAdminPassword = document.getElementById('toggle-admin-password');

        if (adminPasswordInput && toggleAdminPassword) {
            toggleAdminPassword.addEventListener('click', () => {
                const isHidden = adminPasswordInput.type === 'password';
                adminPasswordInput.type = isHidden ? 'text' : 'password';
                toggleAdminPassword.innerHTML = isHidden
                    ? '<i class="fas fa-eye-slash"></i>'
                    : '<i class="fas fa-eye"></i>';
                toggleAdminPassword.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
            });
        }
    </script>
</body>
</html>
