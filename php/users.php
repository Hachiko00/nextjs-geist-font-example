<?php
/**
 * Learning Management System - User Management API
 * Handles user operations: listing, profile management, and user interactions
 */

require_once 'config.php';

// Handle CORS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Require authentication for all user operations
requireAuth();

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Route requests based on method and action
switch ($method) {
    case 'GET':
        switch ($action) {
            case 'profile':
                handleGetProfile();
                break;
            case 'list':
                handleGetUserList();
                break;
            case 'search':
                handleSearchUsers();
                break;
            default:
                jsonResponse(['error' => 'Invalid action for GET method'], 400);
        }
        break;
    case 'POST':
        switch ($action) {
            case 'update_profile':
                handleUpdateProfile();
                break;
            case 'change_password':
                handleChangePassword();
                break;
            default:
                jsonResponse(['error' => 'Invalid action for POST method'], 400);
        }
        break;
    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}

/**
 * Get current user's profile
 */
function handleGetProfile() {
    $userId = $_GET['user_id'] ?? $_SESSION['user_id'];
    $currentUser = getCurrentUser();
    
    // Users can only view their own profile unless they're a teacher
    if ($currentUser['role'] !== 'teacher' && $userId != $_SESSION['user_id']) {
        jsonResponse(['error' => 'Permission denied'], 403);
    }
    
    try {
        $pdo = getDBConnection();
        
        // Get user profile
        $stmt = $pdo->prepare("
            SELECT id, username, email, role, full_name, created_at, updated_at
            FROM users 
            WHERE id = ? AND is_active = TRUE
        ");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            jsonResponse(['error' => 'User not found'], 404);
        }
        
        // Get user statistics
        $totalPoints = getUserTotalPoints($userId);
        $unreadMessages = getUnreadMessageCount($userId);
        
        jsonResponse([
            'success' => true,
            'user' => $user,
            'stats' => [
                'total_points' => $totalPoints,
                'unread_messages' => $unreadMessages
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Error getting user profile: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to retrieve user profile'], 500);
    }
}

/**
 * Get list of users (filtered by role if specified)
 */
function handleGetUserList() {
    $role = $_GET['role'] ?? null;
    $limit = min((int)($_GET['limit'] ?? 50), 100);
    
    try {
        $pdo = getDBConnection();
        
        $whereClause = "WHERE u.is_active = TRUE";
        $params = [];
        
        if ($role && in_array($role, ['teacher', 'student', 'guardian'])) {
            $whereClause .= " AND u.role = ?";
            $params[] = $role;
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                u.id, u.username, u.full_name, u.role, u.email, u.created_at
            FROM users u
            $whereClause
            ORDER BY u.full_name ASC
            LIMIT ?
        ");
        
        $params[] = $limit;
        $stmt->execute($params);
        $users = $stmt->fetchAll();
        
        jsonResponse([
            'success' => true,
            'users' => $users,
            'total' => count($users)
        ]);
        
    } catch (Exception $e) {
        error_log("Error getting user list: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to retrieve user list'], 500);
    }
}

/**
 * Search users by name or username
 */
function handleSearchUsers() {
    $query = sanitizeInput($_GET['q'] ?? '');
    $limit = min((int)($_GET['limit'] ?? 20), 50);
    
    if (strlen($query) < 2) {
        jsonResponse(['error' => 'Search query must be at least 2 characters'], 400);
    }
    
    try {
        $pdo = getDBConnection();
        
        $stmt = $pdo->prepare("
            SELECT 
                u.id, u.username, u.full_name, u.role, u.email
            FROM users u
            WHERE u.is_active = TRUE AND (u.username LIKE ? OR u.full_name LIKE ?)
            ORDER BY u.full_name ASC
            LIMIT ?
        ");
        
        $searchTerm = "%$query%";
        $stmt->execute([$searchTerm, $searchTerm, $limit]);
        $users = $stmt->fetchAll();
        
        jsonResponse([
            'success' => true,
            'users' => $users,
            'query' => $query,
            'total' => count($users)
        ]);
        
    } catch (Exception $e) {
        error_log("Error searching users: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to search users'], 500);
    }
}

/**
 * Update user profile
 */
function handleUpdateProfile() {
    $userId = $_POST['user_id'] ?? $_SESSION['user_id'];
    $currentUser = getCurrentUser();
    
    // Users can only update their own profile unless they're a teacher
    if ($currentUser['role'] !== 'teacher' && $userId != $_SESSION['user_id']) {
        jsonResponse(['error' => 'Permission denied'], 403);
    }
    
    $fullName = sanitizeInput($_POST['full_name'] ?? '');
    $email = sanitizeInput($_POST['email'] ?? '');
    
    if (empty($fullName) || empty($email)) {
        jsonResponse(['error' => 'Full name and email are required'], 400);
    }
    
    if (!isValidEmail($email)) {
        jsonResponse(['error' => 'Invalid email format'], 400);
    }
    
    try {
        $pdo = getDBConnection();
        
        // Check if email is already taken by another user
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $userId]);
        
        if ($stmt->fetch()) {
            jsonResponse(['error' => 'Email is already taken'], 400);
        }
        
        // Update user profile
        $stmt = $pdo->prepare("
            UPDATE users 
            SET full_name = ?, email = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$fullName, $email, $userId]);
        
        logActivity('profile_updated', [
            'user_id' => $userId,
            'updated_by' => $_SESSION['user_id']
        ]);
        
        jsonResponse([
            'success' => true,
            'message' => 'Profile updated successfully'
        ]);
        
    } catch (Exception $e) {
        error_log("Error updating profile: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to update profile'], 500);
    }
}

/**
 * Change user password
 */
function handleChangePassword() {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        jsonResponse(['error' => 'All password fields are required'], 400);
    }
    
    if ($newPassword !== $confirmPassword) {
        jsonResponse(['error' => 'New passwords do not match'], 400);
    }
    
    if (strlen($newPassword) < 8) {
        jsonResponse(['error' => 'New password must be at least 8 characters long'], 400);
    }
    
    try {
        $pdo = getDBConnection();
        
        // Get current user's password hash
        $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        
        if (!$user || !verifyPassword($currentPassword, $user['password_hash'])) {
            jsonResponse(['error' => 'Current password is incorrect'], 400);
        }
        
        // Update password
        $newPasswordHash = hashPassword($newPassword);
        $stmt = $pdo->prepare("
            UPDATE users 
            SET password_hash = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$newPasswordHash, $_SESSION['user_id']]);
        
        logActivity('password_changed', ['user_id' => $_SESSION['user_id']]);
        
        jsonResponse([
            'success' => true,
            'message' => 'Password changed successfully'
        ]);
        
    } catch (Exception $e) {
        error_log("Error changing password: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to change password'], 500);
    }
}
?>
