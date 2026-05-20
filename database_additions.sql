-- ============================================================
-- RMU E-Learning - NEW TABLES (run in phpMyAdmin)
-- ============================================================

-- 1. Announcements (admin posts)
CREATE TABLE IF NOT EXISTS announcements (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    title      VARCHAR(255) NOT NULL,
    body       TEXT NOT NULL,
    type       ENUM('info','warning','success','danger') DEFAULT 'info',
    is_active  TINYINT(1) DEFAULT 1,
    created_by INT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Notifications
CREATE TABLE IF NOT EXISTS notifications (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    type       VARCHAR(60) NOT NULL,   -- 'new_lesson', 'course_complete'
    message    TEXT NOT NULL,
    link       VARCHAR(255) DEFAULT '',
    is_read    TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user (user_id),
    INDEX idx_read (user_id, is_read)
);

-- 3. Course Ratings & Reviews
CREATE TABLE IF NOT EXISTS course_ratings (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    course_id  INT NOT NULL,
    student_id INT NOT NULL,
    rating     TINYINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    review     TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rating (course_id, student_id)
);
