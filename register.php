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
        && preg_match('/[a-z]/', $password);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    if ($name === '' || $email === '' || $password === '' || $confirmPassword === '') {
        $error = 'Please fill in all fields.';
    } elseif (preg_match('/\d/', $name)) {
        $error = 'Full name cannot contain numbers.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Passwords do not match.';
    } elseif (!isStrongPassword($password)) {
        $error = 'Password must be at least 8 characters and include both uppercase and lowercase letters.';
    } else {
        $check = $conn->prepare('SELECT id FROM users WHERE email = ?');
        $check->bind_param('s', $email);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();

        if ($exists) {
            $error = 'That email is already registered.';
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $themePreference = 'light';
            $stmt = $conn->prepare('INSERT INTO users (name, email, password, theme_preference) VALUES (?, ?, ?, ?)');
            $stmt->bind_param('ssss', $name, $email, $hashedPassword, $themePreference);

            if ($stmt->execute()) {
                $_SESSION['user_email'] = $email;
                $_SESSION['user_name'] = $name;
                $_SESSION['user_role'] = 'user';
                $_SESSION['theme'] = 'light';
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
    <title>Sign Up-medztrack</title>
    <!-- CSS for this page is in Login.css -->
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
                    <i class="fas fa-envelope"></i>
                    <input type="email" id="signup-email" name="email" placeholder="Email address" autocomplete="email" autocapitalize="none" spellcheck="false" required>
                </div>

                <div class="input-group password-group">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="signup-password" name="password" placeholder="Password" autocomplete="new-password" minlength="8" pattern="(?=.*[a-z])(?=.*[A-Z]).{8,}" title="Use at least 8 characters with both uppercase and lowercase letters." required>
                    <button type="button" class="password-toggle" id="toggle-signup-password" aria-label="Show password">
                        <i class="fas fa-eye-slash"></i>
                    </button>
                </div>
                <p class="password-help">Use at least 8 characters with both uppercase and lowercase letters.</p>

                <div class="input-group password-group">
                    <i class="fas fa-lock"></i>
                    <input type="password" id="signup-confirm-password" name="confirm_password" placeholder="Confirm Password" autocomplete="new-password" minlength="8" required>
                    <button type="button" class="password-toggle" id="toggle-signup-confirm-password" aria-label="Show confirm password">
                        <i class="fas fa-eye-slash"></i>
                    </button>
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
    <script>
        const signupPasswordInput = document.getElementById('signup-password');
        const toggleSignupPassword = document.getElementById('toggle-signup-password');
        const signupConfirmPasswordInput = document.getElementById('signup-confirm-password');
        const toggleSignupConfirmPassword = document.getElementById('toggle-signup-confirm-password');

        if (signupPasswordInput && toggleSignupPassword) {
            toggleSignupPassword.addEventListener('click', () => {
                const isHidden = signupPasswordInput.type === 'password';
                signupPasswordInput.type = isHidden ? 'text' : 'password';
                toggleSignupPassword.innerHTML = isHidden
                    ? '<i class="fas fa-eye"></i>'
                    : '<i class="fas fa-eye-slash"></i>';
                toggleSignupPassword.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
            });
        }

        if (signupConfirmPasswordInput && toggleSignupConfirmPassword) {
            toggleSignupConfirmPassword.addEventListener('click', () => {
                const isHidden = signupConfirmPasswordInput.type === 'password';
                signupConfirmPasswordInput.type = isHidden ? 'text' : 'password';
                toggleSignupConfirmPassword.innerHTML = isHidden
                    ? '<i class="fas fa-eye"></i>'
                    : '<i class="fas fa-eye-slash"></i>';
                toggleSignupConfirmPassword.setAttribute('aria-label', isHidden ? 'Hide confirm password' : 'Show confirm password');
            });
        }
    </script>
</body>
</html>
