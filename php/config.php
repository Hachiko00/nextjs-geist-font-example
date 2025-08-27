<?php
/**
 * Learning Management System - Configuration File
 * Database connection and common functions
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'lms_system');

// Application configuration
define('UPLOAD_DIR', '../uploads/voice/');
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB
define('QR_TOKEN_EXPIRY', 5 * 60); // 5 minutes in seconds
define('SESSION_TIMEOUT', 30 * 60); // 30 minutes in seconds

// Allowed file types for voice uploads
define('ALLOWED_AUDIO_TYPES', ['audio/webm', 'audio/wav', 'audio/mp3', 'audio/ogg']);

/**
 * Create database connection using PDO
 * @return PDO Database connection object
 * @throws Exception if connection fails
 */
function getDBConnection() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
            ];
            
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch(PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            throw new Exception("Database connection failed");
        }
    }
    
    return $pdo;
}

/**
 * Send JSON response and exit
 * @param mixed $data Data to send
 * @param int $status HTTP status code
 */
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Check if user is logged in
 * @return bool True if logged in, false otherwise
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['username']);
}

/**
 * Check if session is still valid (not expired)
 * @return bool True if valid, false if expired
 */
function isSessionValid() {
    if (!isset($_SESSION['last_activity'])) {
        return false;
    }
    
    if (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT) {
        session_destroy();
        return false;
    }
    
    $_SESSION['last_activity'] = time();
    return true;
}

/**
 * Get current user information
 * @return array|null User data or null if not logged in
 */
function getCurrentUser() {
    if (!isLoggedIn() || !isSessionValid()) {
        return null;
    }
    
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT id, username, email, role, full_name, created_at FROM users WHERE id = ? AND is_active = TRUE");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch();
    } catch (Exception $e) {
        error_log("Error getting current user: " . $e->getMessage());
        return null;
    }
}

/**
 * Require authentication - redirect if not logged in
 */
function requireAuth() {
    if (!isLoggedIn() || !isSessionValid()) {
        if (isAjaxRequest()) {
            jsonResponse(['error' => 'Authentication required'], 401);
        } else {
            header('Location: login.html');
            exit;
        }
    }
}

/**
 * Require specific role
 * @param string|array $allowedRoles Single role or array of allowed roles
 */
function requireRole($allowedRoles) {
    requireAuth();
    
    $user = getCurrentUser();
    if (!$user) {
        jsonResponse(['error' => 'User not found'], 404);
    }
    
    $allowedRoles = is_array($allowedRoles) ? $allowedRoles : [$allowedRoles];
    
    if (!in_array($user['role'], $allowedRoles)) {
        jsonResponse(['error' => 'Insufficient permissions'], 403);
    }
}

/**
 * Check if request is AJAX
 * @return bool True if AJAX request
 */
function isAjaxRequest() {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * Sanitize input data
 * @param string $data Input data
 * @return string Sanitized data
 */
function sanitizeInput($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

/**
 * Validate email format
 * @param string $email Email to validate
 * @return bool True if valid email
 */
function isValidEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Generate secure random token
 * @param int $length Token length
 * @return string Random token
 */
function generateSecureToken($length = 32) {
    return bin2hex(random_bytes($length));
}

/**
 * Hash password securely
 * @param string $password Plain text password
 * @return string Hashed password
 */
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * Verify password against hash
 * @param string $password Plain text password
 * @param string $hash Stored password hash
 * @return bool True if password matches
 */
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Log activity for debugging/auditing
 * @param string $action Action performed
 * @param array $data Additional data
 */
function logActivity($action, $data = []) {
    $logData = [
        'timestamp' => date('Y-m-d H:i:s'),
        'user_id' => $_SESSION['user_id'] ?? null,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'action' => $action,
        'data' => $data
    ];
    
    error_log("LMS Activity: " . json_encode($logData));
}

/**
 * Create upload directory if it doesn't exist
 * @param string $dir Directory path
 * @return bool True if directory exists or was created
 */
function ensureUploadDir($dir) {
    if (!file_exists($dir)) {
        return mkdir($dir, 0755, true);
    }
    return is_dir($dir) && is_writable($dir);
}

/**
 * Get file extension from filename
 * @param string $filename Filename
 * @return string File extension
 */
function getFileExtension($filename) {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

/**
 * Format file size in human readable format
 * @param int $bytes File size in bytes
 * @return string Formatted file size
 */
function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    
    $bytes /= pow(1024, $pow);
    
    return round($bytes, 2) . ' ' . $units[$pow];
}

/**
 * Clean up expired QR sessions
 */
function cleanupExpiredSessions() {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("DELETE FROM qr_sessions WHERE expires_at < NOW()");
        $stmt->execute();
    } catch (Exception $e) {
        error_log("Error cleaning up expired sessions: " . $e->getMessage());
    }
}

/**
 * Get user's total badge points
 * @param int $userId User ID
 * @return int Total points
 */
function getUserTotalPoints($userId) {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(b.points), 0) as total_points 
            FROM user_badges ub 
            JOIN badges b ON ub.badge_id = b.id 
            WHERE ub.user_id = ?
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        return (int)$result['total_points'];
    } catch (Exception $e) {
        error_log("Error getting user total points: " . $e->getMessage());
        return 0;
    }
}

/**
 * Get unread message count for user
 * @param int $userId User ID
 * @return int Unread message count
 */
function getUnreadMessageCount($userId) {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM voice_feedback WHERE receiver_id = ? AND is_read = FALSE");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        return (int)$result['count'];
    } catch (Exception $e) {
        error_log("Error getting unread message count: " . $e->getMessage());
        return 0;
    }
}

// Initialize upload directory
ensureUploadDir(UPLOAD_DIR);

// Clean up expired sessions periodically (1% chance per request)
if (rand(1, 100) === 1) {
    cleanupExpiredSessions();
}

// Set timezone
date_default_timezone_set('UTC');

// Error reporting for development (disable in production)
if (defined('DEVELOPMENT') && DEVELOPMENT) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}
?>
