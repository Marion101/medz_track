<?php
declare(strict_types=1);

session_start();
require_once 'db.php';
require_once 'auth.php';

ensure_user_table($conn);
require_auth($conn);

$email = (string) ($_SESSION['user_email'] ?? '');
$role = (string) ($_SESSION['user_role'] ?? 'user');

if ($email !== '') {
    $stmt = $conn->prepare('SELECT role FROM users WHERE email = ? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $role = (string) ($row['role'] ?? $role);
    $_SESSION['user_role'] = $role;
}

if ($role === 'admin') {
    header('Location: admin_reports.php');
    exit;
}

header('Location: dashboard.php');
exit;
