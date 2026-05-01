<?php
declare(strict_types=1);

function ensure_user_table(mysqli $conn): void
{
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS name VARCHAR(100) DEFAULT NULL AFTER id");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS phone VARCHAR(20) DEFAULT NULL AFTER name");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS role VARCHAR(20) NOT NULL DEFAULT 'user' AFTER phone");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER role");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS remember_token_hash VARCHAR(64) DEFAULT NULL AFTER password");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS remember_token_expires_at DATETIME DEFAULT NULL AFTER remember_token_hash");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_phone_otp VARCHAR(10) DEFAULT NULL AFTER remember_token_expires_at");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_phone_otp_expires_at DATETIME DEFAULT NULL AFTER reset_phone_otp");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token VARCHAR(64) DEFAULT NULL AFTER reset_phone_otp_expires_at");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS token_expiry DATETIME DEFAULT NULL AFTER reset_token");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS theme_preference VARCHAR(10) NOT NULL DEFAULT 'light' AFTER token_expiry");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS failed_login_attempts INT NOT NULL DEFAULT 0 AFTER theme_preference");
    $conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS locked_until DATETIME DEFAULT NULL AFTER failed_login_attempts");
}

function login_max_attempts(): int
{
    return 5;
}

function login_lock_minutes(): int
{
    return 15;
}

function is_login_locked(?string $lockedUntil): bool
{
    if ($lockedUntil === null || trim($lockedUntil) === '') {
        return false;
    }

    $lockTime = strtotime($lockedUntil);
    if ($lockTime === false) {
        return false;
    }

    return $lockTime > time();
}

function login_lock_seconds_remaining(?string $lockedUntil): int
{
    if (!is_login_locked($lockedUntil)) {
        return 0;
    }

    $lockTime = strtotime((string) $lockedUntil);
    if ($lockTime === false) {
        return 0;
    }

    return max(0, $lockTime - time());
}

function login_lock_message(?string $lockedUntil): string
{
    $seconds = login_lock_seconds_remaining($lockedUntil);
    if ($seconds <= 0) {
        return 'Too many failed attempts. Please try again later.';
    }

    $minutes = (int) ceil($seconds / 60);
    return 'Too many failed attempts. Try again in ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.';
}

function clear_login_failures(mysqli $conn, string $email): void
{
    $stmt = $conn->prepare('UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE email = ?');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $stmt->close();
}

function record_login_failure(mysqli $conn, string $email): void
{
    $stmt = $conn->prepare('SELECT failed_login_attempts, locked_until FROM users WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();

    if ($row === []) {
        return;
    }

    $lockedUntil = isset($row['locked_until']) ? (string) $row['locked_until'] : null;
    if (is_login_locked($lockedUntil)) {
        return;
    }

    $attempts = (int) ($row['failed_login_attempts'] ?? 0) + 1;
    if ($attempts >= login_max_attempts()) {
        $newLockedUntil = (new DateTimeImmutable('+' . login_lock_minutes() . ' minutes'))->format('Y-m-d H:i:s');
        $update = $conn->prepare('UPDATE users SET failed_login_attempts = ?, locked_until = ? WHERE email = ?');
        $update->bind_param('iss', $attempts, $newLockedUntil, $email);
        $update->execute();
        $update->close();
        return;
    }

    $update = $conn->prepare('UPDATE users SET failed_login_attempts = ? WHERE email = ?');
    $update->bind_param('is', $attempts, $email);
    $update->execute();
    $update->close();
}

function ensure_activity_log_table(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS activity_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_email VARCHAR(255) DEFAULT NULL,
            action VARCHAR(100) NOT NULL,
            details TEXT DEFAULT NULL,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    );
}

function log_activity(mysqli $conn, ?string $email, string $action, string $details = ''): void
{
    ensure_activity_log_table($conn);

    $stmt = $conn->prepare('INSERT INTO activity_log (user_email, action, details, ip_address, user_agent) VALUES (?, ?, ?, ?, ?)');
    $ipAddress = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $action = substr(trim($action), 0, 100);
    $details = trim($details);
    $stmt->bind_param('sssss', $email, $action, $details, $ipAddress, $userAgent);
    $stmt->execute();
    $stmt->close();
}

function bind_stmt_params(mysqli_stmt $stmt, string $types, array $values): void
{
    if ($types === '' || $values === []) {
        return;
    }

    $stmt->bind_param($types, ...$values);
}

function medicine_is_expired(?string $expiryDate): bool
{
    if ($expiryDate === null || trim($expiryDate) === '') {
        return false;
    }

    $today = (new DateTimeImmutable('today'))->format('Y-m-d');
    return trim($expiryDate) < $today;
}

function log_medicine_removal_alert(
    mysqli $conn,
    ?string $removedByEmail,
    ?string $ownerEmail,
    string $medicineName,
    ?string $expiryDate
): void {
    $isExpired = medicine_is_expired($expiryDate);
    $details = 'Medicine removed | expiry_status: ' . ($isExpired ? 'expired' : 'not_expired')
        . ' | medicine: ' . trim($medicineName)
        . ' | owner: ' . trim((string) $ownerEmail)
        . ' | removed_by: ' . trim((string) $removedByEmail)
        . ' | expiry: ' . trim((string) $expiryDate);

    log_activity($conn, $removedByEmail, 'med_removed', $details);
}

