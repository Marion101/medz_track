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

// Format the join date
function format_member_since_label(string $createdAt): string
{
    $createdAt = trim($createdAt);
    if ($createdAt === '' || strpos($createdAt, '0000-00-00') === 0) {
        return 'Unknown';
    }

    $timestamp = strtotime($createdAt);
    if ($timestamp === false) {
        return 'Unknown';
    }

    $year = (int) date('Y', $timestamp);
    if ($year < 1970) {
        return 'Unknown';
    }

    return date('F Y', $timestamp);
}

// Schema safety
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS name VARCHAR(100) DEFAULT NULL AFTER id");
$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP AFTER role");
$conn->query(
    "CREATE TABLE IF NOT EXISTS medicines (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_email VARCHAR(255) NOT NULL,
        medicine_name VARCHAR(255) NOT NULL,
        dosage VARCHAR(100) DEFAULT '',
        quantity INT NOT NULL,
        expiry_date DATE NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )"
);

$email = $_SESSION['user_email'];
$message = null;
$messageType = 'success';

// Fix missing join dates
$backfillJoinDate = $conn->prepare(
    "UPDATE users u
     LEFT JOIN (
        SELECT user_email, MIN(created_at) AS first_medicine_at
        FROM medicines
        GROUP BY user_email
     ) m ON m.user_email = u.email
     SET u.created_at = COALESCE(m.first_medicine_at, NOW())
     WHERE u.email = ?
       AND (u.created_at IS NULL OR u.created_at = '0000-00-00 00:00:00' OR u.created_at < '1971-01-01 00:00:00')"
);
$backfillJoinDate->bind_param('s', $email);
$backfillJoinDate->execute();
$backfillJoinDate->close();

// Save theme setting
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'update_theme') {
    $theme = isset($_POST['dark_mode']) ? 'dark' : 'light';
    $theme = normalize_theme_preference($theme);
    $themeUpdate = $conn->prepare('UPDATE users SET theme_preference = ? WHERE email = ?');
    $themeUpdate->bind_param('ss', $theme, $email);

    if ($themeUpdate->execute()) {
        $_SESSION['theme'] = $theme;
        $themeUpdate->close();
        header('Location: profile.php?theme_updated=1');
        exit;
    }

    $message = 'Could not update theme right now.';
    $messageType = 'error';
    $themeUpdate->close();
}

// Get profile data
$stmt = $conn->prepare('SELECT name, created_at, theme_preference FROM users WHERE email = ?');
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc() ?: [];
$stmt->close();

$_SESSION['theme'] = normalize_theme_preference((string) ($user['theme_preference'] ?? ($_SESSION['theme'] ?? 'light')));

$medStmt = $conn->prepare('SELECT COUNT(*) AS total_meds FROM medicines WHERE user_email = ?');
$medStmt->bind_param('s', $email);
$medStmt->execute();
$medResult = $medStmt->get_result();
$medData = $medResult->fetch_assoc();
$medStmt->close();

$displayName = trim((string) ($user['name'] ?? ''));
if ($displayName === '') {
    $displayName = $email;
}

$memberSinceLabel = format_member_since_label((string) ($user['created_at'] ?? ''));

if (isset($_GET['theme_updated'])) {
    $message = 'Theme updated.';
    $messageType = 'success';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile-medztrack</title>
    <!-- CSS for this page is in Dashboard.css -->
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
                <a href="user_calendar.php" class="nav-item"><i class="fas fa-calendar-days"></i> Calendar</a>
                <a href="profile.php" class="nav-item active"><i class="fas fa-user"></i> Profile</a>
            </nav>
            <form action="logout.php" method="post">
                <button type="submit" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</button>
            </form>
        </aside>

        <main class="main-content">
            <!-- Page header -->
            <header class="top-header">
                <a href="dashboard.php" class="back-btn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M15 18L9 12L15 6" stroke="#DE8389" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
                <h2>My Profile</h2>
            </header>

            <!-- Success or error message -->
            <?php if ($message !== null): ?>
                <div class="message <?= htmlspecialchars($messageType) ?>"><?= htmlspecialchars($message) ?></div>
            <?php endif; ?>

            <!-- Profile details -->
            <section class="profile-section">
                <div class="profile-card">
                    <div class="profile-info">
                        <h3 id="profile-username"><?= htmlspecialchars($displayName) ?></h3>
                        <p class="profile-label"><?= htmlspecialchars($email) ?></p>
                        <p class="profile-label">Member since <span id="member-date"><?= htmlspecialchars($memberSinceLabel) ?></span></p>
                        <div class="profile-stats">
                            <div class="profile-stat">
                                <i class="fas fa-capsules"></i>
                                <div>
                                    <p class="stat-value" id="total-meds"><?= (int) ($medData['total_meds'] ?? 0) ?></p>
                                    <p class="stat-label">Total Medicines</p>
                                </div>
                            </div>
                        </div>

                        <div class="profile-actions">
                            <a href="edit_profile.php" class="btn-edit" id="edit-username-btn">
                                <i class="fas fa-edit"></i> Edit Username
                            </a>
                            <form method="post" action="logout.php" class="inline-form wide-form">
                                <button type="submit" class="btn-delete" id="reset-data-btn">
                                    <i class="fas fa-sign-out-alt"></i> Logout
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Account settings -->
                <div class="settings-card">
                    <h3>Account Settings</h3>

                    <div class="setting-item">
                        <div>
                            <h4>Dark Mode</h4>
                            <p>Switch between light and dark theme</p>
                        </div>
                        <form method="post" action="" class="inline-form">
                            <input type="hidden" name="action" value="update_theme">
                            <label class="toggle" title="Toggle dark mode">
                                <input type="checkbox" name="dark_mode" value="1" <?= current_theme_preference() === 'dark' ? 'checked' : '' ?> onchange="this.form.submit()">
                                <span class="slider"></span>
                            </label>
                        </form>
                    </div>
                </div>

                <!-- About box -->
                <div class="about-card">
                    <h3>About Medz track</h3>
                    <p><strong>Version:</strong> 1.0</p>
                    <p>Track medicine expiry dates and manage your medication.</p>
                </div>
            </section>
        </main>
    </div>
</body>
</html>

