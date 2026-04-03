<?php
declare(strict_types=1);

$isLocal = isset($_SERVER['HTTP_HOST']) && in_array($_SERVER['HTTP_HOST'], ['localhost', '127.0.0.1', 'localhost:80', '127.0.0.1:80'], true);

if ($isLocal) {
    $servername = 'localhost';
    $username = 'root';
    $password = '';
    $dbname = 'login_system';
} else {
    $servername = 'sql308.infinityfree.com';
    $username = 'if0_41465460';
    $password = 'magiafamily05';
    $dbname = 'if0_41465460_medztrack';
}

$conn = new mysqli($servername, $username, $password, $dbname);

if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}

$conn->set_charset('utf8mb4');
