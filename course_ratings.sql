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
-- Table structure for table `course_ratings`
--

DROP TABLE IF EXISTS `course_ratings`;
CREATE TABLE IF NOT EXISTS `course_ratings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `course_id` int NOT NULL,
  `student_id` int NOT NULL,
  `rating` tinyint NOT NULL,
  `review` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_rating` (`course_id`,`student_id`)
) ;

--
-- Dumping data for table `course_ratings`
--

INSERT INTO `course_ratings` (`id`, `course_id`, `student_id`, `rating`, `review`, `created_at`) VALUES
(1, 2, 6, 3, 'Good', '2026-03-27 08:24:54'),
(2, 2, 28, 3, 'Good', '2026-03-13 21:10:26'),
(3, 8, 28, 2, '', '2026-03-13 21:11:27'),
(4, 8, 6, 3, 'Good', '2026-03-15 21:06:17'),
(5, 12, 6, 4, 'Nice', '2026-03-16 09:55:36'),
(6, 6, 6, 5, 'Easy to understand', '2026-03-30 09:31:53');
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
