<?php
declare(strict_types=1);

$httpHost = (string) ($_SERVER['HTTP_HOST'] ?? '');
$serverName = (string) ($_SERVER['SERVER_NAME'] ?? '');
$remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$hostOnly = strtolower(trim((string) preg_replace('/:\d+$/', '', $httpHost)));
$knownLocalHosts = ['localhost', '127.0.0.1', '::1'];

$isCli = PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg';
$isLocalHost = in_array($hostOnly, $knownLocalHosts, true) || in_array(strtolower($serverName), $knownLocalHosts, true);
$isLocalRemote = in_array($remoteAddress, ['127.0.0.1', '::1'], true);
$isLocal = $isCli || $isLocalHost || $isLocalRemote;

if ($isLocal) {
    $servername = 'localhost';
    $username = 'root';
    $password = '';
    $dbname = 'medztrack';
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
