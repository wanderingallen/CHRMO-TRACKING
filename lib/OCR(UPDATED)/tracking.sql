-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 03, 2025 at 10:28 AM
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
-- Table structure for table `tracking`
--

CREATE TABLE `tracking` (
  `id` int(11) NOT NULL,
  `type` varchar(255) NOT NULL,
  `sender` varchar(255) NOT NULL,
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tracking`
--

INSERT INTO `tracking` (`id`, `type`, `sender`, `date_submitted`, `current_holder`, `end_location`, `status`, `department`, `file_type_icon`, `ocr_content`, `mobile_timestamp`, `file_size`, `user_email`, `file_path`, `created_at`) VALUES
(1, 'Payroll', 'cris', '2025-10-01', 'CADO', 'Digital Archive', 'Archived', 'CADO', 'jpg', 'Document Name: cris\\nDocument Type: Payroll\\nScanned By: cris\\nUser Email: cccc@gmail.com\\nUser Role: user\\nDepartment: CADO\\nScan Date: 2025-10-01 14:06:33.577762\\nConfidence: 67.8%\\nDetected Types: General Document\\n\\n--- Extracted Text ---\\nOCRBsedDocametTracking andAechivlSysiem\\nithAland Predictie\\nAnalyicsofPanaboCity\\nDAVAODELNORTESTATECOLLEGE\\nNewVisayas, PanaboCity\\nInstitute ofComputing\\nAProposed Project Titie Submited by\\nCherry MaeR. Abello\\nCarl Dyngel E. Alcomendras\\nKristopher Allen N. Carñete\\nAlem Cris O. Saquin\\nMarch 2025\\n', 'GALLERY_1759298793565_1759306415421_272', '557222', 'cccc@gmail.com', NULL, '2025-10-01 08:13:35'),
(2, 'Purchase Request', 'cris', '2025-09-30', 'CADO', 'Mobile App Archive', 'Completed', 'CADO', 'jpg', 'Document Name: cris\\nDocument Type: Purchase Request\\nScanned By: cris\\nUser Email: cccc@gmail.com\\nUser Role: user\\nDepartment: CADO\\nScan Date: 2025-09-30 12:32:39.683729\\nConfidence: 86.1%\\nDetected Types: General Document\\n\\n--- Extracted Text ---\\nOCR-Based Document Tracking and Archival System with Al and Predictive\\nAnalytics of Panabo City\\n1995\\nDAVAO DEL NORTE STATECOLLEGE\\nNewVisayas, Panabo City\\nInstitute of Computing\\nA Proposed Project Title Submitted by:\\nCherry Mae R. Abello\\nCarl Dyngel E. Alcomendras\\nKristopher Allen N. Cañete\\nAlem Cris . Saquin\\nMarch 2025\\n', 'GALLERY_1759206759679_1759308924988_142', '569563', 'cccc@gmail.com', NULL, '2025-10-01 08:55:25'),
(3, 'Advisory', 'cris', '2025-09-30', 'CADO', 'Mobile App Archive', 'Completed', 'CADO', 'jpg', 'Document Name: cris\\nDocument Type: Advisory\\nScanned By: cris\\nUser Email: cccc@gmail.com\\nUser Role: user\\nDepartment: CADO\\nScan Date: 2025-09-30 12:11:07.812351\\nConfidence: 85.5%\\nDetected Types: General Document\\n\\n--- Extracted Text ---\\nOCR-Based DocumentTracking and Archival system with Al and Predictive\\nAnalyticsof Panabo City\\n1995\\nDAVAO DEL NORTE STATECOLLEGE\\nNewVisayas, Panabo City\\nInstitute of Computing\\nA Proposed Project Title Submitted by:\\nCherry Mae R. Abello\\nCarl Dyngel E. Alcomendras\\nKristopher AllenN. Cañete\\nAlem Cris O. Saquin\\nMarch 2025\\n', 'GALLERY_1759205467797898797_1759308946095_882', '590565', 'cccc@gmail.com', NULL, '2025-10-01 08:55:46'),
(4, 'Payroll', 'cris', '2025-09-30', 'CADO', 'Mobile App Archive', 'Completed', 'CADO', 'jpg', 'Document Name: cris\\nDocument Type: Payroll\\nScanned By: cris\\nUser Email: cccc@gmail.com\\nUser Role: user\\nDepartment: CADO\\nScan Date: 2025-09-30 11:30:07.947302\\nConfidence: 79.6%\\nDetected Types: General Document\\n\\n--- Extracted Text ---\\nOCRIlased Document Trackingand Archival System with Al and Predictive\\nAnalytics ofPanabo City\\nI995\\nDEL NORTE STATECOLLEGE\\nDAVA\\nNewVisayas, Panabo City\\nInstitute of Computing\\nProject Title Submitted by\\nA Proposed\\nCherry Mae R. Abello\\nE. Alcomendras\\nCarl Dyngel\\nKristopher Allen N. Cañete\\nAlem Cris O. Saquin\\nMarch 2025\\n', 'GALLERY_1759203007931468931_1759308956915_446', '586331', 'cccc@gmail.com', NULL, '2025-10-01 08:55:57'),
(5, 'Payroll', 'cris', '2025-09-30', 'CADO', 'Mobile App Archive', 'Completed', 'CADO', 'jpg', 'Document Name: cris\\nDocument Type: Payroll\\nScanned By: cris\\nUser Email: cccc@gmail.com\\nUser Role: user\\nDepartment: CADO\\nScan Date: 2025-09-30 11:13:21.881415\\nConfidence: 80.2%\\nDetected Types: General Document\\n\\n--- Extracted Text ---\\nOCR-BasedDocumentTracking and Archival\\nSystem with At andPredictve\\nAnalytics ofPanabo City\\n995\\nDAVAODELNORTE STATECOLLEGE\\nNewVisayas, PanaboCity\\nInstitute ofComputing\\nAProposed Project Title Submitted by:\\nCherry Mae R. Abello\\nCarl Dyngel E. Alcomendras\\nKristopher Allen N. Cañete\\nAlem Cris O. Saquin\\nMarch 2025\\n', 'GALLERY_1759202001878163878_1759309038317_242', '600483', 'cccc@gmail.com', NULL, '2025-10-01 08:57:18'),
(6, 'Payroll', 'cris', '2025-09-30', 'CADO', 'Mobile App Archive', 'Completed', 'CADO', 'jpg', 'Document Name: cris\\nDocument Type: Payroll\\nScanned By: cris\\nUser Email: cccc@gmail.com\\nUser Role: user\\nDepartment: CADO\\nScan Date: 2025-09-30 11:13:21.881415\\nConfidence: 80.2%\\nDetected Types: General Document\\n\\n--- Extracted Text ---\\nOCR-BasedDocumentTracking and Archival\\nSystem with At andPredictve\\nAnalytics ofPanabo City\\n995\\nDAVAODELNORTE STATECOLLEGE\\nNewVisayas, PanaboCity\\nInstitute ofComputing\\nAProposed Project Title Submitted by:\\nCherry Mae R. Abello\\nCarl Dyngel E. Alcomendras\\nKristopher Allen N. Cañete\\nAlem Cris O. Saquin\\nMarch 2025\\n', 'GALLERY_1759202001878163878_1759309060482_958', '600483', 'cccc@gmail.com', NULL, '2025-10-01 08:57:40'),
(7, 'Advisory', 'cris', '2025-10-01', 'CADO', 'Mobile App Archive', 'Completed', 'CADO', 'file', 'Document Name: cris\\nDocument Type: Advisory\\nScanned By: cris\\nUser Email: cccc@gmail.com\\nUser Role: user\\nDepartment: CADO\\nScan Date: 2025-10-01 16:58:25.284698\\nConfidence: 84.4%\\nDetected Types: General Document\\n\\n--- Extracted Text ---\\nTracking and Arcbival System with Al and Predictive\\nOCR-Based Document\\nAnalytics of PanaboCity\\nDAVAODELNORTESTATECOLLEGE\\nNewVisayas, PanaboCity\\nInstitute of Computing\\nA Proposed Project Title Submitted by:\\nCherry Mae R. Abello\\nCarl Dyngel E. Alcomendras\\nKristopher Allen N		Cañete\\nAlem Cris O. Saquin\\nMarch 2025\\n', 'MOBILE_1759309105276_1759309107459_339', '576302', 'cccc@gmail.com', NULL, '2025-10-01 08:58:27'),
(8, 'Payroll', 'cris', '2025-10-01', 'CADO', 'Mobile App Archive', 'Completed', 'CADO', 'file', 'Document Name: cris\\nDocument Type: Payroll\\nScanned By: cris\\nUser Email: cccc@gmail.com\\nUser Role: user\\nDepartment: CADO\\nScan Date: 2025-10-01 17:02:34.197107\\nConfidence: 84.9%\\nDetected Types: General Document\\n\\n--- Extracted Text ---\\nOCR-Based Document Tracking and Archival System with Al and Predictive\\nAnalytics of Panabo City\\nDAVAO DEL NORTE STATECOLLEGE\\nNewVisavas. Panabo City\\nInstitute of Computing\\nA Proposed Project Title Submitted by\\nCherry Mae R. Abello\\nCarl Dyngel E Alcomendras\\nKristopher Allen N Cañete\\nAlem Cris	O. Saguin\\nMarch 2025\\n', 'MOBILE_1759309354194_1759309361104_325', '563870', 'cccc@gmail.com', NULL, '2025-10-01 09:02:41'),
(9, 'Purchase Order', 'cris', '2025-10-01', 'CADO', 'Mobile App Archive', 'Completed', 'CADO', 'file', 'Document Name: cris\\nDocument Type: Purchase Order\\nScanned By: cris\\nUser Email: cccc@gmail.com\\nUser Role: user\\nDepartment: CADO\\nScan Date: 2025-10-01 17:02:56.682730\\nConfidence: 80.4%\\nDetected Types: General Document\\n\\n--- Extracted Text ---\\nCR-EBasedDocumentTracking\\nand ArchivalSystem with\\nAnalytics		Al andredicive\\nof Panabo City\\nDAVAO DEL NORTESTATE\\nCOLLEGE\\nNewVisayas, PanaboCity\\nInstitute ofComputing\\nAProposed ProjectTitle Submitted by\\nCherry Mae R.Abello\\nCarl Dyngel E. Alcomendras\\nKristopher Allen N. Cañete\\nAlem Cris O. Saquin\\nMarch 2025\\n', 'MOBILE_1759309376678_1759309378576_619', '598980', 'cccc@gmail.com', NULL, '2025-10-01 09:02:58'),
(10, 'Purchase Order', 'cris', '2025-10-01', 'CADO', 'Mobile App Archive', 'Completed', 'CADO', 'file', 'Document Name: cris\\nDocument Type: Purchase Order\\nScanned By: cris\\nUser Email: cccc@gmail.com\\nUser Role: user\\nDepartment: CADO\\nScan Date: 2025-10-01 17:03:22.729683\\nConfidence: 77.8%\\nDetected Types: General Document\\n\\n--- Extracted Text ---\\nCR-BasedDocument Tracking and\\nArchival Systemwith AlandPredictive\\nAnalytics of Parnabo City\\nDAVAO DEL NORTE STATE COLLEGE\\nNewVisayas, Panabo City\\nInstitute ofComputing\\nA Proposed ProjectTitile Submitted by\\nCherry Mae R. Abello\\nCari Dyngel E. Alcomendras\\nKristopher Allen N. Cañete\\nAlem Cris O. Saquin\\nMarch 2025\\n', 'MOBILE_1759309402727_1759309404900_622', '573020', 'cccc@gmail.com', NULL, '2025-10-01 09:03:25'),
(11, 'Payroll', 'Allen', '2025-09-30', 'CMO', 'Mobile App Archive', 'Completed', 'CMO', 'jpg', 'Document Name: cris\\nDocument Type: Payroll\\nScanned By: cris\\nUser Email: cccc@gmail.com\\nUser Role: user\\nDepartment: CADO\\nScan Date: 2025-09-30 11:30:07.947302\\nConfidence: 79.6%\\nDetected Types: General Document\\n\\n--- Extracted Text ---\\nOCRIlased Document Trackingand Archival System with Al and Predictive\\nAnalytics ofPanabo City\\nI995\\nDEL NORTE STATECOLLEGE\\nDAVA\\nNewVisayas, Panabo City\\nInstitute of Computing\\nProject Title Submitted by\\nA Proposed\\nCherry Mae R. Abello\\nE. Alcomendras\\nCarl Dyngel\\nKristopher Allen N. Cañete\\nAlem Cris O. Saquin\\nMarch 2025\\n', 'GALLERY_1759203007931468931_1759324646616_735', '586331', 'aaaa@gmail.com', NULL, '2025-10-01 13:17:27'),
(12, 'Announcement', 'Allen', '2025-10-01', 'CMO', 'Mobile App Archive', 'Completed', 'CMO', 'file', 'Document Name: Allen\\nDocument Type: Announcement\\nScanned By: Allen\\nUser Email: aaaa@gmail.com\\nUser Role: user\\nDepartment: CMO\\nScan Date: 2025-10-01 22:26:15.019261\\nConfidence: 84.6%\\nDetected Types: General Document\\n\\n--- Extracted Text ---\\nOCR-BasedDocumentTracking and Archival System with Al andPredictive\\nAnalytics of Panabo City\\n1995\\nDAVAO DEL NORTE STATE COLLEGE\\nNew Visayas, Panabo City\\nInstitute of Computing\\nProposed Project Title Submitted by:\\nA\\nCherry MaeR. Abello\\nCarl Dyngel E. Alcomendras\\nKristopher Allen N, Cañete\\nAlem Cris O. Saquin\\nMarch 2025\\n', 'MOBILE_1759328775016_1759328778172_697', '568457', 'aaaa@gmail.com', NULL, '2025-10-01 14:26:19'),
(13, 'Purchase Request', 'cris', '2025-10-01', 'CADO', 'Mobile App Archive', 'Completed', 'CADO', 'file', 'Document Name: cris\\nDocument Type: Purchase Request\\nScanned By: cris\\nUser Email: cccc@gmail.com\\nUser Role: user\\nDepartment: CADO\\nScan Date: 2025-10-01 22:28:11.395077\\nConfidence: 77.9%\\nDetected Types: General Document\\n\\n--- Extracted Text ---\\nOCR-Based DocumentTracking andArchivatSystem with Al andPredietive\\nAnaytics of PanabeCity\\n1995\\nDAVAODEL NORTESTATECOLLEGE\\nNewVisayas, PanaboCity\\nInstitute of Computing\\nProposed ProjectTitle Submitted by:\\nA\\nCherry Mae R. Abello\\nDyngel E.Alcomendras\\nCarl\\nKristopher Allen N. Cañete\\nAlem Cris O. Saquin\\nMarch 2025\\n', 'MOBILE_1759328891392_1759328900744_411', '591637', 'cccc@gmail.com', NULL, '2025-10-01 14:28:21'),
(14, 'Payroll', 'cris', '2025-10-01', 'CADO', 'Mobile App Archive', 'Completed', 'CADO', 'file', 'Document Name: cris\\nDocument Type: Payroll\\nScanned By: cris\\nUser Email: cccc@gmail.com\\nUser Role: user\\nDepartment: CADO\\nScan Date: 2025-10-01 22:28:42.975287\\nConfidence: 52.2%\\nDetected Types: General Document\\n\\n--- Extracted Text ---\\noGRBasadbocumetTracking snd ArshivsSyetam vsA and 1\\nAnalyteotPanasoCHy\\n1993\\nDAVAO DEL NORTE STATE COLLEGE\\nNew Visayas, Panabo City\\nInstitute of Computing\\nAProposed Proect Ttice	Subrnitted by\\n', 'MOBILE_1759328922970_1759328925552_446', '624799', 'cccc@gmail.com', NULL, '2025-10-01 14:28:46'),
(15, 'Purchase Order', 'carl', '2025-10-02', 'CACCO', 'Mobile App Archive', 'Completed', 'CACCO', 'jpg', 'Document Name: carl\\nDocument Type: Purchase Order\\nScanned By: carl\\nUser Email: carl@gmail.com\\nUser Role: user\\nDepartment: CACCO\\nScan Date: 2025-10-02 02:59:55.719917\\nConfidence: 49.8%\\nDetected Types: General Document\\n\\n--- Extracted Text ---\\nCherny Mae\\nAemCs\\n', 'GALLERY_1759345195717_1759345211522_265', '582899', 'carl@gmail.com', NULL, '2025-10-01 19:00:12'),
(16, 'Advisory', 'carl', '2025-10-02', 'CACCO', 'Mobile App Archive', 'Completed', 'CACCO', 'file', 'Document Name: carl\\nDocument Type: Advisory\\nScanned By: carl\\nUser Email: carl@gmail.com\\nUser Role: user\\nDepartment: CACCO\\nScan Date: 2025-10-02 21:50:33.621384\\nConfidence: 82.7%\\nDetected Types: General Document\\n\\n--- Extracted Text ---\\nN\\nOCR-Based Document Tracking and Archival System with AI and Predictive\\nAnalytics of PanaboCity\\nRIE\\nI095\\nDAVAO DEL NORTE STATE COLLEGE\\nNewVisayas, Panabo City\\nInstitute of Computing\\nProject Title Submitted by:\\nAProposed\\nCherry Mae R. Abello\\nDyngel E. Alcomendras\\nCarl\\nKristopher Allen N. Cañete\\nAlem Cris O, Saquin\\nMarch 2025\\n', 'MOBILE_1759413033617_1759413048570_789', '600173', 'carl@gmail.com', NULL, '2025-10-02 13:50:50'),
(17, 'Purchase Request', 'carl', '2025-10-02', 'CACCO', 'Mobile App Archive', 'Completed', 'CACCO', 'file', 'Document Name: carl\\nDocument Type: Purchase Request\\nScanned By: carl\\nUser Email: carl@gmail.com\\nUser Role: user\\nDepartment: CACCO\\nScan Date: 2025-10-02 22:00:24.803411\\nConfidence: 83.0%\\nDetected Types: General Document\\n\\n--- Extracted Text ---\\nOCR-Based\\nDocument\\nTrackingand\\nAnalytics ArchivalSystem\\nofPanabo		withAl andPredictive\\nCity\\nT4\\n199\\nDAVAODELNORTE\\nSTATECOLLEGE\\nNewVisayas,Panabo\\nCity\\nInstitute ofComputing\\nA Proposed ProjectTitle Submitted\\nby:\\nCherry MaeR. Abello\\nCarl Dyngel E. Alcomendras\\nKristopherAllen N. Cañete\\nAlem Cris O. Saquin\\nMarch 2025\\n', 'MOBILE_1759413624800_1759413634075_42', '586872', 'carl@gmail.com', NULL, '2025-10-02 14:00:35');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `tracking`
--
ALTER TABLE `tracking`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `tracking`
--
ALTER TABLE `tracking`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
