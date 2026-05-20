<?php
// =============================================
// RMU E-Learning - Core Configuration
// =============================================

// Database settings - edit these for your WAMP
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'rmu_elearn');

// Base URL - change if your folder name is different
define('BASE_URL', 'http://localhost/rmu3');

// ---- RMU Email Rules ----
// Students:    george.boateng@st.rmu.edu.gh
// Instructors: firstname.lastname@rmu.edu.gh  (NOT @st.rmu.edu.gh)
// Admin:       admin@rmu.edu.gh  (special account, role set in DB)
define('STUDENT_EMAIL_DOMAIN',    '@st.rmu.edu.gh');
define('INSTRUCTOR_EMAIL_DOMAIN', '@rmu.edu.gh');

// Upload settings
define('UPLOAD_DIR', dirname(__DIR__) . '/uploads/');
define('MAX_VIDEO_MB', 20480);  // 20 GB
define('MAX_SLIDE_MB', 20480);  // 20 GB

// Start session once (skip in CLI mode)
if (php_sapi_name() !== 'cli' && session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database connection
try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die('<h2 style="font-family:sans-serif;color:red;padding:40px">Database Error: ' . htmlspecialchars($e->getMessage()) . '<br><br>Please check your database settings in includes/config.php and make sure you have run database.sql in phpMyAdmin.</h2>');
}

// ---- Helper functions ----

function isLoggedIn() {
    return !empty($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/index.php');
        exit;
    }
}

function requireRole(string $role) {
    requireLogin();
    // Admin can access any page OR enforce specific role
    if ($_SESSION['role'] === 'admin' && $role === 'admin') {
        return; // admin accessing admin pages
    }
    if ($_SESSION['role'] !== $role && $_SESSION['role'] !== 'admin') {
        header('Location: ' . BASE_URL . '/index.php?err=access');
        exit;
    }
}

function h(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

/**
 * Validate an RMU email address.
 * Returns 'student', 'instructor', 'admin', or false.
 *
 * Student:    george.boateng@st.rmu.edu.gh
 * Instructor: firstname.lastname@rmu.edu.gh  (NOT @st.rmu.edu.gh)
 * Admin:      role stored in DB overrides email detection
 */
function validateRmuEmail(string $email) {
    $email = strtolower(trim($email));
    // IMPORTANT: Check student FIRST — @st.rmu.edu.gh also ends with @rmu.edu.gh
    if (str_ends_with($email, STUDENT_EMAIL_DOMAIN)) {
        return 'student';
    }
    if (str_ends_with($email, INSTRUCTOR_EMAIL_DOMAIN)) {
        return 'instructor'; // admin also uses @rmu.edu.gh — role resolved from DB
    }
    return false;
}

/**
 * Check email matches expected role.
 */
function emailMatchesRole(string $email, string $role): bool {
    $detected = validateRmuEmail($email);
    return $detected === $role;
}

function formatSize(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 1) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 1)    . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 0)       . ' KB';
    return $bytes . ' B';
}
