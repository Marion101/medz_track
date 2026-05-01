<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

session_start();
require_once 'db.php';
require_once 'auth.php';

// Check login
ensure_user_table($conn);
require_auth($conn);

if (!isset($_SESSION['user_email'])) {
    header('Location: login.php');
    exit;
}

$email = (string) $_SESSION['user_email'];

// Get current user name
$userStmt = $conn->prepare('SELECT name FROM users WHERE email = ? LIMIT 1');
$userStmt->bind_param('s', $email);
$userStmt->execute();
$user = $userStmt->get_result()->fetch_assoc() ?: [];
$userStmt->close();

$displayName = trim((string) ($user['name'] ?? ''));
if ($displayName === '') {
    $displayName = $email;
}

// Read calendar filters
$todayDate = (new DateTimeImmutable('today'))->format('Y-m-d');
$selectedMonth = trim((string) ($_GET['month'] ?? ''));
if ($selectedMonth !== '' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $selectedMonth)) {
    $selectedMonth = '';
}
$selectedDay = trim((string) ($_GET['day'] ?? ''));
if ($selectedDay !== '' && !preg_match('/^\d{4}-(0[1-9]|1[0-2])-[0-3]\d$/', $selectedDay)) {
    $selectedDay = '';
}
if ($selectedMonth !== '' && $selectedDay !== '' && strpos($selectedDay, $selectedMonth . '-') !== 0) {
    $selectedDay = '';
}

// Get months that have expiry dates
$availableMonths = $conn->query(
    "SELECT DISTINCT DATE_FORMAT(expiry_date, '%Y-%m') AS month_key
     FROM medicines
     WHERE expiry_date IS NOT NULL
     ORDER BY month_key DESC"
)->fetch_all(MYSQLI_ASSOC);

// Get days for the selected month
$availableDaysSql =
    "SELECT DISTINCT DATE(expiry_date) AS day_key
     FROM medicines
     WHERE expiry_date IS NOT NULL";
$dayTypes = '';
$dayValues = [];
if ($selectedMonth !== '') {
    $availableDaysSql .= " AND DATE_FORMAT(expiry_date, '%Y-%m') = ?";
    $dayTypes = 's';
    $dayValues[] = $selectedMonth;
}
$availableDaysSql .= " ORDER BY day_key DESC";
$availableDaysStmt = $conn->prepare($availableDaysSql);
bind_stmt_params($availableDaysStmt, $dayTypes, $dayValues);
$availableDaysStmt->execute();
$availableDays = $availableDaysStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$availableDaysStmt->close();

// Labels shown on the page
$monthLabel = 'All months';
if ($selectedMonth !== '') {
    $monthDate = DateTimeImmutable::createFromFormat('Y-m', $selectedMonth);
    if ($monthDate instanceof DateTimeImmutable) {
        $monthLabel = $monthDate->format('F Y');
    }
}
$dayLabel = 'All days';
if ($selectedDay !== '') {
    $dayDate = DateTimeImmutable::createFromFormat('Y-m-d', $selectedDay);
    if ($dayDate instanceof DateTimeImmutable) {
        $dayLabel = $dayDate->format('d M Y');
    }
}

// Build the calendar query
$whereParts = ['m.expiry_date IS NOT NULL'];
$bindTypes = '';
$bindValues = [];
if ($selectedMonth !== '') {
    $whereParts[] = "DATE_FORMAT(m.expiry_date, '%Y-%m') = ?";
    $bindTypes .= 's';
    $bindValues[] = $selectedMonth;
}
if ($selectedDay !== '') {
    $whereParts[] = "DATE(m.expiry_date) = ?";
    $bindTypes .= 's';
    $bindValues[] = $selectedDay;
}
$whereClause = ' WHERE ' . implode(' AND ', $whereParts);

$calendarSql =
    "SELECT
        m.medicine_name,
        m.expiry_date,
        m.quantity,
        m.category,
        m.user_email,
        u.name AS owner_name
     FROM medicines m
     LEFT JOIN users u ON u.email = m.user_email" .
     $whereClause .
    " ORDER BY m.expiry_date ASC, m.id DESC
      LIMIT 300";
