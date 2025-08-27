<?php
/**
 * Learning Management System - Authentication Handler
 * Handles QR code authentication, regular login, and session management
 */

require_once 'config.php';

// Handle CORS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Route requests based on action
switch ($action) {
    case 'generate_qr':
        handleGenerateQR();
        break;
    case 'verify_qr':
        handleVerifyQR();
        break;
    case 'login':
        handleRegularLogin();
        break;
    case 'logout':
        handleLogout();
        break;
    case 'check_session':
        handleCheckSession();
        break;
    case 'get_qr_status':
        handleGetQRStatus();
        break;
    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}

/**
 * Generate QR code token for authentication
 */
function handleGenerateQR() {
    try {
        $pdo = getDBConnection();
        
        // Generate unique token
        $token = generateSecureToken(32);
        $expiresAt = date('Y-m-d H:i:s', time() + QR_TOKEN_EXPIRY);
        $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        
        // Store token in database
        $stmt = $pdo->prepare("
            INSERT INTO qr_sessions (token, expires_at, ip_address, user_agent) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$token, $expiresAt, $ipAddress, $userAgent]);
        
        logActivity('qr_token_generated', ['token' => substr($token, 0, 8) . '...']);
        
        jsonResponse([
            'success' => true,
            'token' => $token,
            'expires_at' => $expiresAt,
            'expires_in' => QR_TOKEN_EXPIRY
        ]);
        
    } catch (Exception $e) {
        error_log("Error generating QR token: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to generate QR token'], 500);
    }
}

/**
 * Verify QR code token and authenticate user
 */
function handleVerifyQR() {
    $token = sanitizeInput($_POST['token'] ?? '');
    $username = sanitizeInput($_POST['username'] ?? '');
    
    if (empty($token) || empty($username)) {
        jsonResponse(['error' => 'Token and username are required'], 400);
    }
    
    try {
        $pdo = getDBConnection();
        
        // Check if token is valid and not expired
        $stmt = $pdo->prepare("
            SELECT * FROM qr_sessions 
            WHERE token = ? AND expires_at > NOW() AND is_used = FALSE
        ");
        $stmt->execute([$token]);
        $session = $stmt->fetch();
        
        if (!$session) {
            jsonResponse(['error' => 'Invalid or expired QR code'], 400);
        }
        
        // Check if user exists and is active
        $stmt = $pdo->prepare("
            SELECT id, username, email, role, full_name 
            FROM users 
            WHERE username = ? AND is_active = TRUE
        ");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if (!$user) {
            jsonResponse(['error' => 'User not found or inactive'], 400);
        }
        
        // Mark token as used and associate with user
        $stmt = $pdo->prepare("
            UPDATE qr_sessions 
            SET is_used = TRUE, user_id = ?, used_at = NOW() 
            WHERE token = ?
        ");
        $stmt->execute([$user['id'], $token]);
        
        // Create user session
        createUserSession($user);
        
        logActivity('qr_login_success', [
            'user_id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role']
        ]);
        
        jsonResponse([
            'success' => true,
            'message' => 'Authentication successful',
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'role' => $user['role'],
                'full_name' => $user['full_name']
            ],
            'redirect' => 'dashboard.html'
        ]);
        
    } catch (Exception $e) {
        error_log("Error verifying QR token: " . $e->getMessage());
        jsonResponse(['error' => 'Authentication failed'], 500);
    }
}

/**
 * Handle regular username/password login
 */
function handleRegularLogin() {
    $username = sanitizeInput($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        jsonResponse(['error' => 'Username and password are required'], 400);
    }
    
    try {
        $pdo = getDBConnection();
        
        // Get user by username or email
        $stmt = $pdo->prepare("
            SELECT id, username, email, password_hash, role, full_name 
            FROM users 
            WHERE (username = ? OR email = ?) AND is_active = TRUE
        ");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();
        
        if (!$user || !verifyPassword($password, $user['password_hash'])) {
            // Log failed login attempt
            logActivity('login_failed', ['username' => $username]);
            jsonResponse(['error' => 'Invalid username or password'], 401);
        }
        
        // Create user session
        createUserSession($user);
        
        logActivity('regular_login_success', [
            'user_id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role']
        ]);
        
        jsonResponse([
            'success' => true,
            'message' => 'Login successful',
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'role' => $user['role'],
                'full_name' => $user['full_name']
            ],
            'redirect' => 'dashboard.html'
        ]);
        
    } catch (Exception $e) {
        error_log("Error during regular login: " . $e->getMessage());
        jsonResponse(['error' => 'Login failed'], 500);
    }
}

