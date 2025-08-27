-- Learning Management System Database Schema
-- Tech Stack: MySQL
-- Features: QR Authentication, Badge Gamification, Voice Feedback

CREATE DATABASE IF NOT EXISTS lms_system;
USE lms_system;

-- Drop tables if they exist (for clean setup)
DROP TABLE IF EXISTS qr_sessions;
DROP TABLE IF EXISTS voice_feedback;
DROP TABLE IF EXISTS user_badges;
DROP TABLE IF EXISTS badges;
DROP TABLE IF EXISTS users;

-- Users table - stores all system users (teachers, students, guardians)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('teacher', 'student', 'guardian') NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    qr_token VARCHAR(255) UNIQUE,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Badges table - defines available badges in the system
CREATE TABLE badges (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    icon_class VARCHAR(50) DEFAULT 'badge-default',
    points INT DEFAULT 0,
    category ENUM('welcome', 'assignment', 'attendance', 'communication', 'achievement') DEFAULT 'achievement',
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- User badges table - tracks which badges users have earned
CREATE TABLE user_badges (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    badge_id INT NOT NULL,
    awarded_by INT, -- ID of user who awarded the badge (usually teacher)
    awarded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes TEXT, -- Optional notes about why badge was awarded
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (badge_id) REFERENCES badges(id) ON DELETE CASCADE,
    FOREIGN KEY (awarded_by) REFERENCES users(id) ON DELETE SET NULL,
    UNIQUE KEY unique_user_badge (user_id, badge_id) -- Prevent duplicate badges
);

-- Voice feedback table - stores voice messages between users
CREATE TABLE voice_feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    subject VARCHAR(200),
    file_path VARCHAR(255), -- Path to uploaded voice file
    message TEXT, -- Optional text message accompanying voice
    duration INT, -- Duration in seconds
    file_size INT, -- File size in bytes
    is_read BOOLEAN DEFAULT FALSE,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_receiver_unread (receiver_id, is_read),
    INDEX idx_sender_date (sender_id, created_at)
);

-- QR authentication sessions table - manages QR code authentication
CREATE TABLE qr_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    token VARCHAR(255) UNIQUE NOT NULL,
    user_id INT NULL, -- NULL until someone uses the token
    expires_at TIMESTAMP NOT NULL,
    is_used BOOLEAN DEFAULT FALSE,
    used_at TIMESTAMP NULL,
    ip_address VARCHAR(45), -- Support both IPv4 and IPv6
    user_agent TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_token_expires (token, expires_at),
    INDEX idx_cleanup (expires_at, is_used)
);

-- Insert sample badges
INSERT INTO badges (name, description, icon_class, points, category) VALUES
('Welcome Badge', 'Awarded for joining the Learning Management System', 'badge-welcome', 10, 'welcome'),
('First Assignment', 'Completed your first assignment successfully', 'badge-assignment', 25, 'assignment'),
('Perfect Attendance', 'No missed classes this month', 'badge-attendance', 50, 'attendance'),
('Voice Communicator', 'Sent your first voice message', 'badge-voice', 15, 'communication'),
('Helpful Student', 'Helped a classmate with their studies', 'badge-helpful', 30, 'achievement'),
('Quick Learner', 'Completed 5 assignments ahead of schedule', 'badge-quick', 40, 'achievement'),
('Class Participation', 'Actively participated in class discussions', 'badge-participation', 20, 'achievement'),
('Study Streak', 'Logged in and studied for 7 consecutive days', 'badge-streak', 35, 'achievement'),
('Excellence Award', 'Achieved top grades in multiple subjects', 'badge-excellence', 100, 'achievement'),
('Team Player', 'Successfully completed a group project', 'badge-team', 45, 'achievement');

-- Insert sample users (passwords are hashed version of 'password123')
INSERT INTO users (username, email, password_hash, role, full_name) VALUES
('teacher1', 'teacher1@school.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'teacher', 'Ms. Sarah Johnson'),
('teacher2', 'teacher2@school.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'teacher', 'Mr. David Wilson'),
('student1', 'student1@school.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'Alex Thompson'),
('student2', 'student2@school.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'Emma Davis'),
('student3', 'student3@school.edu', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'Michael Brown'),
('guardian1', 'guardian1@email.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'guardian', 'Jennifer Thompson'),
('guardian2', 'guardian2@email.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'guardian', 'Robert Davis');

-- Award some sample badges to demonstrate the system
INSERT INTO user_badges (user_id, badge_id, awarded_by, notes) VALUES
(3, 1, 1, 'Welcome to the LMS system!'), -- Alex gets welcome badge from teacher1
(4, 1, 1, 'Welcome to the LMS system!'), -- Emma gets welcome badge from teacher1
(5, 1, 2, 'Welcome to the LMS system!'), -- Michael gets welcome badge from teacher2
(3, 4, 1, 'Great job on your first voice message!'), -- Alex gets voice communicator badge
(4, 2, 1, 'Excellent work on your first assignment!'), -- Emma gets first assignment badge
(4, 7, 1, 'Outstanding participation in today\'s discussion!'); -- Emma gets participation badge

-- Insert some sample voice messages
INSERT INTO voice_feedback (sender_id, receiver_id, subject, message, is_read) VALUES
(1, 3, 'Great work on your assignment', 'Alex, your essay was well-written and showed great understanding of the topic.', FALSE),
(3, 6, 'Update on school progress', 'Hi Mom, I wanted to let you know I got a good grade on my math test today!', TRUE),
(1, 6, 'Alex\'s Progress Report', 'Jennifer, Alex is doing excellent work in class. He\'s very engaged and helpful to other students.', FALSE),
(4, 1, 'Question about homework', 'Ms. Johnson, I have a question about the science homework due tomorrow.', TRUE);

-- Create indexes for better performance
CREATE INDEX idx_users_role ON users(role);
CREATE INDEX idx_users_active ON users(is_active);
CREATE INDEX idx_badges_category ON badges(category);
CREATE INDEX idx_badges_active ON badges(is_active);

-- Create a view for user badge summary
CREATE VIEW user_badge_summary AS
SELECT 
    u.id as user_id,
    u.username,
    u.full_name,
    u.role,
    COUNT(ub.badge_id) as total_badges,
    COALESCE(SUM(b.points), 0) as total_points
FROM users u
LEFT JOIN user_badges ub ON u.id = ub.user_id
LEFT JOIN badges b ON ub.badge_id = b.id
WHERE u.is_active = TRUE
GROUP BY u.id, u.username, u.full_name, u.role;

-- Create a view for unread message counts
CREATE VIEW unread_message_counts AS
SELECT 
    receiver_id,
    COUNT(*) as unread_count
FROM voice_feedback 
WHERE is_read = FALSE 
GROUP BY receiver_id;

-- Show sample data
SELECT 'Sample Users:' as info;
SELECT id, username, full_name, role FROM users;

SELECT 'Sample Badges:' as info;
SELECT id, name, points, category FROM badges LIMIT 5;

SELECT 'User Badge Summary:' as info;
SELECT * FROM user_badge_summary;

SELECT 'Database setup completed successfully!' as status;