function log_medicine_addition_alert(
    mysqli $conn,
    ?string $addedByEmail,
    ?string $ownerEmail,
    string $medicineName,
    ?string $expiryDate
): void {
    $isExpired = medicine_is_expired($expiryDate);
    $details = 'Medicine added | expiry_status: ' . ($isExpired ? 'expired' : 'not_expired')
        . ' | medicine: ' . trim($medicineName)
        . ' | owner: ' . trim((string) $ownerEmail)
        . ' | added_by: ' . trim((string) $addedByEmail)
        . ' | expiry: ' . trim((string) $expiryDate);

    log_activity($conn, $addedByEmail, 'med_added', $details);
}

function remember_cookie_name(): string
{
    return 'medz_remember';
}

function session_lifetime_seconds(): int
{
    return 60 * 60 * 24 * 30;
}

function cookie_options(int $expires): array
{
    return [
        'expires' => $expires,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function set_remember_cookie(string $token, int $expiresAt): void
{
    setcookie(remember_cookie_name(), $token, cookie_options($expiresAt));
}

function clear_remember_cookie(): void
{
    setcookie(remember_cookie_name(), '', cookie_options(time() - 3600));
}

function set_session_cookie_persistent(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    setcookie(session_name(), session_id(), cookie_options(time() + session_lifetime_seconds()));
}

function set_session_cookie_session_only(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    setcookie(session_name(), session_id(), [
        'expires' => 0,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function clear_session_cookie(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    setcookie(session_name(), '', cookie_options(time() - 3600));
}

function store_remember_me(mysqli $conn, string $email): void
{
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = time() + session_lifetime_seconds();
    $expiresAtSql = date('Y-m-d H:i:s', $expiresAt);

    $stmt = $conn->prepare('UPDATE users SET remember_token_hash = ?, remember_token_expires_at = ? WHERE email = ?');
    $stmt->bind_param('sss', $tokenHash, $expiresAtSql, $email);
    $stmt->execute();
    $stmt->close();

    set_remember_cookie($token, $expiresAt);
    set_session_cookie_persistent();
}

function clear_remember_me(mysqli $conn, ?string $email = null): void
{
    if ($email !== null) {
        $stmt = $conn->prepare('UPDATE users SET remember_token_hash = NULL, remember_token_expires_at = NULL WHERE email = ?');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $stmt->close();
    }

    clear_remember_cookie();
    clear_session_cookie();
}

function bootstrap_session_from_cookie(mysqli $conn): void
{
    if (isset($_SESSION['user_email'])) {
        return;
    }

    $token = (string) ($_COOKIE[remember_cookie_name()] ?? '');
    if ($token === '') {
        return;
    }

    $tokenHash = hash('sha256', $token);
    $stmt = $conn->prepare('SELECT email, name, role, theme_preference FROM users WHERE remember_token_hash = ? AND remember_token_expires_at > NOW() LIMIT 1');
    $stmt->bind_param('s', $tokenHash);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc() ?: [];
    $stmt->close();

    if ($user === []) {
        clear_remember_cookie();
        return;
    }

    $_SESSION['user_email'] = (string) $user['email'];
    $_SESSION['user_name'] = trim((string) ($user['name'] ?? '')) !== '' ? (string) $user['name'] : (string) $user['email'];
    $_SESSION['user_role'] = (string) ($user['role'] ?? 'user');
    $_SESSION['theme'] = normalize_theme_preference((string) ($user['theme_preference'] ?? 'light'));
}

function require_auth(mysqli $conn): void
{
    bootstrap_session_from_cookie($conn);

    if (!isset($_SESSION['user_email'])) {
        header('Location: login.php');
        exit;
    }
}

function normalize_phone(string $phone): string
{
    $phone = trim($phone);
    return preg_replace('/[^0-9+]/', '', $phone) ?? '';
}

function password_matches_or_legacy(string $plainPassword, string $storedPassword): bool
{
    if ($storedPassword === '') {
        return false;
    }

    if (password_verify($plainPassword, $storedPassword)) {
        return true;
    }

    return hash_equals($storedPassword, $plainPassword);
}

function upgrade_password_hash_if_legacy(mysqli $conn, string $email, string $plainPassword, string $storedPassword): void
{
    $needsUpgrade = password_get_info($storedPassword)['algo'] === null;
    if (!$needsUpgrade) {
        return;
    }

    $newHash = password_hash($plainPassword, PASSWORD_DEFAULT);
    $stmt = $conn->prepare('UPDATE users SET password = ? WHERE email = ?');
    $stmt->bind_param('ss', $newHash, $email);
    $stmt->execute();
    $stmt->close();
}

function normalize_theme_preference(string $theme): string
{
    return $theme === 'dark' ? 'dark' : 'light';
}

function current_theme_preference(): string
{
    return normalize_theme_preference((string) ($_SESSION['theme'] ?? 'light'));
}

function theme_body_class(string $baseClass = ''): string
{
    $classes = [];
    if (trim($baseClass) !== '') {
        $classes[] = trim($baseClass);
    }

    if (current_theme_preference() === 'dark') {
        $classes[] = 'dark-theme';
    }

    return implode(' ', $classes);
}