$calendarStmt = $conn->prepare($calendarSql);
bind_stmt_params($calendarStmt, $bindTypes, $bindValues);
$calendarStmt->execute();
$calendarRows = $calendarStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$calendarStmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Calendar-medztrack</title>
    <!-- Page styles -->
    <link rel="stylesheet" href="Dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="<?= htmlspecialchars(theme_body_class()) ?>">
    <div class="dashboard-container">
        <!-- Sidebar menu -->
        <aside class="sidebar">
            <div class="sidebar-header">
                <h1><i class="fas fa-pills"></i> Medz track</h1>
            </div>
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-item"><i class="fas fa-home"></i> Dashboard</a>
                <a href="add_medicine.php" class="nav-item"><i class="fas fa-plus-circle"></i> Add Medicine</a>
                <a href="my_medicines.php" class="nav-item"><i class="fas fa-list"></i> My Medicines</a>
                <a href="alerts.php" class="nav-item"><i class="fas fa-bell"></i> Alerts</a>
                <a href="user_calendar.php" class="nav-item active"><i class="fas fa-calendar-days"></i> Calendar</a>
                <a href="profile.php" class="nav-item"><i class="fas fa-user"></i> Profile</a>
            </nav>
            <form action="logout.php" method="post">
                <button class="logout-btn" type="submit"><i class="fas fa-sign-out-alt"></i> Logout</button>
            </form>
        </aside>

        <main class="main-content">
            <!-- Page header -->
            <header class="top-header">
                <h2>Expiry Calendar</h2>
            </header>

            <!-- Current filter summary -->
            <div class="dashboard-note">
                <i class="fas fa-circle-info"></i>
                <span>
                    Signed in as <strong><?= htmlspecialchars($displayName) ?></strong>.
                    <br>
                    Showing data for <strong><?= htmlspecialchars($monthLabel) ?></strong> | <strong><?= htmlspecialchars($dayLabel) ?></strong>.
                </span>
            </div>

            <!-- Calendar filters and table -->
            <section class="medicines-section" style="margin-top: 10px;">
                <h3>Filter Calendar</h3>
                <form method="get" action="user_calendar.php" class="dev-inline-form" style="margin-bottom: 16px; flex-wrap: wrap; gap: 8px;">
                    <select name="month" class="dev-select" onchange="this.form.submit()">
                        <option value="">All months</option>
                        <?php foreach ($availableMonths as $monthRow): ?>
                            <?php
                            $monthKey = (string) ($monthRow['month_key'] ?? '');
                            if ($monthKey === '') {
                                continue;
                            }
                            $monthDate = DateTimeImmutable::createFromFormat('Y-m', $monthKey);
                            $monthText = $monthDate instanceof DateTimeImmutable ? $monthDate->format('F Y') : $monthKey;
                            ?>
                            <option value="<?= htmlspecialchars($monthKey) ?>" <?= $selectedMonth === $monthKey ? 'selected' : '' ?>>
                                <?= htmlspecialchars($monthText) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select name="day" class="dev-select" onchange="this.form.submit()">
                        <option value="">All days</option>
                        <?php foreach ($availableDays as $dayRow): ?>
                            <?php
                            $dayKey = (string) ($dayRow['day_key'] ?? '');
                            if ($dayKey === '') {
                                continue;
                            }
                            $dayDate = DateTimeImmutable::createFromFormat('Y-m-d', $dayKey);
                            $dayText = $dayDate instanceof DateTimeImmutable ? $dayDate->format('d M Y') : $dayKey;
                            ?>
                            <option value="<?= htmlspecialchars($dayKey) ?>" <?= $selectedDay === $dayKey ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dayText) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($selectedMonth !== '' || $selectedDay !== ''): ?>
                        <a href="user_calendar.php" class="dev-btn" style="text-decoration:none;">Clear</a>
                    <?php endif; ?>
                </form>

                <div class="dev-table-wrap">
                    <table class="dev-table">
                        <thead>
                            <tr>
                                <th>Expiry Date</th>
                                <th>Medicine</th>
                                <th>For User</th>
                                <th>Category</th>
                                <th>Qty</th>
                                <th>Days Left</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ($calendarRows === []): ?>
                                <tr><td colspan="7" class="empty-cell">No medicine expiry records found.</td></tr>
                            <?php else: ?>
                                <?php foreach ($calendarRows as $row): ?>
                                    <?php
                                    $expiryDate = (string) ($row['expiry_date'] ?? '');
                                    $ownerLabel = (string) (($row['owner_name'] ?? '') !== '' ? $row['owner_name'] : ($row['user_email'] ?? ''));
                                    $status = 'Good';
                                    $daysLeftLabel = '-';
                                    if ($expiryDate !== '') {
                                        $todayObj = new DateTimeImmutable($todayDate);
                                        $expiryObj = new DateTimeImmutable($expiryDate);
                                        $daysLeft = (int) $todayObj->diff($expiryObj)->format('%r%a');
                                        $daysLeftLabel = (string) $daysLeft;
                                        if ($daysLeft < 0) {
                                            $status = 'Expired';
                                        } elseif ($daysLeft <= 30) {
                                            $status = 'Expiring Soon';
                                        }
                                    }
                                    ?>
                                    <tr>
                                        <td><?= htmlspecialchars($expiryDate) ?></td>
                                        <td><?= htmlspecialchars((string) ($row['medicine_name'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars($ownerLabel) ?></td>
                                        <td><?= htmlspecialchars((string) ($row['category'] ?? 'Other')) ?></td>
                                        <td><?= (int) ($row['quantity'] ?? 0) ?></td>
                                        <td><?= htmlspecialchars($daysLeftLabel) ?></td>
                                        <td><span class="dev-pill"><?= htmlspecialchars($status) ?></span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
</body>
</html>
