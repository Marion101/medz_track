<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);

session_start();
require_once 'db.php';
require_once 'auth.php';

ensure_user_table($conn);
require_auth($conn);

if (!isset($_SESSION['user_email'])) {
    header('Location: login.php');
    exit;
}

$conn->query("ALTER TABLE users ADD COLUMN IF NOT EXISTS name VARCHAR(100) DEFAULT NULL AFTER id");
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
$stmt = $conn->prepare('SELECT name, created_at FROM users WHERE email = ?');
$stmt->bind_param('s', $email);
$stmt->execute();
$result = $stmt->get_result();
$user = $result->fetch_assoc() ?: [];
$stmt->close();

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

$memberSince = $user['created_at'] ?? date('Y-m-d H:i:s');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - Medicine Expiry Tracker</title>
    <link rel="stylesheet" href="Dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="dashboard-container">
        <aside class="sidebar">
            <div class="sidebar-header">
                <h1><i class="fas fa-pills"></i> Medz track</h1>
            </div>
            <nav class="sidebar-nav">
                <a href="dashboard.php" class="nav-item"><i class="fas fa-home"></i> Dashboard</a>
                <a href="add_medicine.php" class="nav-item"><i class="fas fa-plus-circle"></i> Add Medicine</a>
                <a href="my_medicines.php" class="nav-item"><i class="fas fa-list"></i> My Medicines</a>
                <a href="alerts.php" class="nav-item"><i class="fas fa-bell"></i> Alerts</a>
                <a href="profile.php" class="nav-item active"><i class="fas fa-user"></i> Profile</a>
            </nav>
            <form action="logout.php" method="post">
                <button type="submit" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</button>
            </form>
        </aside>

        <main class="main-content">
            <header class="top-header">
                <a href="dashboard.php" class="back-btn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M15 18L9 12L15 6" stroke="#DE8389" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </a>
                <h2>My Profile</h2>
            </header>

            <section class="profile-section">
                <div class="profile-card">
                    <div class="profile-picture-container">
                        <img id="profile-picture" src="https://via.placeholder.com/150?text=User" alt="Profile Picture" class="profile-picture">
                        <label for="picture-upload" class="picture-upload-btn">
                            <i class="fas fa-camera"></i>
                        </label>
                        <input type="file" id="picture-upload" accept="image/*" style="display: none;">
                    </div>

                    <div class="profile-info">
                        <h3 id="profile-username"><?= htmlspecialchars($displayName) ?></h3>
                        <p class="profile-label"><?= htmlspecialchars($email) ?></p>
                        <p class="profile-label">Member since <span id="member-date"><?= htmlspecialchars(date('F Y', strtotime($memberSince))) ?></span></p>
                        <div class="profile-stats">
                            <div class="profile-stat">
                                <i class="fas fa-capsules"></i>
                                <div>
                                    <p class="stat-value" id="total-meds"><?= (int) ($medData['total_meds'] ?? 0) ?></p>
                                    <p class="stat-label">Total Medicines</p>
                                </div>
                            </div>
                            <div class="profile-stat">
                                <i class="fas fa-check-circle"></i>
                                <div>
                                    <p class="stat-value" id="doses-used">0</p>
                                    <p class="stat-label">Doses Used</p>
                                </div>
                            </div>
                            <div class="profile-stat">
                                <i class="fas fa-calendar"></i>
                                <div>
                                    <p class="stat-value" id="days-tracking">0</p>
                                    <p class="stat-label">Days Tracking</p>
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

                <div class="settings-card">
                    <h3>Account Settings</h3>
                    
                    <div class="setting-item">
                        <div>
                            <h4>Notifications</h4>
                            <p>Get alerts for expiring medicines and low stock</p>
                        </div>
                        <label class="toggle">
                            <input type="checkbox" checked disabled>
                            <span class="slider"></span>
                        </label>
                    </div>

                    <div class="setting-item">
                        <div>
                            <h4>Dark Mode</h4>
                            <p>Dark theme is not available in this version</p>
                        </div>
                        <span class="setting-note">Coming Soon</span>
                    </div>

                    <div class="setting-item">
                        <div>
                            <h4>Auto Backup</h4>
                            <p>Automatically backup your data</p>
                        </div>
                        <label class="toggle">
                            <input type="checkbox" checked disabled>
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>

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

