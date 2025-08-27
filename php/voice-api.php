<?php
/**
 * Learning Management System - Voice Feedback API
 * Handles voice message upload, retrieval, and management
 */

require_once 'config.php';

// Handle CORS preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Require authentication for all voice operations
requireAuth();

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Route requests based on method and action
switch ($method) {
    case 'GET':
        switch ($action) {
            case 'messages':
                handleGetMessages();
                break;
            case 'conversation':
                handleGetConversation();
                break;
            case 'unread_count':
                handleGetUnreadCount();
                break;
            default:
                jsonResponse(['error' => 'Invalid action for GET method'], 400);
        }
        break;
    case 'POST':
        switch ($action) {
            case 'upload':
                handleUploadVoiceMessage();
                break;
            case 'mark_read':
                handleMarkMessageAsRead();
                break;
            case 'send_text':
                handleSendTextMessage();
                break;
            default:
                jsonResponse(['error' => 'Invalid action for POST method'], 400);
        }
        break;
    case 'DELETE':
        if ($action === 'delete') {
            handleDeleteMessage();
        } else {
            jsonResponse(['error' => 'Invalid action for DELETE method'], 400);
        }
        break;
    default:
        jsonResponse(['error' => 'Method not allowed'], 405);
}

/**
 * Get voice messages for current user
 */
