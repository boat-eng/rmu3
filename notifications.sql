-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: Apr 01, 2026 at 07:12 PM
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
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `type` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `link` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT '',
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_read` (`user_id`,`is_read`)
) ENGINE=MyISAM AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `type`, `message`, `link`, `is_read`, `created_at`) VALUES
(4, 16, 'course_complete', 'Seidu Fauziatu Abdallah completed your course \"Operating System\"', '../instructor/students.php', 1, '2026-03-12 13:19:29'),
(5, 16, 'course_complete', 'Seidu Fauziatu Abdallah completed your course \"Operating System\"', '../instructor/students.php', 1, '2026-03-13 20:55:40'),
(6, 16, 'course_complete', 'Seidu Fauziatu Abdallah completed your course \"Operating System\"', '../instructor/students.php', 1, '2026-03-13 21:03:46'),
(7, 16, 'course_complete', 'George Boateng completed your course \"Operating System\"', '../instructor/students.php', 1, '2026-03-13 21:10:15'),
(8, 5, 'course_complete', 'George Boateng completed your course \"DATA COMMUNICATION & COMPUTER NETWORKS I\"', '../instructor/students.php', 1, '2026-03-13 21:11:11'),
(9, 28, 'new_lesson', 'New slide added to \"DATA COMMUNICATION & COMPUTER NETWORKS I\": Week4', 'http://localhost/rmu3/student/watch.php?id=8&lesson=0', 0, '2026-03-15 09:37:36'),
(10, 5, 'course_complete', 'Seidu Fauziatu Abdallah completed your course \"DATA COMMUNICATION & COMPUTER NETWORKS I\"', '../instructor/students.php', 1, '2026-03-15 21:05:57'),
(11, 16, 'course_complete', 'Seidu Fauziatu Abdallah completed your course \"Data strutu\"', '../instructor/students.php', 1, '2026-03-16 09:55:24'),
(12, 16, 'course_complete', 'Seidu Fauziatu Abdallah completed your course \"Operating System\"', '../instructor/students.php', 1, '2026-03-27 08:24:46'),
(13, 24, 'course_complete', 'Seidu Fauziatu Abdallah completed your course \"BASIC DIGITAL ELECTRONICS\"', '../instructor/students.php', 0, '2026-03-30 09:31:18'),
(14, 6, 'new_lesson', 'New video added to \"Operating System\": THE', 'http://localhost/rmu3/student/watch.php?id=2&lesson=0', 1, '2026-04-01 18:19:47'),
(15, 28, 'new_lesson', 'New video added to \"Operating System\": THE', 'http://localhost/rmu3/student/watch.php?id=2&lesson=0', 0, '2026-04-01 18:19:47'),
(16, 29, 'new_lesson', 'New video added to \"Operating System\": THE', 'http://localhost/rmu3/student/watch.php?id=2&lesson=0', 0, '2026-04-01 18:19:47'),
(17, 16, 'course_complete', 'Seidu Fauziatu Abdallah completed your course \"Operating System\"', '../instructor/students.php', 0, '2026-04-01 18:20:41');
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
