-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jan 05, 2026 at 09:07 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `chrmo_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `app_settings`
--

CREATE TABLE `app_settings` (
  `k` varchar(64) NOT NULL,
  `v` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `app_settings`
--

INSERT INTO `app_settings` (`k`, `v`) VALUES
('dept_activity_reset_at', '2026-01-04 09:28:19');

-- --------------------------------------------------------

--
-- Table structure for table `archive`
--

CREATE TABLE `archive` (
  `id` int(11) NOT NULL,
  `document_name` varchar(255) NOT NULL,
  `department` varchar(255) NOT NULL,
  `archived_by_department` varchar(255) DEFAULT NULL,
  `type` varchar(255) NOT NULL,
  `status` varchar(50) NOT NULL,
  `date_archived` date NOT NULL,
  `size` varchar(50) NOT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `file_type_icon` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `archive`
--

INSERT INTO `archive` (`id`, `document_name`, `department`, `type`, `status`, `date_archived`, `size`, `file_path`, `file_type_icon`) VALUES
(1, 'Alem', 'CMO', 'Advisory', 'Archived', '2026-01-04', '1196730', 'uploads/final/final_9092_1767549904_final_document_identity.pdf.enc', 'pdf');

-- --------------------------------------------------------

--
-- Table structure for table `department_archives`
-- Per-department archive isolation: records which departments have archived which tracking docs
--

CREATE TABLE `department_archives` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `tracking_id` int(10) unsigned NOT NULL,
  `department` varchar(255) NOT NULL,
  `archived_at` datetime NOT NULL DEFAULT current_timestamp(),
  `archived_by_user_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tracking_dept` (`tracking_id`,`department`),
  KEY `idx_tracking_id` (`tracking_id`),
  KEY `idx_department` (`department`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `control`
--

CREATE TABLE `control` (
  `id` int(11) NOT NULL,
  `user` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `role` varchar(255) NOT NULL,
  `department` varchar(255) NOT NULL,
  `status` varchar(50) NOT NULL,
  `last_active` date NOT NULL,
  `file_type_icon` varchar(50) NOT NULL,
  `password` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `control`
--

INSERT INTO `control` (`id`, `user`, `email`, `role`, `department`, `status`, `last_active`, `file_type_icon`, `password`) VALUES
(12, 'Cherry', '', 'user', 'CMO', 'active', '0000-00-00', '', '$2y$10$DIwp9u83AVpBy3Rnfs7svuqkkYuciApmKlh4/CnGEdmOIZ3zGfc/S'),
(13, 'Alem', '', 'user', 'CACCO', 'active', '0000-00-00', '', '$2y$10$QueCtGqYU4dtADcSFy8rWOXXYW9mCb.2wT6qi2819lrbHuRPCML/6'),
(14, 'Carl', '', 'user', 'CBO', 'active', '0000-00-00', '', '$2y$10$SnfHaLweuuHCaA0IQlBnNeR9jTAeD7JwkWI0yTLqyN0oHVdCWGlXW'),
(15, 'Allen', '', 'user', 'CPDO', 'active', '0000-00-00', '', '$2y$10$g9A8IwFlYvEPG6ASa8ndp.MyuGlfUSAmXzph1s6C/wb8gzLD3xvPy'),
(16, 'Angel', '', 'user', 'GSO', 'active', '0000-00-00', '', '$2y$10$k4q37vrlmpDuHKNK9zlyv.epAD38m6YXpU/talXFhGM/ULH/oU7He');

-- --------------------------------------------------------

--
-- Table structure for table `dashboard`
--

CREATE TABLE `dashboard` (
  `id` varchar(50) NOT NULL,
  `type` varchar(255) NOT NULL,
  `employee_name` varchar(255) NOT NULL,
  `date_submitted` date NOT NULL,
  `current_holder` varchar(255) NOT NULL,
  `end_location` varchar(255) NOT NULL,
  `status` varchar(50) NOT NULL,
  `department` varchar(255) NOT NULL,
  `file_type_icon` varchar(10) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int(11) NOT NULL,
  `department_name` varchar(255) NOT NULL,
  `head` varchar(255) NOT NULL,
  `employees` int(11) NOT NULL,
  `contact` varchar(255) NOT NULL,
  `location` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `documents`
--

CREATE TABLE `documents` (
  `id` varchar(255) NOT NULL,
  `type` varchar(255) NOT NULL,
  `employee` varchar(255) DEFAULT NULL,
  `date` date NOT NULL,
  `holder` varchar(255) DEFAULT NULL,
  `end_location` varchar(255) DEFAULT NULL,
  `status` varchar(50) NOT NULL,
  `department` varchar(255) DEFAULT NULL,
  `file_type_icon` varchar(50) DEFAULT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_hash` varchar(255) NOT NULL,
  `extracted_text` longtext DEFAULT NULL,
  `ai_categorized_type` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `document_history`
--

