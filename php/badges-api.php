<?php
/**
 * Learning Management System - Badges API
 * Handles badge operations: viewing, awarding, and managing badges
 */

require_once 'config.php';

// Handle CORS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Require authentication for all badge operations
requireAuth();

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Route requests based on method and action
switch ($method) {
    case 'GET':
        switch ($action) {
            case 'user_badges':
                handleGetUserBadges();
                break;
            case 'available_badges':
                handleGetAvailableBadges();
                break;
            case 'leaderboard':
                handleGetLeaderboard();
                break;
            case 'badge_details':
                handleGetBadgeDetails();
                break;
            default:
                handleGetAllBadges();
        }
        break;
    case 'POST':
        switch ($action) {
            case 'award':
                handleAwardBadge();
                break;
            case 'create':
                handleCreateBadge();
                break;
            default:
                jsonResponse(['error' => 'Invalid action for POST method'], 400);
        }
        break;
    case 'DELETE':
        if ($action === 'revoke') {
            handleRevokeBadge();
        } else {
            jsonResponse(['error' => 'Invalid action for DELETE method'], 400);
        }
        break;
    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}

/**
 * Get all available badges in the system
 */
function handleGetAllBadges() {
    try {
        $pdo = getDBConnection();
        
        $stmt = $pdo->prepare("
            SELECT id, name, description, icon_class, points, category, created_at
            FROM badges 
            WHERE is_active = TRUE 
            ORDER BY category, points DESC
        ");
        $stmt->execute();
        $badges = $stmt->fetchAll();
        
        jsonResponse([
            'success' => true,
            'badges' => $badges,
            'total' => count($badges)
        ]);
        
    } catch (Exception $e) {
        error_log("Error getting all badges: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to retrieve badges'], 500);
    }
}

/**
 * Get badges earned by a specific user
 */
function handleGetUserBadges() {
    $userId = $_GET['user_id'] ?? $_SESSION['user_id'];
    $currentUser = getCurrentUser();
    
    // Users can only view their own badges unless they're a teacher
    if ($currentUser['role'] !== 'teacher' && $userId != $_SESSION['user_id']) {
        jsonResponse(['error' => 'Permission denied'], 403);
    }
    
    try {
        $pdo = getDBConnection();
        
        // Get user's badges with details
        $stmt = $pdo->prepare("
            SELECT 
                b.id, b.name, b.description, b.icon_class, b.points, b.category,
                ub.awarded_at, ub.notes,
                awarder.full_name as awarded_by_name
            FROM user_badges ub
            JOIN badges b ON ub.badge_id = b.id
            LEFT JOIN users awarder ON ub.awarded_by = awarder.id
            WHERE ub.user_id = ?
            ORDER BY ub.awarded_at DESC
        ");
        $stmt->execute([$userId]);
        $userBadges = $stmt->fetchAll();
        
        // Get user's total points
        $totalPoints = getUserTotalPoints($userId);
        
        // Get user info
        $stmt = $pdo->prepare("SELECT username, full_name, role FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $userInfo = $stmt->fetch();
        
        // Get badge statistics by category
        $stmt = $pdo->prepare("
            SELECT 
                b.category,
                COUNT(*) as count,
                SUM(b.points) as total_points
            FROM user_badges ub
            JOIN badges b ON ub.badge_id = b.id
            WHERE ub.user_id = ?
            GROUP BY b.category
        ");
        $stmt->execute([$userId]);
        $categoryStats = $stmt->fetchAll();
        
        jsonResponse([
            'success' => true,
            'user' => $userInfo,
            'badges' => $userBadges,
            'total_badges' => count($userBadges),
            'total_points' => $totalPoints,
            'category_stats' => $categoryStats
        ]);
        
    } catch (Exception $e) {
        error_log("Error getting user badges: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to retrieve user badges'], 500);
    }
}

/**
 * Get badges available to be awarded (not yet earned by user)
 */
function handleGetAvailableBadges() {
    $userId = $_GET['user_id'] ?? null;
    
    if (!$userId) {
        jsonResponse(['error' => 'User ID is required'], 400);
    }
    
    // Only teachers can view available badges for awarding
    requireRole('teacher');
    
    try {
        $pdo = getDBConnection();
        
        $stmt = $pdo->prepare("
            SELECT b.id, b.name, b.description, b.icon_class, b.points, b.category
            FROM badges b
            WHERE b.is_active = TRUE 
            AND b.id NOT IN (
                SELECT badge_id FROM user_badges WHERE user_id = ?
            )
            ORDER BY b.category, b.points DESC
        ");
        $stmt->execute([$userId]);
        $availableBadges = $stmt->fetchAll();
        
        jsonResponse([
            'success' => true,
            'badges' => $availableBadges,
            'total' => count($availableBadges)
        ]);
        
    } catch (Exception $e) {
        error_log("Error getting available badges: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to retrieve available badges'], 500);
    }
}

/**
 * Get leaderboard showing top users by points
 */
function handleGetLeaderboard() {
    $limit = min((int)($_GET['limit'] ?? 10), 50); // Max 50 users
    $role = $_GET['role'] ?? null; // Optional role filter
    
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
                u.id, u.username, u.full_name, u.role,
                COUNT(ub.badge_id) as total_badges,
                COALESCE(SUM(b.points), 0) as total_points,
                MAX(ub.awarded_at) as last_badge_date
            FROM users u
            LEFT JOIN user_badges ub ON u.id = ub.user_id
            LEFT JOIN badges b ON ub.badge_id = b.id
            $whereClause
            GROUP BY u.id, u.username, u.full_name, u.role
            ORDER BY total_points DESC, total_badges DESC, last_badge_date DESC
            LIMIT ?
        ");
        
        $params[] = $limit;
        $stmt->execute($params);
        $leaderboard = $stmt->fetchAll();
        
        // Add rank to each user
        foreach ($leaderboard as $index => &$user) {
            $user['rank'] = $index + 1;
        }
        
        jsonResponse([
            'success' => true,
            'leaderboard' => $leaderboard,
            'total' => count($leaderboard)
        ]);
        
    } catch (Exception $e) {
        error_log("Error getting leaderboard: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to retrieve leaderboard'], 500);
    }
}

/**
 * Get detailed information about a specific badge
 */
function handleGetBadgeDetails() {
    $badgeId = $_GET['badge_id'] ?? null;
    
    if (!$badgeId) {
        jsonResponse(['error' => 'Badge ID is required'], 400);
    }
    
    try {
        $pdo = getDBConnection();
        
        // Get badge details
        $stmt = $pdo->prepare("
            SELECT id, name, description, icon_class, points, category, created_at
            FROM badges 
            WHERE id = ? AND is_active = TRUE
        ");
        $stmt->execute([$badgeId]);
        $badge = $stmt->fetch();
        
        if (!$badge) {
            jsonResponse(['error' => 'Badge not found'], 404);
        }
        
        // Get users who have earned this badge
        $stmt = $pdo->prepare("
            SELECT 
                u.username, u.full_name, u.role,
                ub.awarded_at, ub.notes,
                awarder.full_name as awarded_by_name
            FROM user_badges ub
            JOIN users u ON ub.user_id = u.id
            LEFT JOIN users awarder ON ub.awarded_by = awarder.id
            WHERE ub.badge_id = ?
            ORDER BY ub.awarded_at DESC
            LIMIT 20
        ");
        $stmt->execute([$badgeId]);
        $recipients = $stmt->fetchAll();
        
        // Get total count of recipients
        $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM user_badges WHERE badge_id = ?");
        $stmt->execute([$badgeId]);
        $totalRecipients = $stmt->fetch()['count'];
        
        jsonResponse([
            'success' => true,
            'badge' => $badge,
            'recipients' => $recipients,
            'total_recipients' => $totalRecipients
        ]);
        
    } catch (Exception $e) {
        error_log("Error getting badge details: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to retrieve badge details'], 500);
    }
}

/**
 * Award a badge to a user
 */
function handleAwardBadge() {
    // Only teachers can award badges
    requireRole('teacher');
    
    $badgeId = (int)($_POST['badge_id'] ?? 0);
    $userId = (int)($_POST['user_id'] ?? 0);
    $notes = sanitizeInput($_POST['notes'] ?? '');
    
    if (!$badgeId || !$userId) {
        jsonResponse(['error' => 'Badge ID and User ID are required'], 400);
    }
    
    try {
        $pdo = getDBConnection();
        
        // Check if badge exists and is active
        $stmt = $pdo->prepare("SELECT * FROM badges WHERE id = ? AND is_active = TRUE");
        $stmt->execute([$badgeId]);
        $badge = $stmt->fetch();
        
        if (!$badge) {
            jsonResponse(['error' => 'Badge not found or inactive'], 404);
        }
        
        // Check if user exists and is active
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ? AND is_active = TRUE");
        $stmt->execute([$userId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            jsonResponse(['error' => 'User not found or inactive'], 404);
        }
        
        // Check if user already has this badge
        $stmt = $pdo->prepare("SELECT * FROM user_badges WHERE user_id = ? AND badge_id = ?");
        $stmt->execute([$userId, $badgeId]);
        
        if ($stmt->fetch()) {
            jsonResponse(['error' => 'User already has this badge'], 400);
        }
        
        // Award the badge
        $stmt = $pdo->prepare("
            INSERT INTO user_badges (user_id, badge_id, awarded_by, notes) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$userId, $badgeId, $_SESSION['user_id'], $notes]);
        
        logActivity('badge_awarded', [
            'badge_id' => $badgeId,
            'badge_name' => $badge['name'],
            'recipient_id' => $userId,
            'recipient_name' => $user['username'],
            'awarded_by' => $_SESSION['user_id']
        ]);
        
        jsonResponse([
            'success' => true,
            'message' => 'Badge awarded successfully!',
            'badge' => $badge,
            'recipient' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'full_name' => $user['full_name']
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Error awarding badge: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to award badge'], 500);
    }
}

/**
 * Create a new badge (admin/teacher only)
 */
function handleCreateBadge() {
    // Only teachers can create badges
    requireRole('teacher');
    
    $name = sanitizeInput($_POST['name'] ?? '');
    $description = sanitizeInput($_POST['description'] ?? '');
    $iconClass = sanitizeInput($_POST['icon_class'] ?? 'badge-default');
    $points = (int)($_POST['points'] ?? 0);
    $category = $_POST['category'] ?? 'achievement';
    
    if (empty($name) || empty($description)) {
        jsonResponse(['error' => 'Name and description are required'], 400);
    }
    
    if (!in_array($category, ['welcome', 'assignment', 'attendance', 'communication', 'achievement'])) {
        jsonResponse(['error' => 'Invalid category'], 400);
    }
    
    try {
        $pdo = getDBConnection();
        
        // Check if badge name already exists
        $stmt = $pdo->prepare("SELECT id FROM badges WHERE name = ?");
        $stmt->execute([$name]);
        
        if ($stmt->fetch()) {
            jsonResponse(['error' => 'Badge with this name already exists'], 400);
        }
        
        // Create the badge
        $stmt = $pdo->prepare("
            INSERT INTO badges (name, description, icon_class, points, category) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$name, $description, $iconClass, $points, $category]);
        
        $badgeId = $pdo->lastInsertId();
        
        logActivity('badge_created', [
            'badge_id' => $badgeId,
            'name' => $name,
            'points' => $points,
            'category' => $category
        ]);
        
        jsonResponse([
            'success' => true,
            'message' => 'Badge created successfully!',
            'badge' => [
                'id' => $badgeId,
                'name' => $name,
                'description' => $description,
                'icon_class' => $iconClass,
                'points' => $points,
                'category' => $category
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Error creating badge: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to create badge'], 500);
    }
}

/**
 * Revoke a badge from a user
 */
function handleRevokeBadge() {
    // Only teachers can revoke badges
    requireRole('teacher');
    
    $userBadgeId = (int)($_POST['user_badge_id'] ?? 0);
    $reason = sanitizeInput($_POST['reason'] ?? '');
    
    if (!$userBadgeId) {
        jsonResponse(['error' => 'User badge ID is required'], 400);
    }
    
    try {
        $pdo = getDBConnection();
        
        // Get badge details before deletion
        $stmt = $pdo->prepare("
            SELECT ub.*, b.name as badge_name, u.username, u.full_name
            FROM user_badges ub
            JOIN badges b ON ub.badge_id = b.id
            JOIN users u ON ub.user_id = u.id
            WHERE ub.id = ?
        ");
        $stmt->execute([$userBadgeId]);
        $userBadge = $stmt->fetch();
        
        if (!$userBadge) {
            jsonResponse(['error' => 'User badge not found'], 404);
        }
        
        // Delete the user badge
        $stmt = $pdo->prepare("DELETE FROM user_badges WHERE id = ?");
        $stmt->execute([$userBadgeId]);
        
        logActivity('badge_revoked', [
            'user_badge_id' => $userBadgeId,
            'badge_name' => $userBadge['badge_name'],
            'user_id' => $userBadge['user_id'],
            'username' => $userBadge['username'],
            'reason' => $reason,
            'revoked_by' => $_SESSION['user_id']
        ]);
        
        jsonResponse([
            'success' => true,
            'message' => 'Badge revoked successfully',
            'revoked_badge' => [
                'badge_name' => $userBadge['badge_name'],
                'user_name' => $userBadge['full_name'],
                'reason' => $reason
            ]
        ]);
        
    } catch (Exception $e) {
        error_log("Error revoking badge: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to revoke badge'], 500);
    }
}
?>