/**
 * Handle user logout
 */
function handleLogout() {
    if (isLoggedIn()) {
        logActivity('logout', ['user_id' => $_SESSION['user_id']]);
    }
    
    // Destroy session
    session_destroy();
    
    jsonResponse([
        'success' => true,
        'message' => 'Logged out successfully',
        'redirect' => 'login.html'
    ]);
}

/**
 * Check current session status
 */
function handleCheckSession() {
    if (!isLoggedIn() || !isSessionValid()) {
        jsonResponse([
            'authenticated' => false,
            'message' => 'Not authenticated'
        ]);
    }
    
    $user = getCurrentUser();
    if (!$user) {
        jsonResponse([
            'authenticated' => false,
            'message' => 'User not found'
        ]);
    }
    
    // Get additional user stats
    $totalPoints = getUserTotalPoints($user['id']);
    $unreadMessages = getUnreadMessageCount($user['id']);
    
    jsonResponse([
        'authenticated' => true,
        'user' => $user,
        'stats' => [
            'total_points' => $totalPoints,
            'unread_messages' => $unreadMessages
        ]
    ]);
}

/**
 * Get QR token status (for polling)
 */
function handleGetQRStatus() {
    $token = sanitizeInput($_GET['token'] ?? '');
    
    if (empty($token)) {
        jsonResponse(['error' => 'Token is required'], 400);
    }
    
    try {
        $pdo = getDBConnection();
        
        $stmt = $pdo->prepare("
            SELECT qs.*, u.username, u.full_name, u.role 
            FROM qr_sessions qs
            LEFT JOIN users u ON qs.user_id = u.id
            WHERE qs.token = ?
        ");
        $stmt->execute([$token]);
        $session = $stmt->fetch();
        
        if (!$session) {
            jsonResponse(['error' => 'Token not found'], 404);
        }
        
        // Check if token has expired
        if (strtotime($session['expires_at']) < time()) {
            jsonResponse([
                'status' => 'expired',
                'message' => 'QR code has expired'
            ]);
        }
        
        // Check if token has been used
        if ($session['is_used']) {
            jsonResponse([
                'status' => 'used',
                'message' => 'QR code has been used',
                'user' => [
                    'username' => $session['username'],
                    'full_name' => $session['full_name'],
                    'role' => $session['role']
                ]
            ]);
        }
        
        // Token is still valid and unused
        $remainingTime = strtotime($session['expires_at']) - time();
        jsonResponse([
            'status' => 'waiting',
            'message' => 'Waiting for authentication',
            'expires_in' => $remainingTime
        ]);
        
    } catch (Exception $e) {
        error_log("Error checking QR status: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to check QR status'], 500);
    }
}

/**
 * Create user session
 * @param array $user User data
 */
function createUserSession($user) {
    // Regenerate session ID for security
    session_regenerate_id(true);
    
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['last_activity'] = time();
    $_SESSION['login_time'] = time();
    
    // Award welcome badge if this is user's first login
    awardWelcomeBadgeIfNeeded($user['id']);
}

/**
 * Award welcome badge to new users
 * @param int $userId User ID
 */
function awardWelcomeBadgeIfNeeded($userId) {
    try {
        $pdo = getDBConnection();
        
        // Check if user already has welcome badge
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM user_badges ub 
            JOIN badges b ON ub.badge_id = b.id 
            WHERE ub.user_id = ? AND b.category = 'welcome'
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        
        if ($result['count'] == 0) {
            // Get welcome badge ID
            $stmt = $pdo->prepare("SELECT id FROM badges WHERE category = 'welcome' LIMIT 1");
            $stmt->execute();
            $badge = $stmt->fetch();
            
            if ($badge) {
                // Award welcome badge
                $stmt = $pdo->prepare("
                    INSERT INTO user_badges (user_id, badge_id, notes) 
                    VALUES (?, ?, 'Automatically awarded on first login')
                ");
                $stmt->execute([$userId, $badge['id']]);
                
                logActivity('badge_awarded', [
                    'user_id' => $userId,
                    'badge_id' => $badge['id'],
                    'type' => 'welcome'
                ]);
            }
        }
    } catch (Exception $e) {
        error_log("Error awarding welcome badge: " . $e->getMessage());
    }
}
?>
