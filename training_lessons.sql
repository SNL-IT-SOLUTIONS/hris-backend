-- phpMyAdmin SQL Dump
-- version 5.2.2
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 13, 2026 at 02:04 PM
-- Server version: 11.8.3-MariaDB-log
-- PHP Version: 7.2.34

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `u673355866_hris`
--

-- --------------------------------------------------------

--
-- Table structure for table `training_lessons`
--

CREATE TABLE `training_lessons` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `lesson_title` varchar(2552) NOT NULL,
  `lesson_description` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `training_lessons`
--

INSERT INTO `training_lessons` (`id`, `lesson_title`, `lesson_description`, `created_at`, `updated_at`) VALUES
(1, 'Workplace Safety', 'Introduction to workplace hazards, risk awareness, and safety procedures for employees.', '2026-03-13 14:02:12', '2026-03-13 14:02:12'),
(2, 'Safety Equipment Usage', 'Training on the correct usage of safety equipment and reporting procedures.', '2026-03-13 14:02:12', '2026-03-13 14:02:12'),
(3, 'Workplace Hazard Identification', 'Learn how to identify potential hazards in the workplace and take preventive actions.', '2026-03-13 14:02:12', '2026-03-13 14:02:12'),
(4, 'Personal Protective Equipment (PPE)', 'Understanding the importance and correct use of personal protective equipment.', '2026-03-13 14:02:12', '2026-03-13 14:02:12'),
(5, 'Emergency Procedures', 'Guidelines on evacuation procedures, fire safety, and emergency response protocols.', '2026-03-13 14:02:12', '2026-03-13 14:02:12');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `training_lessons`
--
ALTER TABLE `training_lessons`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `training_lessons`
--
ALTER TABLE `training_lessons`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
