-- ============================================
-- RMU E-Learning Platform - Database Setup
-- Run this entire file in phpMyAdmin > SQL tab
-- NOTE: Also create the folder uploads/avatars/
--       and give it write permissions (chmod 775)
-- ============================================

CREATE DATABASE IF NOT EXISTS rmu_elearn 
  CHARACTER SET utf8mb4 
  COLLATE utf8mb4_unicode_ci;

USE rmu_elearn;

-- Drop tables if they exist (clean install)
DROP TABLE IF EXISTS lesson_progress;
DROP TABLE IF EXISTS lessons;
DROP TABLE IF EXISTS enrollments;
DROP TABLE IF EXISTS courses;
DROP TABLE IF EXISTS users;

-- USERS (instructors + students)
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('instructor','student','admin') NOT NULL DEFAULT 'student',
    department VARCHAR(100) NOT NULL DEFAULT 'Information Technology Department',
    programme ENUM('IT','CS','CE') DEFAULT NULL,
    student_id VARCHAR(50) DEFAULT NULL,
    profile_pic VARCHAR(255) DEFAULT NULL,
    bio TEXT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- COURSES
CREATE TABLE courses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    instructor_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    course_code VARCHAR(20) DEFAULT NULL UNIQUE,
    description TEXT DEFAULT NULL,
    level ENUM('100','200','300','400') NOT NULL DEFAULT '100',
    is_published TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (instructor_id) REFERENCES users(id) ON DELETE CASCADE
);

-- LESSONS (videos and slides)
CREATE TABLE lessons (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT DEFAULT NULL,
    type ENUM('video','slide') NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_size BIGINT NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
);

-- ENROLLMENTS
CREATE TABLE enrollments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    student_id INT NOT NULL,
    enrolled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_enrollment (course_id, student_id),
    FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
);

-- LESSON PROGRESS
CREATE TABLE lesson_progress (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lesson_id INT NOT NULL,
    student_id INT NOT NULL,
    completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_progress (lesson_id, student_id),
    FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE,
    FOREIGN KEY (student_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Demo accounts (password = "password123" for all)
-- Admin email:       admin@rmu.edu.gh
-- Lecturer email:    firstname.lastname@rmu.edu.gh
-- Student email:     firstname.lastname@st.rmu.edu.gh
INSERT INTO users (full_name, email, password, role, department, programme) VALUES
('Administrator',    'admin@rmu.edu.gh',        '$2y$10$TKh8H1.PfuA2Pi/9rjQdXeVtBDqg6HvRKMuoaEhJrT.JHHH.1/7zO', 'admin',      'Information Technology Department', NULL),
('Dr. Kwame Mensah', 'kwame.mensah@rmu.edu.gh', '$2y$10$TKh8H1.PfuA2Pi/9rjQdXeVtBDqg6HvRKMuoaEhJrT.JHHH.1/7zO', 'instructor', 'Information Technology Department', 'IT'),
('Ama Asante',       'ama.asante@st.rmu.edu.gh','$2y$10$TKh8H1.PfuA2Pi/9rjQdXeVtBDqg6HvRKMuoaEhJrT.JHHH.1/7zO', 'student',    'Information Technology Department', 'CS');

-- Demo course
INSERT INTO courses (instructor_id, title, description, level, is_published) VALUES
(2, 'Introduction to Programming', 'A comprehensive introduction to programming fundamentals using Python — variables, loops, functions, and problem solving.', '100', 1);

-- Enroll demo student
INSERT INTO enrollments (course_id, student_id) VALUES (1, 3);
