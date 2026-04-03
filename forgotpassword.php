<?php
declare(strict_types=1);

require_once 'db.php';
require_once 'auth.php';
require_once __DIR__ . '/phpmailer/src/Exception.php';
require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

ensure_user_table($conn);

$message = null;
$messageType = 'error';

function send_reset_email(string $toEmail, string $toName, string $resetLink): bool
{
    $mailConfig = require 'mail_config.php';

    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = (string) $mailConfig['host'];
        $mail->SMTPAuth = true;
        $mail->Username = (string) $mailConfig['username'];
        $mail->Password = (string) $mailConfig['password'];
        $mail->SMTPSecure = (string) $mailConfig['encryption'];
        $mail->Port = (int) $mailConfig['port'];

        $mail->setFrom((string) $mailConfig['from_email'], (string) $mailConfig['from_name']);
        $mail->addAddress($toEmail, $toName !== '' ? $toName : $toEmail);
        $mail->isHTML(true);
        $mail->Subject = 'Medz track password reset';
        $mail->Body = '<p>Hello ' . htmlspecialchars($toName !== '' ? $toName : 'there', ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>We received a request to reset your password. Click the link below to continue:</p>'
            . '<p><a href="' . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8') . '</a></p>'
            . '<p>This link expires in 10 minutes.</p>';
        $mail->AltBody = "Reset your password using this link:\n{$resetLink}\n\nThis link expires in 10 minutes.";

        return $mail->send();
    } catch (Exception) {
        return false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = 'Please enter a valid email address.';
    } else {
        $stmt = $conn->prepare('SELECT id, name, email FROM users WHERE email = ? LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $user = $result->fetch_assoc() ?: null;
        $stmt->close();

        if ($user === null) {
            $message = 'If the email exists in our system, a reset link will be sent.';
        } else {
            $token = bin2hex(random_bytes(32));
            $expiry = date('Y-m-d H:i:s', strtotime('+10 minutes'));

            $update = $conn->prepare('UPDATE users SET reset_token = ?, token_expiry = ? WHERE id = ?');
            $update->bind_param('ssi', $token, $expiry, $user['id']);
            $update->execute();
            $update->close();

            $resetLink = 'http://localhost/medz_track/resetpassword.php?token=' . urlencode($token);
            if (send_reset_email((string) $user['email'], (string) ($user['name'] ?? ''), $resetLink)) {
                log_activity($conn, (string) $user['email'], 'password_reset_request', 'Reset email sent');
                $messageType = 'success';
                $message = 'A reset link has been sent to your email address.';
            } else {
                log_activity($conn, (string) $user['email'], 'password_reset_request_failed', 'Reset link created but email send failed');
                $message = 'We created the reset link, but the email could not be sent. Check your SMTP settings in mail_config.php.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="page">
        <div class="container">
            <h2>Forgot Password</h2>
            <?php if ($message !== null): ?>
                <div class="message <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>
            <form action="" method="post">
                <div>
                    <label for="email">Email Address</label>
                    <input id="email" type="email" name="email" placeholder="Enter your email address" required>
                </div>
                <button type="submit">Send Reset Link</button>
            </form>
            <div class="links">
                <p><a href="login.php">Back to login</a></p>
            </div>
        </div>
    </div>
</body>
</html>
