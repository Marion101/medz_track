<?php
declare(strict_types=1);

session_start();
require_once 'db.php';
require_once 'auth.php';

if (isset($_SESSION['user_email'])) {
    log_activity($conn, (string) $_SESSION['user_email'], 'logout', 'User logged out');
    clear_remember_me($conn, (string) $_SESSION['user_email']);
} else {
    clear_remember_cookie();
    clear_session_cookie();
}

$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
?>
