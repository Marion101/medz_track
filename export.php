<?php
declare(strict_types=1);
session_start();
require_once 'db.php';
require_once 'auth.php';

ensure_user_table($conn);
require_auth($conn);

if (!isset($_SESSION['user_email'])) {
    header('Location: login.php');
    exit;
}

$email = $_SESSION['user_email'];

$stmt = $conn->prepare('SELECT medicine_name, dosage, quantity, expiry_date, category FROM medicines WHERE user_email = ? ORDER BY expiry_date ASC');
$stmt->bind_param('s', $email);
$stmt->execute();
$medicines = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="my_medicines.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Medicine Name', 'Dosage', 'Quantity', 'Expiry Date', 'Category']);
foreach ($medicines as $row) {
    fputcsv($out, $row);
}
fclose($out);
exit;
?>