CREATE TABLE `document_history` (
  `id` int(11) NOT NULL,
  `doc_id` int(11) NOT NULL,
  `action` varchar(32) NOT NULL,
  `actor_user_id` int(11) DEFAULT NULL,
  `from_status` varchar(100) DEFAULT NULL,
  `to_status` varchar(100) DEFAULT NULL,
  `from_holder` varchar(255) DEFAULT NULL,
  `to_holder` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `document_history`
--

INSERT INTO `document_history` (`id`, `doc_id`, `action`, `actor_user_id`, `from_status`, `to_status`, `from_holder`, `to_holder`, `notes`, `created_at`) VALUES
(1, 40, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-10-27 08:00:41'),
(2, 40, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-10-27 08:00:45'),
(3, 40, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-10-27 08:00:56'),
(4, 40, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-10-27 08:01:10'),
(5, 40, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-10-27 08:01:13'),
(6, 40, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-10-27 08:01:15'),
(7, 40, 'route', 4, 'Archived', 'Completed', 'Allen', 'CADO', NULL, '2025-10-27 08:01:29'),
(8, 40, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-10-27 08:01:32'),
(9, 40, 'route', 4, 'Archived', 'Completed', 'CADO', 'CBO', NULL, '2025-10-27 10:22:36'),
(10, 40, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-10-29 11:29:10'),
(11, 40, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-10-29 11:29:24'),
(12, 67, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-04 12:37:19'),
(13, 68, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-04 12:40:34'),
(14, 74, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-04 14:49:09'),
(15, 74, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-04 14:49:37'),
(16, 74, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-04 14:59:27'),
(17, 74, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-04 14:59:30'),
(18, 74, 'route', 4, 'Archived', 'Completed', 'Alem', 'CACCO', NULL, '2025-11-04 14:59:41'),
(19, 74, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-04 15:13:51'),
(20, 74, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-04 15:14:32'),
(21, 74, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-04 15:14:53'),
(22, 74, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-04 15:38:38'),
(23, 75, 'route', 4, 'Pending', 'Completed', 'Alem', 'CBO', NULL, '2025-11-04 15:38:57'),
(24, 75, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-04 15:38:57'),
(25, 76, 'route', 4, 'Pending', 'Completed', 'Alem', 'CACCO', NULL, '2025-11-04 16:40:28'),
(26, 76, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-04 16:40:28'),
(27, 77, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-04 16:42:20'),
(28, 78, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-04 16:58:50'),
(29, 79, 'route', 4, 'Pending', 'Completed', 'Allen', 'CACCO', NULL, '2025-11-04 17:04:37'),
(30, 79, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-04 17:04:37'),
(31, 81, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-04 17:44:10'),
(32, 92, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-04 18:26:03'),
(33, 98, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-04 18:26:11'),
(34, 106, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-04 18:26:14'),
(35, 107, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-04 18:26:19'),
(36, 97, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-04 18:26:37'),
(37, 101, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-04 18:26:42'),
(38, 117, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-10 23:48:50'),
(39, 114, 'route', 4, 'Pending', 'Completed', 'Allen', 'CBO', NULL, '2025-11-10 23:49:42'),
(40, 114, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-10 23:49:42'),
(41, 118, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-11 10:17:59'),
(42, 120, 'route', 4, 'Pending', 'In Review', 'Allen', 'CADO', NULL, '2025-11-11 10:25:41'),
(43, 119, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-11 12:08:28'),
(44, 145, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-11 12:10:51'),
(45, 146, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-11 12:11:50'),
(46, 147, 'route', 4, 'Pending', 'Pending', 'Default Holder', 'CACCO', NULL, '2025-11-11 12:12:19'),
(47, 147, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-11 12:12:26'),
(48, 148, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-11 12:40:42'),
(49, 149, 'archive', 4, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-11 12:42:37'),
(50, 36, 'archive', 0, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-19 21:57:02'),
(51, 39, 'archive', 0, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-20 15:47:19'),
(52, 9006, 'archive', 0, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-21 16:09:26'),
(53, 9008, 'archive', 0, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2025-11-21 17:11:08'),
(54, 9076, 'receive', 13, 'In Review', 'In Review', 'CACCO', 'CACCO', NULL, '2025-12-29 17:01:29'),
(55, 9076, 'receive', 13, 'In Review', 'In Review', 'CACCO', 'CACCO', NULL, '2025-12-29 23:17:37'),
(56, 9076, 'route', 13, 'In Review', 'Pending', 'CACCO', 'CBO', NULL, '2025-12-29 23:17:41'),
(57, 9076, 'receive', 14, 'Pending', 'In Review', 'CBO', 'CBO', NULL, '2025-12-29 23:18:07'),
(58, 9077, 'receive', 13, 'Pending', 'In Review', 'CACCO', 'CACCO', NULL, '2025-12-29 23:39:14'),
(59, 9077, 'route', 13, 'In Review', 'Pending', 'CACCO', 'CBO', NULL, '2025-12-29 23:39:44'),
(60, 9077, 'receive', 14, 'Pending', 'In Review', 'CBO', 'CBO', NULL, '2025-12-29 23:40:20'),
(61, 9077, 'route', 14, 'In Review', 'Pending', 'CBO', 'CPDO', NULL, '2025-12-29 23:43:50'),
(62, 9077, 'receive', 15, 'Pending', 'In Review', 'CPDO', 'CPDO', NULL, '2025-12-29 23:44:23'),
(63, 9077, 'route', 15, 'In Review', 'Pending', 'CPDO', 'GSO', NULL, '2025-12-29 23:45:06'),
(64, 9077, 'receive', 16, 'Pending', 'In Review', 'GSO', 'GSO', NULL, '2025-12-29 23:45:30'),
(65, 9078, 'receive', 16, 'Pending', 'In Review', 'GSO', 'GSO', NULL, '2025-12-31 19:14:51'),
(66, 9078, 'receive', 16, 'In Review', 'In Review', 'GSO', 'GSO', NULL, '2026-01-01 15:06:52'),
(67, 9079, 'receive', 13, 'Pending', 'In Review', 'CACCO', 'CACCO', NULL, '2026-01-01 15:09:41'),
(68, 9079, 'route', 13, 'In Review', 'Pending', 'CACCO', 'GSO', NULL, '2026-01-01 15:10:00'),
(69, 9079, 'receive', 16, 'Pending', 'In Review', 'GSO', 'GSO', NULL, '2026-01-01 15:10:26'),
(70, 9079, 'file_update', 0, NULL, NULL, NULL, NULL, 'Final document captured', '2026-01-01 15:11:04'),
(71, 9079, 'receive', 16, 'Ready for Archive', 'In Review', 'GSO', 'GSO', NULL, '2026-01-01 15:33:38'),
(72, 9079, 'receive', 16, 'In Review', 'In Review', 'GSO', 'GSO', NULL, '2026-01-01 16:11:37'),
(73, 9079, 'complete', 0, NULL, NULL, NULL, NULL, 'Final document captured and marked Completed', '2026-01-01 16:15:05'),
(74, 9079, 'archive', 0, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2026-01-01 16:16:40'),
(75, 9079, 'receive', 0, '', 'In Review', '', '', NULL, '2026-01-01 16:54:41'),
(76, 9080, 'receive', 13, 'Pending', 'In Review', 'CACCO', 'CACCO', NULL, '2026-01-01 16:57:53'),
(77, 9080, 'route', 13, 'In Review', 'Pending', 'CACCO', 'GSO', NULL, '2026-01-01 16:58:03'),
(78, 9080, 'receive', 16, 'Pending', 'In Review', 'GSO', 'GSO', NULL, '2026-01-01 16:58:29'),
(79, 9080, 'receive', 16, 'In Review', 'In Review', 'GSO', 'GSO', NULL, '2026-01-02 00:03:47'),
(80, 9080, 'receive', 16, 'In Review', 'In Review', 'GSO', 'GSO', NULL, '2026-01-02 00:04:41'),
(81, 9080, 'receive', 16, 'In Review', 'In Review', 'GSO', 'GSO', NULL, '2026-01-02 01:20:58'),
(82, 9080, 'complete', 0, NULL, NULL, NULL, NULL, 'Final document captured and marked Completed', '2026-01-02 01:28:30'),
(83, 9080, 'archive', 0, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2026-01-02 01:29:28'),
(84, 9081, 'receive', 13, 'Pending', 'In Review', 'CACCO', 'CACCO', NULL, '2026-01-02 13:37:24'),
(85, 9081, 'route', 13, 'In Review', 'Pending', 'CACCO', 'GSO', NULL, '2026-01-02 13:37:47'),
(86, 9081, 'receive', 16, 'Pending', 'In Review', 'GSO', 'GSO', NULL, '2026-01-02 13:38:14'),
(87, 9081, 'complete', 0, NULL, NULL, NULL, NULL, 'Final document captured and marked Completed', '2026-01-02 13:38:51'),
(88, 9083, 'create', NULL, NULL, 'Pending', NULL, 'CACCO', NULL, '2026-01-03 00:08:42'),
(89, 9084, 'create', NULL, NULL, 'Pending', NULL, 'CMO', NULL, '2026-01-03 00:22:40'),
(90, 9084, 'receive', 12, 'Pending', 'In Review', 'CMO', 'CMO', NULL, '2026-01-03 00:22:59'),
(91, 9084, 'route', 12, 'In Review', 'Pending', 'CMO', 'GSO', NULL, '2026-01-03 00:23:14'),
(92, 9084, 'receive', 16, 'Pending', 'In Review', 'GSO', 'GSO', NULL, '2026-01-03 00:23:34'),
(93, 9084, 'complete', 0, NULL, NULL, NULL, NULL, 'Final document captured and marked Completed', '2026-01-03 00:24:08'),
(94, 9084, 'archive', 0, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2026-01-03 00:24:33'),
(95, 9085, 'create', NULL, NULL, 'Pending', NULL, 'CPDO', NULL, '2026-01-03 11:36:53'),
(96, 9086, 'create', NULL, NULL, 'Pending', NULL, 'CACCO', NULL, '2026-01-04 00:27:59'),
(97, 9086, 'receive', 13, 'Pending', 'In Review', 'CACCO', 'CACCO', NULL, '2026-01-04 00:29:30'),
(98, 9086, 'route', 13, 'In Review', 'Pending', 'CACCO', 'CPDO', NULL, '2026-01-04 00:29:55'),
(99, 9086, 'receive', 15, 'Pending', 'In Review', 'CPDO', 'CPDO', NULL, '2026-01-04 00:30:16'),
(100, 9086, 'complete', 0, NULL, NULL, NULL, NULL, 'Final document captured and marked Completed', '2026-01-04 00:30:58'),
(101, 9087, 'create', NULL, NULL, 'Pending', NULL, 'GSO', NULL, '2026-01-04 15:40:55'),
(102, 9087, 'receive', 16, 'Pending', 'In Review', 'GSO', 'GSO', NULL, '2026-01-04 15:41:35'),
(103, 9087, 'receive', 16, 'In Review', 'In Review', 'GSO', 'GSO', NULL, '2026-01-04 16:28:30'),
(104, 9088, 'create', NULL, NULL, 'Pending', NULL, 'CMO', NULL, '2026-01-04 16:30:03'),
(105, 9088, 'receive', 12, 'Pending', 'In Review', 'CMO', 'CMO', NULL, '2026-01-04 16:30:42'),
(106, 9089, 'create', NULL, NULL, 'Pending', NULL, 'GSO', NULL, '2026-01-04 17:05:38'),
(107, 9089, 'receive', 16, 'Pending', 'In Review', 'GSO', 'GSO', NULL, '2026-01-04 17:06:12'),
(108, 9090, 'create', NULL, NULL, 'Pending', NULL, 'GSO', NULL, '2026-01-04 23:33:01'),
(109, 9090, 'receive', 16, 'Pending', 'In Review', 'GSO', 'GSO', NULL, '2026-01-04 23:33:43'),
(110, 9090, 'receive', 16, 'In Review', 'In Review', 'GSO', 'GSO', NULL, '2026-01-04 23:50:24'),
(111, 9090, 'receive', 16, 'In Review', 'In Review', 'GSO', 'GSO', NULL, '2026-01-05 00:02:58'),
(112, 9091, 'create', NULL, NULL, 'Pending', NULL, 'CACCO', NULL, '2026-01-05 00:43:22'),
(113, 9091, 'receive', 13, 'Pending', 'In Review', 'CACCO', 'CACCO', NULL, '2026-01-05 01:10:32'),
(114, 9092, 'create', NULL, NULL, 'Pending', NULL, 'CMO', NULL, '2026-01-05 01:12:43'),
(115, 9092, 'receive', 12, 'Pending', 'In Review', 'CMO', 'CMO', NULL, '2026-01-05 01:13:18'),
(116, 9092, 'receive', 12, 'In Review', 'In Review', 'CMO', 'CMO', NULL, '2026-01-05 01:35:39'),
(117, 9092, 'complete', 0, NULL, NULL, NULL, NULL, 'Final document captured and marked Completed', '2026-01-05 02:05:04'),
(118, 9092, 'archive', 0, NULL, 'Archived', NULL, 'Digital Archive', NULL, '2026-01-05 02:05:34');

-- --------------------------------------------------------

--
-- Table structure for table `email_oauth_tokens`
--

CREATE TABLE `email_oauth_tokens` (
  `id` int(11) NOT NULL,
  `provider` varchar(32) NOT NULL,
  `admin_email` varchar(255) NOT NULL,
  `access_token` text NOT NULL,
  `refresh_token` text NOT NULL,
  `expires_at` int(11) NOT NULL,
  `scope` text DEFAULT NULL,
  `token_type` varchar(32) DEFAULT NULL,
  `created_at` int(11) NOT NULL DEFAULT 0,
  `updated_at` int(11) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fcm_tokens`
--

CREATE TABLE `fcm_tokens` (
  `id` int(11) NOT NULL,
  `username` varchar(128) NOT NULL,
  `department` varchar(128) DEFAULT NULL,
  `token` text NOT NULL,
  `platform` varchar(16) DEFAULT 'android',
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `fcm_tokens`
--

INSERT INTO `fcm_tokens` (`id`, `username`, `department`, `token`, `platform`, `updated_at`, `created_at`) VALUES
(1, 'Alem', 'CACCO', 'fwjBCzwWRaWIZmNNnIKPqA:APA91bGRfPJH0QwXrDUJGCDv412iUflOiB8lkcWFSN_YC89rT4X_hYqWs4zUWA73f7XBCJWvG0qKSXo7KVUvqNqK93_A8tjMh1MSpqor3xmhSfrgG7vWITw', 'android', '2025-11-03 02:46:06', '2025-10-29 13:45:05'),
(2, 'Allen', 'CPDO', 'fwjBCzwWRaWIZmNNnIKPqA:APA91bGRfPJH0QwXrDUJGCDv412iUflOiB8lkcWFSN_YC89rT4X_hYqWs4zUWA73f7XBCJWvG0qKSXo7KVUvqNqK93_A8tjMh1MSpqor3xmhSfrgG7vWITw', 'android', '2025-11-03 04:40:49', '2025-10-29 14:03:45'),
(3, 'carl', 'CACCO', 'c3qJQpRcQTSHX7JzHJcCjF:APA91bHEk-LgEsD69q635Sh92Jiq6aF4sbWY_PwwzO5abpamOIqUFJzpicxVSini_L0NLxzja-zEV2ZbXLbQ1kGUaicSx0RY19x9nhe9QVZn6mzQh0i-q70', 'android', '2025-10-31 19:10:51', '2025-10-31 19:10:51');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `content` text DEFAULT NULL,
  `type` varchar(64) DEFAULT 'mobile_message',
  `recipient_username` varchar(128) NOT NULL,
  `sender_username` varchar(128) DEFAULT NULL,
  `department` varchar(128) DEFAULT NULL,
  `recipient_department` varchar(128) DEFAULT NULL,
  `status` varchar(32) DEFAULT 'new',
  `file_url` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `meta` text DEFAULT NULL,
  `tracking_id` int(11) DEFAULT NULL,
  `mobile_timestamp` varchar(128) DEFAULT NULL,
  `end_location` varchar(128) DEFAULT NULL,
  `current_holder` varchar(128) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications_read`
--

CREATE TABLE `notifications_read` (
  `user_id` varchar(191) NOT NULL,
  `tracking_id` int(11) NOT NULL,
  `read_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `password_resets`
--

INSERT INTO `password_resets` (`id`, `user_id`, `email`, `token_hash`, `expires_at`, `used_at`, `ip_address`, `user_agent`, `created_at`) VALUES
(8, 3, '', '6c0ed9e57f2ed0b36a86fa8b5d398aa97616846254f7d8d6d00e04df06ca06d8', '2025-10-14 16:02:30', '2025-10-14 15:51:32', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '2025-10-14 15:47:30'),
(9, 3, '', '41900da633e94d4544544100ab3ed9dbcad2fe51dec8e8368aded7d89234faeb', '2025-10-14 16:17:09', '2025-10-14 16:02:51', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '2025-10-14 16:02:09'),
(10, 3, '', 'eebc3f56c657525852b5b26b084d48148e973b1e66f347c50202a0d8ed44b43e', '2025-10-15 01:21:45', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '2025-10-15 01:06:45'),
(11, 4, '', 'b1f583776f600e052d618f57c72fa6a7302d88ed532051409f8ffaaa79a6541b', '2025-10-27 09:59:49', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '2025-10-27 09:44:49'),
(12, 4, '', 'f30a313250c275ec6c3488f178d57f25ffa6544281ee15587ca6a316b28ea499', '2025-10-27 10:23:20', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '2025-10-27 10:08:20'),
(13, 3, '', '4e773d1654b4b8caa2ddfdf0c12bdc35bac37a16684fcc58f419eb3bcbf41ee5', '2025-10-27 10:27:02', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '2025-10-27 10:12:02'),
(14, 3, '', '27edd334def688f8a5da62b85ed97c19942d9314900b11cea4e396d654da272c', '2025-10-27 10:29:04', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '2025-10-27 10:14:04'),
(15, 3, '', '3bfed764a05d045e58cac7174647792e568a80943af2e75b751e9dc6eeec13be', '2025-10-27 10:39:52', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '2025-10-27 10:24:52'),
(16, 4, '', '3198ffd2857558355c97ecb0e1b0dc58950e144127325e81e8cb8fb41b2610b4', '2025-10-27 10:40:29', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '2025-10-27 10:25:29'),
(17, 3, '', 'd776cf3d769972bb05c1817ea034cb268bdb33df5c3ff25c05f8b6285d47f035', '2025-10-27 10:42:18', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '2025-10-27 10:27:18'),
(18, 3, '', '32a9c5582a1a86a548a8f56189ccba80212453a3dd33041d2fbfb90118746403', '2025-10-27 11:10:04', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '2025-10-27 10:55:04'),
(19, 4, '', 'c30dfe5fe682984fa9f168f0562fd303dabca989e0779bcba009da19efadd438', '2025-11-01 04:22:09', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '2025-11-01 04:07:09'),
(20, 4, '', '4ce155831f937dc3de0b54fc376eb06a8869bc73fcfe9dab8726c03fedb88d3b', '2025-11-04 15:35:02', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '2025-11-04 15:20:02'),
(21, 4, '', 'b5d679ed3758be519ad92ae507e13a59508f5a5fb1b4a66a4c718fa9aaf7947b', '2025-11-04 15:41:22', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '2025-11-04 15:26:22'),
(22, 4, '', '2b50afd554d2b5647c91dbeaa972d1d2fe5387ea195b671fb56371a44c967e20', '2025-11-04 16:18:23', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '2025-11-04 16:03:23'),
(23, 4, '', '5a49787cd60ba0edce56d060ac44b6e637aac1a802d3c9649b38ea6e342251d0', '2025-11-04 16:48:47', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '2025-11-04 16:33:47'),
(24, 4, '', '1168d35fa1393c700eec7f9197c929092ca5b672107dd9ea791f144218d432a7', '2025-11-04 16:51:12', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '2025-11-04 16:36:12'),
(25, 4, '', '78571e17307d5a69ad8ca2d36e62458840bcbb927f99cdd2400530f0580f0a45', '2025-11-04 16:53:51', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '2025-11-04 16:38:51'),
(26, 4, '', 'b5f0ee9cbbfd304219a95f11c260220347f9a75b93162007738f1d56966542e6', '2025-11-04 17:09:24', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '2025-11-04 16:54:24'),
(27, 4, '', 'f789baa1e3a1e5e23abb0d6b33e148a50a27c9c97d670166339c0c4c25b64706', '2025-11-04 17:43:24', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '2025-11-04 17:28:24'),
(28, 4, '', '7a258ad369d2ee26e520477f451da9f5004db5ef560869c00572fc5507f0edfe', '2025-11-04 17:46:10', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '2025-11-04 17:31:10'),
(29, 4, '', '843a244c0ec12581685b33b169e10957da89c19f7405314eb9fa29a42cbac694', '2025-11-04 17:52:08', NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '2025-11-04 17:37:08');

-- --------------------------------------------------------

--
-- Table structure for table `password_resets_mobile`
--

CREATE TABLE `password_resets_mobile` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `username` varchar(128) DEFAULT NULL,
  `email` varchar(255) NOT NULL,
  `code` varchar(12) NOT NULL,
  `created_at` int(11) NOT NULL,
  `expires_at` int(11) NOT NULL,
  `used` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `password_resets_mobile`
--

INSERT INTO `password_resets_mobile` (`id`, `user_id`, `username`, `email`, `code`, `created_at`, `expires_at`, `used`) VALUES
(4, 8, 'Alem', 'saquinalemcris@gmail.com', '355331', 1761748487, 1761749087, 0),
(11, 7, 'Allen', 'allen22@gmail.com', '463185', 1762092649, 1762093249, 0),
(12, 7, 'Allen', 'saquinalemcris16@gmail.com', '369482', 1762092804, 1762093404, 0),
(13, 7, 'Allen', 'saquinalemcris16@gmail.com', '296673', 1762092957, 1762093557, 0),
(14, 7, 'Allen', 'saquinalemcris16@gmail.com', '461635', 1762093061, 1762093661, 0),
(15, 7, 'Allen', 'saquinalemcris16@gmail.com', '214995', 1762093084, 1762093684, 0),
(16, 7, 'Allen', 'saquinalemcris16@gmail.com', '027058', 1762093193, 1762093793, 0),
(17, 7, 'Allen', 'saquinalemcris16@gmail.com', '302551', 1762093457, 1762094057, 0),
(18, 7, 'Allen', 'saquinalemcris16@gmail.com', '425162', 1762094205, 1762094805, 0),
(19, 7, 'Allen', 'saquinalemcris16@gmail.com', '817789', 1762094247, 1762094847, 0),
(20, 7, 'Allen', 'saquinalemcris16@gmail.com', '530568', 1762094523, 1762095123, 0),
(21, 7, 'Allen', 'saquinalemcris16@gmail.com', '672991', 1762094793, 1762095393, 0),
(22, 7, 'Allen', 'saquinalemcris16@gmail.com', '082068', 1762094963, 1762095563, 0),
(23, 7, 'Allen', 'saquinalemcris16@gmail.com', '846361', 1762095116, 1762095716, 0),
(24, 7, 'Allen', 'saquinalemcris16@gmail.com', '652989', 1762095247, 1762095847, 0),
(25, 7, 'Allen', 'saquinalemcris16@gmail.com', '613093', 1762096881, 1762097481, 0),
(26, 7, 'Allen', 'saquinalemcris16@gmail.com', '606707', 1762097687, 1762098287, 0),
(27, 7, 'Allen', 'saquinalemcris16@gmail.com', '596105', 1762097806, 1762098406, 0),
(28, 7, 'Allen', 'saquinalemcris16@gmail.com', '526255', 1762098119, 1762098719, 0),
(29, 7, 'Allen', 'saquinalemcris16@gmail.com', '210618', 1762098920, 1762099520, 0),
(30, 7, 'Allen', 'saquinalemcris16@gmail.com', '307773', 1762099435, 1762100035, 0),
(31, 7, 'Allen', 'saquinalemcris16@gmail.com', '242364', 1762099477, 1762100077, 0),
(32, 7, 'Allen', 'saquinalemcris16@gmail.com', '428364', 1762099571, 1762100171, 0),
(33, 7, 'Allen', 'saquinalemcris16@gmail.com', '697122', 1762568990, 1762569590, 0),
(34, 8, 'Alem', 'saquinalemcris16@gmail.com', '199113', 1762569807, 1762570407, 0),
(35, 8, 'Alem', 'saquinalemcris16@gmail.com', '939990', 1762570589, 1762571189, 0),
(36, 8, 'Alem', 'saquinalemcris16@gmail.com', '230042', 1762570619, 1762571219, 0),
(37, 8, 'Alem', 'saquinalemcris16@gmail.com', '956566', 1762570807, 1762571407, 0),
(38, 8, 'Alem', 'saquinalemcris16@gmail.com', '916032', 1762571089, 1762571689, 0),
(39, 8, 'Alem', 'saquinalemcris16@gmail.com', '914950', 1762571533, 1762572133, 0),
(40, 8, 'Alem', 'saquinalemcris16@gmail.com', '087733', 1762571648, 1762572248, 0),
(41, 8, 'Alem', 'saquinalemcris16@gmail.com', '586763', 1762571755, 1762572355, 0),
(42, 8, 'Alem', 'saquinalemcris16@gmail.com', '222452', 1762571784, 1762572384, 0),
(43, 8, 'Alem', 'saquinalemcris16@gmail.com', '908507', 1762572023, 1762572623, 0),
(44, 8, 'Alem', 'saquinalemcris16@gmail.com', '921052', 1762572354, 1762572954, 0),
(45, 8, 'Alem', 'saquinalemcris16@gmail.com', '917504', 1762572721, 1762573321, 0),
(46, 8, 'Alem', 'saquinalemcris16@gmail.com', '120257', 1762572958, 1762573558, 0),
(47, 8, 'Alem', 'saquinalemcris16@gmail.com', '376867', 1762573349, 1762573949, 0),
(48, 7, 'Allen', 'saquinalemcris16@gmail.com', '460234', 1762573445, 1762574045, 0),
(49, 8, 'Alem', 'saquinalemcris16@gmail.com', '121274', 1762573521, 1762574121, 0),
(50, 8, 'Alem', 'saquinalemcris16@gmail.com', '665414', 1762573697, 1762574297, 0),
(51, 11, 'Allen', '', '725382', 1762863826, 1762864426, 0),
(0, 8, 'Alem', '', '733125', 1763022269, 1763022869, 0),
(0, 1, 'Allen', 'allen@example.com', '800956', 1763204939, 1763205539, 0);

-- --------------------------------------------------------

--
-- Table structure for table `predictions_cache`
--

CREATE TABLE `predictions_cache` (
  `id` int(10) UNSIGNED NOT NULL,
  `metric` varchar(64) NOT NULL,
  `forecast_date` date NOT NULL,
  `forecast_value` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `predictions_cache`
--

INSERT INTO `predictions_cache` (`id`, `metric`, `forecast_date`, `forecast_value`, `created_at`) VALUES
(1, 'documents_per_day_both', '2025-01-11', 9.50, '2025-11-21 07:53:32'),
(2, 'documents_per_day_both', '2025-01-12', 10.10, '2025-11-21 07:53:32'),
(3, 'documents_per_day_both', '2025-01-13', 11.00, '2025-11-21 07:53:32'),
(4, 'documents_per_day_both', '2025-01-14', 9.80, '2025-11-21 07:53:32'),
(5, 'documents_per_day_both', '2025-01-15', 10.60, '2025-11-21 07:53:32'),
(6, 'documents_per_day_both', '2025-01-16', 11.20, '2025-11-21 07:53:32'),
(7, 'documents_per_day_both', '2025-01-17', 12.00, '2025-11-21 07:53:32');

-- --------------------------------------------------------

--
-- Table structure for table `rate_limits`
--

CREATE TABLE `rate_limits` (
  `id` int(11) NOT NULL,
  `identifier` varchar(255) NOT NULL,
  `action` varchar(50) NOT NULL,
  `attempts` int(11) DEFAULT 1,
  `window_start` datetime NOT NULL,
  `last_attempt` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rate_limits`
--

INSERT INTO `rate_limits` (`id`, `identifier`, `action`, `attempts`, `window_start`, `last_attempt`) VALUES
(1, '::1', 'forgot_password', 1, '0000-00-00 00:00:00', '2025-10-13 20:58:39'),
(2, 'alankiller207@gmail.com', 'forgot_password_cooldown', 1, '0000-00-00 00:00:00', '2025-10-13 20:58:39'),
(3, '::1', 'forgot_password', 1, '0000-00-00 00:00:00', '2025-10-13 20:58:41'),
(4, '::1', 'forgot_password', 1, '0000-00-00 00:00:00', '2025-10-13 20:58:42'),
(5, '::1', 'forgot_password', 1, '0000-00-00 00:00:00', '2025-10-13 23:56:48'),
(6, '::1', 'forgot_password', 1, '0000-00-00 00:00:00', '2025-10-13 23:57:00'),
(7, '::1', 'forgot_password', 1, '0000-00-00 00:00:00', '2025-10-13 23:57:20'),
(8, '::1', 'forgot_password', 1, '0000-00-00 00:00:00', '2025-10-13 23:57:45'),
(9, '::1', 'forgot_password', 1, '0000-00-00 00:00:00', '2025-10-13 23:57:50'),
(10, '::1', 'forgot_password', 1, '0000-00-00 00:00:00', '2025-10-14 13:42:38'),
(11, '::1', 'forgot_password', 1, '0000-00-00 00:00:00', '2025-10-14 13:42:50');

-- --------------------------------------------------------

--
-- Table structure for table `security_logs`
--

CREATE TABLE `security_logs` (
  `id` int(11) NOT NULL,
  `event_type` varchar(50) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `security_logs`
--

INSERT INTO `security_logs` (`id`, `event_type`, `user_id`, `email`, `ip_address`, `user_agent`, `details`, `created_at`) VALUES
(1, 'rate_limit_exceeded', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"identifier\":\"::1\",\"action\":\"forgot_password\",\"attempts\":\"3\"}', '2025-10-13 23:06:19'),
(2, 'forgot_password_rate_limited', NULL, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"ip\":\"::1\"}', '2025-10-13 23:06:19'),
(3, 'rate_limit_exceeded', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"identifier\":\"::1\",\"action\":\"forgot_password\",\"attempts\":\"3\"}', '2025-10-13 23:06:21'),
(4, 'forgot_password_rate_limited', NULL, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"ip\":\"::1\"}', '2025-10-13 23:06:21'),
(5, 'rate_limit_exceeded', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"identifier\":\"::1\",\"action\":\"forgot_password\",\"attempts\":\"3\"}', '2025-10-13 23:06:21'),
(6, 'forgot_password_rate_limited', NULL, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"ip\":\"::1\"}', '2025-10-13 23:06:21'),
(7, 'rate_limit_exceeded', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"identifier\":\"::1\",\"action\":\"forgot_password\",\"attempts\":\"3\"}', '2025-10-13 23:06:22'),
(8, 'forgot_password_rate_limited', NULL, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"ip\":\"::1\"}', '2025-10-13 23:06:22'),
(9, 'rate_limit_exceeded', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"identifier\":\"::1\",\"action\":\"forgot_password\",\"attempts\":\"3\"}', '2025-10-13 23:06:22'),
(10, 'forgot_password_rate_limited', NULL, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"ip\":\"::1\"}', '2025-10-13 23:06:22'),
(11, 'rate_limit_exceeded', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"identifier\":\"::1\",\"action\":\"forgot_password\",\"attempts\":\"3\"}', '2025-10-13 23:06:22'),
(12, 'forgot_password_rate_limited', NULL, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"ip\":\"::1\"}', '2025-10-13 23:06:22'),
(13, 'rate_limit_exceeded', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"identifier\":\"::1\",\"action\":\"forgot_password\",\"attempts\":\"3\"}', '2025-10-13 23:06:24'),
(14, 'forgot_password_rate_limited', NULL, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"ip\":\"::1\"}', '2025-10-13 23:06:24'),
(15, 'rate_limit_exceeded', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"identifier\":\"::1\",\"action\":\"forgot_password\",\"attempts\":\"3\"}', '2025-10-13 23:06:24'),
(16, 'forgot_password_rate_limited', NULL, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"ip\":\"::1\"}', '2025-10-13 23:06:24'),
(17, 'rate_limit_exceeded', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"identifier\":\"::1\",\"action\":\"forgot_password\",\"attempts\":\"3\"}', '2025-10-13 23:06:24'),
(18, 'forgot_password_rate_limited', NULL, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"ip\":\"::1\"}', '2025-10-13 23:06:24'),
(19, 'rate_limit_exceeded', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"identifier\":\"::1\",\"action\":\"forgot_password\",\"attempts\":\"3\"}', '2025-10-13 23:07:07'),
(20, 'forgot_password_rate_limited', NULL, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"ip\":\"::1\"}', '2025-10-13 23:07:07'),
(21, 'rate_limit_exceeded', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"identifier\":\"::1\",\"action\":\"forgot_password\",\"attempts\":\"3\"}', '2025-10-13 23:07:13'),
(22, 'forgot_password_rate_limited', NULL, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"ip\":\"::1\"}', '2025-10-13 23:07:13'),
(23, 'rate_limit_exceeded', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"identifier\":\"::1\",\"action\":\"forgot_password\",\"attempts\":\"3\"}', '2025-10-13 23:35:29'),
(24, 'forgot_password_rate_limited', NULL, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"ip\":\"::1\"}', '2025-10-13 23:35:29'),
(25, 'rate_limit_exceeded', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"identifier\":\"::1\",\"action\":\"forgot_password\",\"attempts\":\"3\"}', '2025-10-13 23:48:55'),
(26, 'forgot_password_rate_limited', NULL, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"ip\":\"::1\"}', '2025-10-13 23:48:55'),
(27, 'rate_limit_exceeded', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"identifier\":\"::1\",\"action\":\"forgot_password\",\"attempts\":\"3\"}', '2025-10-13 23:50:42'),
(28, 'forgot_password_rate_limited', NULL, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"ip\":\"::1\"}', '2025-10-13 23:50:42'),
(29, 'rate_limit_exceeded', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"identifier\":\"::1\",\"action\":\"forgot_password\",\"attempts\":\"3\"}', '2025-10-13 23:55:21'),
(30, 'forgot_password_rate_limited', NULL, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"ip\":\"::1\"}', '2025-10-13 23:55:21'),
(31, 'rate_limit_exceeded', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"identifier\":\"alankiller207@gmail.com\",\"action\":\"forgot_password_cooldown\",\"attempts\":\"1\"}', '2025-10-13 23:56:48'),
(32, 'rate_limit_exceeded', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"identifier\":\"alankiller207@gmail.com\",\"action\":\"forgot_password_cooldown\",\"attempts\":\"1\"}', '2025-10-13 23:57:00'),
(33, 'rate_limit_exceeded', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"identifier\":\"alankiller207@gmail.com\",\"action\":\"forgot_password_cooldown\",\"attempts\":\"1\"}', '2025-10-13 23:57:20'),
(34, 'rate_limit_exceeded', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"identifier\":\"alankiller207@gmail.com\",\"action\":\"forgot_password_cooldown\",\"attempts\":\"1\"}', '2025-10-13 23:57:45'),
(35, 'rate_limit_exceeded', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"identifier\":\"alankiller207@gmail.com\",\"action\":\"forgot_password_cooldown\",\"attempts\":\"1\"}', '2025-10-13 23:57:50'),
(36, 'rate_limit_exceeded', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"identifier\":\"alankiller207@gmail.com\",\"action\":\"forgot_password_cooldown\",\"attempts\":\"1\"}', '2025-10-14 13:42:38'),
(37, 'rate_limit_exceeded', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"identifier\":\"alankiller207@gmail.com\",\"action\":\"forgot_password_cooldown\",\"attempts\":\"1\"}', '2025-10-14 13:42:50'),
(38, 'rate_limit_exceeded', NULL, NULL, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"identifier\":\"::1\",\"action\":\"forgot_password\",\"attempts\":\"10\"}', '2025-10-14 13:44:05'),
(39, 'forgot_password_rate_limited', NULL, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '{\"ip\":\"::1\"}', '2025-10-14 13:44:05'),
(40, 'password_reset_requested', 3, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '[]', '2025-10-14 13:51:46'),
(41, 'password_reset_requested', 3, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '[]', '2025-10-14 14:06:47'),
(42, 'password_reset_requested', 3, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '[]', '2025-10-14 14:46:16'),
(43, 'password_reset_requested', 3, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '[]', '2025-10-14 14:52:33'),
(44, 'password_reset_requested', 3, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '[]', '2025-10-14 15:17:06'),
(45, 'password_reset_requested', 3, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '[]', '2025-10-14 15:23:32'),
(46, 'password_reset_requested', 3, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '[]', '2025-10-14 15:30:34'),
(47, 'password_reset_requested', 3, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '[]', '2025-10-14 15:47:34'),
(48, 'password_reset_success', 3, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '[]', '2025-10-14 15:51:36'),
(49, 'password_reset_requested', 3, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '[]', '2025-10-14 16:02:12'),
(50, 'password_reset_success', 3, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '[]', '2025-10-14 16:02:54'),
(51, 'password_reset_requested', 3, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', '[]', '2025-10-15 01:06:50'),
(52, 'password_reset_requested', 4, 'saquinalemcris16@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '[]', '2025-10-27 09:44:51'),
(53, 'password_reset_requested', 4, 'saquinalemcris16@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '[]', '2025-10-27 10:08:22'),
(54, 'password_reset_requested', 3, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '[]', '2025-10-27 10:12:04'),
(55, 'password_reset_requested', 3, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '[]', '2025-10-27 10:14:06'),
(56, 'password_reset_requested', 3, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '[]', '2025-10-27 10:24:54'),
(57, 'password_reset_requested', 4, 'saquinalemcris16@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '[]', '2025-10-27 10:25:31'),
(58, 'password_reset_requested', 3, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '[]', '2025-10-27 10:27:20'),
(59, 'password_reset_requested', 3, 'alankiller207@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '[]', '2025-10-27 10:55:06'),
(60, 'password_reset_requested', 4, 'saquinalemcris16@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '[]', '2025-11-01 04:07:11'),
(61, 'password_reset_requested', 4, 'saquinalemcris16@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '[]', '2025-11-04 15:20:04'),
(62, 'password_reset_requested', 4, 'saquinalemcris16@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '[]', '2025-11-04 15:26:24'),
(63, 'password_reset_requested', 4, 'saquinalemcris16@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '[]', '2025-11-04 16:03:26'),
(64, 'password_reset_requested', 4, 'saquinalemcris16@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '[]', '2025-11-04 16:33:49'),
(65, 'password_reset_requested', 4, 'saquinalemcris16@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '[]', '2025-11-04 16:36:14'),
(66, 'password_reset_requested', 4, 'saquinalemcris16@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '[]', '2025-11-04 16:38:53'),
(67, 'password_reset_requested', 4, 'saquinalemcris16@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '[]', '2025-11-04 16:54:26'),
(68, 'password_reset_requested', 4, 'saquinalemcris16@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '[]', '2025-11-04 17:28:26'),
(69, 'password_reset_requested', 4, 'saquinalemcris@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '[]', '2025-11-04 17:31:12'),
(70, 'password_reset_requested', 4, 'saquinalemcris@gmail.com', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0', '[]', '2025-11-04 17:37:10'),
(0, 'password_reset_success', 0, '', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36 Edg/142.0.0.0', '[]', '2025-11-19 01:01:38'),
(0, 'password_reset_success', 0, '', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/143.0.0.0 Safari/537.36', '[]', '2025-12-24 01:13:01');

-- --------------------------------------------------------

--
-- Table structure for table `share_feed`
--

CREATE TABLE `share_feed` (
  `id` int(11) NOT NULL,
  `username` varchar(128) NOT NULL,
  `department` varchar(128) DEFAULT NULL,
  `content` text NOT NULL,
  `created_at` int(11) NOT NULL,
  `pinned` tinyint(1) NOT NULL DEFAULT 0,
  `reactions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`reactions`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `share_feed`
--

INSERT INTO `share_feed` (`id`, `username`, `department`, `content`, `created_at`, `pinned`, `reactions`) VALUES
(1, 'Allen', 'CPDO', 'jdhshahshs', 1761710816, 0, NULL),
(2, 'Allen', 'CPDO', 'bbsshjsbshs', 1761710817, 0, NULL),
(3, 'Allen', 'CPDO', 'bdbsbsvbs', 1761710818, 0, NULL),
(4, 'Alem', 'CACCO', 'shhshahhaha', 1761710845, 0, NULL),
(5, 'Alem', 'CACCO', 'bdbsjhshs', 1761710846, 0, NULL),
(6, 'Alem', 'CACCO', 'bsjshgshs', 1761710847, 0, NULL),
(7, 'Allen', 'CPDO', 'hshshhahs', 1761711025, 0, NULL),
(8, 'Alem', 'CACCO', 'geetgyif', 1761711442, 0, NULL),
(9, 'cherry', 'CTO', 'hshgahahajaja', 1761717999, 0, NULL),
(10, 'cherry', 'CTO', 'bdbshahhaha', 1761718006, 0, NULL),
(11, 'Allen', 'CPDO', 'hshsgaha', 1761718054, 0, NULL),
(12, 'carl', 'CACCO', 'hahshsgs', 1761718079, 0, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `share_reactions`
--

CREATE TABLE `share_reactions` (
  `id` int(11) NOT NULL,
  `share_id` int(11) NOT NULL,
  `username` varchar(128) NOT NULL,
  `created_at` int(11) NOT NULL,
  `type` varchar(16) NOT NULL DEFAULT 'like'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sla_predictions`
--

CREATE TABLE `sla_predictions` (
  `id` int(10) UNSIGNED NOT NULL,
  `document_id` varchar(255) NOT NULL,
  `document_type` varchar(255) NOT NULL,
  `department` varchar(255) DEFAULT NULL,
  `predicted_total_days` decimal(10,2) NOT NULL,
  `elapsed_days` decimal(10,2) NOT NULL,
  `sla_days` int(11) NOT NULL,
  `risk_score` decimal(5,4) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stats`
--

CREATE TABLE `stats` (
  `id` int(11) NOT NULL,
  `document` varchar(255) NOT NULL,
  `department` varchar(255) NOT NULL,
  `status` varchar(50) NOT NULL,
  `date` date NOT NULL,
  `file_type_icon` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tracking`
--

CREATE TABLE `tracking` (
  `id` int(10) UNSIGNED NOT NULL,
  `type` varchar(255) NOT NULL,
  `employee_name` varchar(255) NOT NULL,
  `date_submitted` date NOT NULL,
  `current_holder` varchar(255) NOT NULL,
  `end_location` varchar(255) NOT NULL,
  `status` varchar(50) NOT NULL,
  `department` varchar(255) DEFAULT NULL,
  `file_type_icon` varchar(50) DEFAULT NULL,
  `ocr_content` text DEFAULT NULL,
  `mobile_timestamp` varchar(50) DEFAULT NULL,
  `file_size` varchar(20) DEFAULT NULL,
  `user_email` varchar(255) DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `doc_hash` char(64) DEFAULT NULL,
  `is_hidden` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tracking_backup`
--

CREATE TABLE `tracking_backup` (
  `id` varchar(50) NOT NULL,
  `type` varchar(255) NOT NULL,
  `employee_name` varchar(255) NOT NULL,
  `date_submitted` date NOT NULL,
  `current_holder` varchar(255) NOT NULL,
  `end_location` varchar(255) NOT NULL,
  `status` varchar(50) NOT NULL,
  `department` varchar(255) NOT NULL,
  `file_type_icon` varchar(10) NOT NULL,
  `ocr_content` text DEFAULT NULL,
  `mobile_timestamp` varchar(50) DEFAULT NULL,
  `file_size` varchar(20) DEFAULT NULL,
  `user_email` varchar(255) DEFAULT NULL,
  `file_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tracking_backup`
--

INSERT INTO `tracking_backup` (`id`, `type`, `employee_name`, `date_submitted`, `current_holder`, `end_location`, `status`, `department`, `file_type_icon`, `ocr_content`, `mobile_timestamp`, `file_size`, `user_email`, `file_path`, `created_at`) VALUES
('', 'Payroll', 'cris', '2025-10-01', 'CADO', 'Mobile App Archive', 'Completed', 'CADO', 'jpg', 'Document Name: cris\\nDocument Type: Payroll\\nScanned By: cris\\nUser Email: cccc@gmail.com\\nUser Role: user\\nDepartment: CADO\\nScan Date: 2025-10-01 14:06:33.577762\\nConfidence: 67.8%\\nDetected Types: General Document\\n\\n--- Extracted Text ---\\nOCRBsedDocametTracking andAechivlSysiem\\nithAland Predictie\\nAnalyicsofPanaboCity\\nDAVAODELNORTESTATECOLLEGE\\nNewVisayas, PanaboCity\\nInstitute ofComputing\\nAProposed Project Titie Submited by\\nCherry MaeR. Abello\\nCarl Dyngel E. Alcomendras\\nKristopher Allen N. Carñete\\nAlem Cris O. Saquin\\nMarch 2025\\n', 'GALLERY_1759298793565_1759306415421_272', '557222', 'cccc@gmail.com', NULL, '2025-10-01 08:13:35');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(50) DEFAULT 'user',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`, `created_at`) VALUES
(4, 'Alem', 'saquinalemcris@gmail.com', '$2y$10$A9AouGVDkA86KJ7HQAMti.P1SJxV./Fd1GL2EeIJXdCwCHjbeQJq2', 'admin', '2025-10-27 07:26:55'),
(0, 'Allentest', '', '$2y$10$.uSwZKl94fVXp9XgoLR5uOi7qM3DBTOxtDE1VVmBmdp0s7JKbqCHq', 'admin', '2025-11-13 23:56:24');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `app_settings`
--
ALTER TABLE `app_settings`
  ADD PRIMARY KEY (`k`);

--
-- Indexes for table `archive`
--
ALTER TABLE `archive`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `control`
--
ALTER TABLE `control`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `dashboard`
--
ALTER TABLE `dashboard`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `document_history`
--
ALTER TABLE `document_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `doc_id` (`doc_id`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `recipient_username` (`recipient_username`),
  ADD KEY `recipient_department` (`recipient_department`),
  ADD KEY `type` (`type`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `predictions_cache`
--
ALTER TABLE `predictions_cache`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `metric_day` (`metric`,`forecast_date`);

--
-- Indexes for table `sla_predictions`
--
ALTER TABLE `sla_predictions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `doc_unique` (`document_id`);

--
-- Indexes for table `tracking`
--
ALTER TABLE `tracking`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tracking_is_hidden` (`is_hidden`),
  ADD KEY `idx_tracking_created_at` (`created_at`),
  ADD KEY `idx_tracking_date_sub` (`date_submitted`),
  ADD KEY `idx_tracking_status` (`status`),
  ADD KEY `idx_tracking_department` (`department`),
  ADD KEY `idx_tracking_mobile_ts` (`mobile_timestamp`),
  ADD KEY `idx_tracking_date_submitted` (`date_submitted`),
  ADD KEY `idx_tracking_status_created` (`status`,`created_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `control`
--
ALTER TABLE `control`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `document_history`
--
ALTER TABLE `document_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=119;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=245;

--
-- AUTO_INCREMENT for table `predictions_cache`
--
ALTER TABLE `predictions_cache`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `sla_predictions`
--
ALTER TABLE `sla_predictions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tracking`
--
ALTER TABLE `tracking`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9093;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
