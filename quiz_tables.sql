-- ============================================
-- QUIZ SYSTEM DATABASE TABLES
-- Run this in phpMyAdmin on your elearning DB
-- ============================================

-- Quizzes table
CREATE TABLE IF NOT EXISTS `quizzes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `course_id` INT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `pass_mark` TINYINT NOT NULL DEFAULT 70 COMMENT 'Percentage required to pass',
  `time_limit` SMALLINT DEFAULT NULL COMMENT 'Time limit in minutes, NULL = no limit',
  `allow_retakes` TINYINT(1) DEFAULT 0,
  `is_published` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`course_id`) REFERENCES `courses`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Quiz questions
CREATE TABLE IF NOT EXISTS `quiz_questions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `quiz_id` INT NOT NULL,
  `question_text` TEXT NOT NULL,
  `type` ENUM('mcq', 'truefalse', 'short') NOT NULL DEFAULT 'mcq',
  `marks` TINYINT NOT NULL DEFAULT 1,
  `order_num` TINYINT NOT NULL DEFAULT 0,
  FOREIGN KEY (`quiz_id`) REFERENCES `quizzes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- MCQ options (4 choices per question)
CREATE TABLE IF NOT EXISTS `quiz_options` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `question_id` INT NOT NULL,
  `option_text` VARCHAR(500) NOT NULL,
  `is_correct` TINYINT(1) DEFAULT 0,
  FOREIGN KEY (`question_id`) REFERENCES `quiz_questions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Student quiz attempts
CREATE TABLE IF NOT EXISTS `quiz_attempts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `quiz_id` INT NOT NULL,
  `student_id` INT NOT NULL,
  `score` FLOAT DEFAULT 0 COMMENT 'Percentage score',
  `total_marks` INT DEFAULT 0,
  `earned_marks` FLOAT DEFAULT 0,
  `passed` TINYINT(1) DEFAULT 0,
  `status` ENUM('in_progress', 'submitted', 'marked') DEFAULT 'in_progress',
  `started_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `submitted_at` TIMESTAMP NULL DEFAULT NULL,
  FOREIGN KEY (`quiz_id`) REFERENCES `quizzes`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Student answers per question
CREATE TABLE IF NOT EXISTS `quiz_answers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `attempt_id` INT NOT NULL,
  `question_id` INT NOT NULL,
  `selected_option_id` INT DEFAULT NULL COMMENT 'For MCQ and True/False',
  `short_answer_text` TEXT DEFAULT NULL COMMENT 'For short answer questions',
  `is_correct` TINYINT(1) DEFAULT NULL COMMENT 'NULL = not yet marked (short answer)',
  `marks_awarded` FLOAT DEFAULT 0,
  FOREIGN KEY (`attempt_id`) REFERENCES `quiz_attempts`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`question_id`) REFERENCES `quiz_questions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
