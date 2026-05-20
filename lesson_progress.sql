-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Apr 01, 2026 at 07:13 PM
-- Server version: 8.4.7
-- PHP Version: 8.3.28

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `rmu_elearn`
--

-- --------------------------------------------------------

--
-- Table structure for table `lesson_progress`
--

DROP TABLE IF EXISTS `lesson_progress`;
CREATE TABLE IF NOT EXISTS `lesson_progress` (
  `id` int NOT NULL AUTO_INCREMENT,
  `lesson_id` int NOT NULL,
  `student_id` int NOT NULL,
  `completed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_progress` (`lesson_id`,`student_id`),
  KEY `student_id` (`student_id`)
) ENGINE=MyISAM AUTO_INCREMENT=33 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `lesson_progress`
--

INSERT INTO `lesson_progress` (`id`, `lesson_id`, `student_id`, `completed_at`, `created_at`) VALUES
(16, 19, 6, '2026-03-12 13:19:29', '2026-03-27 21:21:04'),
(17, 20, 6, '2026-03-13 20:55:40', '2026-03-27 21:21:04'),
(18, 21, 6, '2026-03-13 21:03:46', '2026-03-27 21:21:04'),
(19, 19, 28, '2026-03-13 21:10:05', '2026-03-27 21:21:04'),
(20, 20, 28, '2026-03-13 21:10:11', '2026-03-27 21:21:04'),
(21, 21, 28, '2026-03-13 21:10:15', '2026-03-27 21:21:04'),
(22, 22, 28, '2026-03-13 21:11:06', '2026-03-27 21:21:04'),
(23, 23, 28, '2026-03-13 21:11:11', '2026-03-27 21:21:04'),
(24, 22, 6, '2026-03-15 21:04:31', '2026-03-27 21:21:04'),
(25, 23, 6, '2026-03-15 21:05:51', '2026-03-27 21:21:04'),
(28, 26, 6, '2026-03-16 09:55:24', '2026-03-27 21:21:04'),
(27, 25, 6, '2026-03-16 09:55:07', '2026-03-27 21:21:04'),
(26, 24, 6, '2026-03-15 21:05:57', '2026-03-27 21:21:04'),
(29, 31, 6, '2026-03-27 06:02:22', '2026-03-27 21:21:04'),
(30, 29, 6, '2026-03-27 08:24:46', '2026-03-27 21:21:04'),
(31, 32, 6, '2026-03-30 10:31:18', '2026-03-30 09:31:18'),
(32, 33, 6, '2026-04-01 19:20:41', '2026-04-01 18:20:41');
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