function handleGetMessages() {
    $userId = $_SESSION['user_id'];
    $type = $_GET['type'] ?? 'all'; // 'sent', 'received', or 'all'
    $limit = min((int)($_GET['limit'] ?? 20), 100);
    $offset = (int)($_GET['offset'] ?? 0);
    
    try {
        $pdo = getDBConnection();
        
        $whereClause = "";
        $params = [];
        
        switch ($type) {
            case 'sent':
                $whereClause = "WHERE vf.sender_id = ?";
                $params[] = $userId;
                break;
            case 'received':
                $whereClause = "WHERE vf.receiver_id = ?";
                $params[] = $userId;
                break;
            default: // 'all'
                $whereClause = "WHERE (vf.sender_id = ? OR vf.receiver_id = ?)";
                $params[] = $userId;
                $params[] = $userId;
        }
        
        $stmt = $pdo->prepare("
            SELECT 
                vf.*,
                sender.username as sender_username,
                sender.full_name as sender_name,
                sender.role as sender_role,
                receiver.username as receiver_username,
                receiver.full_name as receiver_name,
                receiver.role as receiver_role
            FROM voice_feedback vf
            JOIN users sender ON vf.sender_id = sender.id
            JOIN users receiver ON vf.receiver_id = receiver.id
            $whereClause
            ORDER BY vf.created_at DESC
            LIMIT ? OFFSET ?
        ");
        
        $params[] = $limit;
        $params[] = $offset;
        $stmt->execute($params);
        $messages = $stmt->fetchAll();
        
        // Process messages to add additional info
        foreach ($messages as &$message) {
            $message['is_sender'] = ($message['sender_id'] == $userId);
            $message['file_size_formatted'] = $message['file_size'] ? formatFileSize($message['file_size']) : null;
            $message['duration_formatted'] = $message['duration'] ? formatDuration($message['duration']) : null;
        }
        
        jsonResponse([
            'success' => true,
            'messages' => $messages,
            'total' => count($messages),
            'has_more' => count($messages) == $limit
        ]);
        
    } catch (Exception $e) {
        error_log("Error getting messages: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to retrieve messages'], 500);
    }
}

/**
 * Get conversation between two users
 */
function handleGetConversation() {
    $userId = $_SESSION['user_id'];
    $otherUserId = (int)($_GET['user_id'] ?? 0);
    $limit = min((int)($_GET['limit'] ?? 50), 100);
    
    if (!$otherUserId) {
        jsonResponse(['error' => 'User ID is required'], 400);
    }
    
    try {
        $pdo = getDBConnection();
        
        // Get conversation between current user and specified user
        $stmt = $pdo->prepare("
            SELECT 
                vf.*,
                sender.username as sender_username,
                sender.full_name as sender_name,
                sender.role as sender_role,
                receiver.username as receiver_username,
                receiver.full_name as receiver_name,
                receiver.role as receiver_role
            FROM voice_feedback vf
            JOIN users sender ON vf.sender_id = sender.id
            JOIN users receiver ON vf.receiver_id = receiver.id
            WHERE (vf.sender_id = ? AND vf.receiver_id = ?) 
               OR (vf.sender_id = ? AND vf.receiver_id = ?)
            ORDER BY vf.created_at ASC
            LIMIT ?
        ");
        $stmt->execute([$userId, $otherUserId, $otherUserId, $userId, $limit]);
        $messages = $stmt->fetchAll();
        
        // Mark received messages as read
        $stmt = $pdo->prepare("
            UPDATE voice_feedback 
            SET is_read = TRUE, read_at = NOW() 
            WHERE sender_id = ? AND receiver_id = ? AND is_read = FALSE
        ");
        $stmt->execute([$otherUserId, $userId]);
        
        // Process messages
        foreach ($messages as &$message) {
            $message['is_sender'] = ($message['sender_id'] == $userId);
            $message['file_size_formatted'] = $message['file_size'] ? formatFileSize($message['file_size']) : null;
            $message['duration_formatted'] = $message['duration'] ? formatDuration($message['duration']) : null;
        }
        
        // Get other user info
        $stmt = $pdo->prepare("SELECT username, full_name, role FROM users WHERE id = ?");
        $stmt->execute([$otherUserId]);
        $otherUser = $stmt->fetch();
        
        jsonResponse([
            'success' => true,
            'messages' => $messages,
            'other_user' => $otherUser,
            'total' => count($messages)
        ]);
        
    } catch (Exception $e) {
        error_log("Error getting conversation: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to retrieve conversation'], 500);
    }
}

/**
 * Get unread message count for current user
 */
function handleGetUnreadCount() {
    $userId = $_SESSION['user_id'];
    
    try {
        $count = getUnreadMessageCount($userId);
        
        jsonResponse([
            'success' => true,
            'unread_count' => $count
        ]);
        
    } catch (Exception $e) {
        error_log("Error getting unread count: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to get unread count'], 500);
    }
}

/**
 * Upload voice message
 */
function handleUploadVoiceMessage() {
    $receiverId = (int)($_POST['receiver_id'] ?? 0);
    $subject = sanitizeInput($_POST['subject'] ?? '');
    $message = sanitizeInput($_POST['message'] ?? '');
    
    if (!$receiverId) {
        jsonResponse(['error' => 'Receiver ID is required'], 400);
    }
    
    try {
        $pdo = getDBConnection();
        
        // Verify receiver exists and is active
        $stmt = $pdo->prepare("SELECT id, username, role FROM users WHERE id = ? AND is_active = TRUE");
        $stmt->execute([$receiverId]);
        $receiver = $stmt->fetch();
        
        if (!$receiver) {
            jsonResponse(['error' => 'Receiver not found or inactive'], 404);
        }
        
        $filePath = null;
        $fileSize = null;
        $duration = null;
        
        // Handle file upload if present
        if (isset($_FILES['voice_file']) && $_FILES['voice_file']['error'] === UPLOAD_ERR_OK) {
            $uploadResult = handleFileUpload($_FILES['voice_file']);
            if ($uploadResult['success']) {
                $filePath = $uploadResult['file_path'];
                $fileSize = $uploadResult['file_size'];
                $duration = $uploadResult['duration'] ?? null;
            } else {
                jsonResponse(['error' => $uploadResult['error']], 400);
            }
        }
        
        // Must have either file or text message
        if (!$filePath && empty($message)) {
            jsonResponse(['error' => 'Either voice file or text message is required'], 400);
        }
        
        // Insert voice message
        $stmt = $pdo->prepare("
            INSERT INTO voice_feedback (sender_id, receiver_id, subject, file_path, message, duration, file_size) 
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$_SESSION['user_id'], $receiverId, $subject, $filePath, $message, $duration, $fileSize]);
        
        $messageId = $pdo->lastInsertId();
        
        // Award voice communicator badge if this is user's first voice message
        awardVoiceCommunicatorBadgeIfNeeded($_SESSION['user_id']);
        
        logActivity('voice_message_sent', [
            'message_id' => $messageId,
            'receiver_id' => $receiverId,
            'has_file' => !empty($filePath),
            'has_text' => !empty($message)
        ]);
        
        jsonResponse([
            'success' => true,
            'message' => 'Voice message sent successfully!',
            'message_id' => $messageId,
            'receiver' => $receiver
        ]);
        
    } catch (Exception $e) {
        error_log("Error uploading voice message: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to send voice message'], 500);
    }
}

/**
 * Send text-only message
 */
function handleSendTextMessage() {
    $receiverId = (int)($_POST['receiver_id'] ?? 0);
    $subject = sanitizeInput($_POST['subject'] ?? '');
    $message = sanitizeInput($_POST['message'] ?? '');
    
    if (!$receiverId || empty($message)) {
        jsonResponse(['error' => 'Receiver ID and message are required'], 400);
    }
    
    try {
        $pdo = getDBConnection();
        
        // Verify receiver exists
        $stmt = $pdo->prepare("SELECT id, username, role FROM users WHERE id = ? AND is_active = TRUE");
        $stmt->execute([$receiverId]);
        $receiver = $stmt->fetch();
        
        if (!$receiver) {
            jsonResponse(['error' => 'Receiver not found or inactive'], 404);
        }
        
        // Insert text message
        $stmt = $pdo->prepare("
            INSERT INTO voice_feedback (sender_id, receiver_id, subject, message) 
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$_SESSION['user_id'], $receiverId, $subject, $message]);
        
        $messageId = $pdo->lastInsertId();
        
        logActivity('text_message_sent', [
            'message_id' => $messageId,
            'receiver_id' => $receiverId
        ]);
        
        jsonResponse([
            'success' => true,
            'message' => 'Message sent successfully!',
            'message_id' => $messageId,
            'receiver' => $receiver
        ]);
        
    } catch (Exception $e) {
        error_log("Error sending text message: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to send message'], 500);
    }
}

/**
 * Mark message as read
 */
function handleMarkMessageAsRead() {
    $messageId = (int)($_POST['message_id'] ?? 0);
    
    if (!$messageId) {
        jsonResponse(['error' => 'Message ID is required'], 400);
    }
    
    try {
        $pdo = getDBConnection();
        
        // Update message as read (only if current user is the receiver)
        $stmt = $pdo->prepare("
            UPDATE voice_feedback 
            SET is_read = TRUE, read_at = NOW() 
            WHERE id = ? AND receiver_id = ? AND is_read = FALSE
        ");
        $stmt->execute([$messageId, $_SESSION['user_id']]);
        
        $rowsAffected = $stmt->rowCount();
        
        if ($rowsAffected > 0) {
            logActivity('message_marked_read', ['message_id' => $messageId]);
            
            jsonResponse([
                'success' => true,
                'message' => 'Message marked as read'
            ]);
        } else {
            jsonResponse([
                'success' => false,
                'message' => 'Message not found or already read'
            ]);
        }
        
    } catch (Exception $e) {
        error_log("Error marking message as read: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to mark message as read'], 500);
    }
}

/**
 * Delete message
 */
function handleDeleteMessage() {
    $messageId = (int)($_POST['message_id'] ?? 0);
    
    if (!$messageId) {
        jsonResponse(['error' => 'Message ID is required'], 400);
    }
    
    try {
        $pdo = getDBConnection();
        
        // Get message details first
        $stmt = $pdo->prepare("
            SELECT * FROM voice_feedback 
            WHERE id = ? AND (sender_id = ? OR receiver_id = ?)
        ");
        $stmt->execute([$messageId, $_SESSION['user_id'], $_SESSION['user_id']]);
        $message = $stmt->fetch();
        
        if (!$message) {
            jsonResponse(['error' => 'Message not found or access denied'], 404);
        }
        
        // Delete associated file if exists
        if ($message['file_path'] && file_exists($message['file_path'])) {
            unlink($message['file_path']);
        }
        
        // Delete message from database
        $stmt = $pdo->prepare("DELETE FROM voice_feedback WHERE id = ?");
        $stmt->execute([$messageId]);
        
        logActivity('message_deleted', [
            'message_id' => $messageId,
            'had_file' => !empty($message['file_path'])
        ]);
        
        jsonResponse([
            'success' => true,
            'message' => 'Message deleted successfully'
        ]);
        
    } catch (Exception $e) {
        error_log("Error deleting message: " . $e->getMessage());
        jsonResponse(['error' => 'Failed to delete message'], 500);
    }
}

/**
 * Handle file upload
 * @param array $file $_FILES array element
 * @return array Upload result
 */
function handleFileUpload($file) {
    // Check for upload errors
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'File upload error: ' . $file['error']];
    }
    
    // Check file size
    if ($file['size'] > MAX_FILE_SIZE) {
        return ['success' => false, 'error' => 'File too large. Maximum size: ' . formatFileSize(MAX_FILE_SIZE)];
    }
    
    // Check file type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, ALLOWED_AUDIO_TYPES)) {
        return ['success' => false, 'error' => 'Invalid file type. Allowed types: ' . implode(', ', ALLOWED_AUDIO_TYPES)];
    }
    
    // Ensure upload directory exists
    if (!ensureUploadDir(UPLOAD_DIR)) {
        return ['success' => false, 'error' => 'Upload directory not accessible'];
    }
    
    // Generate unique filename
    $extension = getFileExtension($file['name']);
    $filename = uniqid('voice_' . $_SESSION['user_id'] . '_' . time() . '_') . '.' . $extension;
    $filePath = UPLOAD_DIR . $filename;
    
    // Move uploaded file
    if (!move_uploaded_file($file['tmp_name'], $filePath)) {
        return ['success' => false, 'error' => 'Failed to save uploaded file'];
    }
    
    // Get audio duration if possible (requires getID3 or similar library)
    $duration = getAudioDuration($filePath);
    
    return [
        'success' => true,
        'file_path' => $filePath,
        'file_size' => $file['size'],
        'duration' => $duration,
        'mime_type' => $mimeType
    ];
}

/**
 * Get audio file duration (basic implementation)
 * @param string $filePath Path to audio file
 * @return int|null Duration in seconds
 */
function getAudioDuration($filePath) {
    // This is a basic implementation
    // For production, consider using getID3 library or ffmpeg
    try {
        if (function_exists('exec')) {
            $output = [];
            exec("ffprobe -v quiet -show_entries format=duration -of csv=p=0 " . escapeshellarg($filePath), $output);
            if (!empty($output[0]) && is_numeric($output[0])) {
                return (int)round($output[0]);
            }
        }
    } catch (Exception $e) {
        error_log("Error getting audio duration: " . $e->getMessage());
    }
    
    return null;
}

/**
 * Format duration in seconds to human readable format
 * @param int $seconds Duration in seconds
 * @return string Formatted duration
 */
function formatDuration($seconds) {
    if ($seconds < 60) {
        return $seconds . 's';
    } elseif ($seconds < 3600) {
        $minutes = floor($seconds / 60);
        $remainingSeconds = $seconds % 60;
        return $minutes . 'm ' . $remainingSeconds . 's';
    } else {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        return $hours . 'h ' . $minutes . 'm';
    }
}

/**
 * Award voice communicator badge if this is user's first voice message
 * @param int $userId User ID
 */
function awardVoiceCommunicatorBadgeIfNeeded($userId) {
    try {
        $pdo = getDBConnection();
        
        // Check if user already has voice communicator badge
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as count 
            FROM user_badges ub 
            JOIN badges b ON ub.badge_id = b.id 
            WHERE ub.user_id = ? AND b.category = 'communication'
        ");
        $stmt->execute([$userId]);
        $result = $stmt->fetch();
        
        if ($result['count'] == 0) {
            // Get voice communicator badge ID
            $stmt = $pdo->prepare("SELECT id FROM badges WHERE name LIKE '%Voice%' AND category = 'communication' LIMIT 1");
            $stmt->execute();
            $badge = $stmt->fetch();
            
            if ($badge) {
                // Award voice communicator badge
                $stmt = $pdo->prepare("
                    INSERT INTO user_badges (user_id, badge_id, notes) 
                    VALUES (?, ?, 'Automatically awarded for first voice message')
                ");
                $stmt->execute([$userId, $badge['id']]);
                
                logActivity('badge_awarded', [
                    'user_id' => $userId,
                    'badge_id' => $badge['id'],
                    'type' => 'voice_communicator'
                ]);
            }
        }
    } catch (Exception $e) {
        error_log("Error awarding voice communicator badge: " . $e->getMessage());
    }
}
?>
