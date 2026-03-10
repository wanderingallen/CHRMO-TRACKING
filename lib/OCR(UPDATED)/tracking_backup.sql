-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 03, 2025 at 09:44 AM
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
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
