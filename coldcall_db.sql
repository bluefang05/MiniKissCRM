-- phpMyAdmin SQL Dump
-- version 5.1.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 17, 2025 at 02:31 AM
-- Server version: 10.4.22-MariaDB
-- PHP Version: 8.1.2

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `coldcall_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `user_id`, `action`, `description`, `ip_address`, `user_agent`, `created_at`) VALUES
(1, 5, 'import_leads', 'file=M1134717_head20.csv src=4 default_interest=4 created=20 updated=0 skipped=0', '127.0.0.1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:138.0) Gecko/20100101 Firefox/138.0', '2025-05-21 23:01:00');

-- --------------------------------------------------------

--
-- Table structure for table `dispositions`
--

CREATE TABLE `dispositions` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `dispositions`
--

INSERT INTO `dispositions` (`id`, `name`, `created_at`) VALUES
(1, 'Left Voicemail', '2025-05-14 18:06:03'),
(2, 'Interested', '2025-05-14 18:06:03'),
(3, 'Not Interested', '2025-05-14 18:06:03'),
(4, 'Follow Up', '2025-05-14 18:06:03'),
(5, 'Service Sold', '2025-06-13 09:17:34'),
(6, 'Do Not Call Again', '2025-06-13 09:17:34');

-- --------------------------------------------------------

--
-- Table structure for table `income_ranges`
--

CREATE TABLE `income_ranges` (
  `code` char(1) NOT NULL,
  `description` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `income_ranges`
--

INSERT INTO `income_ranges` (`code`, `description`) VALUES
('C', 'C'),
('D', 'D'),
('E', 'E'),
('F', 'F');

-- --------------------------------------------------------

--
-- Table structure for table `insurance_interests`
--

CREATE TABLE `insurance_interests` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `insurance_interests`
--

INSERT INTO `insurance_interests` (`id`, `name`, `category`, `active`, `created_at`) VALUES
(1, 'Life', 'Personal', 1, '2025-05-14 18:06:03'),
(2, 'Home', 'Property', 1, '2025-05-14 18:06:03'),
(3, 'Auto', 'Vehicle', 1, '2025-05-14 18:06:03'),
(4, 'Health', 'Personal', 1, '2025-05-15 12:10:40');

-- --------------------------------------------------------

--
-- Table structure for table `interactions`
--

CREATE TABLE `interactions` (
  `id` int(11) NOT NULL,
  `lead_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `disposition_id` int(11) NOT NULL,
  `notes` text DEFAULT NULL,
  `interaction_time` datetime NOT NULL DEFAULT current_timestamp(),
  `duration_seconds` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `interactions`
--

INSERT INTO `interactions` (`id`, `lead_id`, `user_id`, `disposition_id`, `notes`, `interaction_time`, `duration_seconds`, `created_at`, `updated_at`) VALUES
(1, 198126, 5, 4, 'testing dashboard ', '2025-06-02 09:21:29', 145, '2025-06-02 09:21:29', '2025-06-02 09:21:29'),
(2, 198126, 5, 4, 'testing dashboard  2', '2025-06-02 10:36:07', 111, '2025-06-02 10:36:07', '2025-06-02 10:36:07'),
(3, 198107, 5, 2, 'Lead was interested in health insurance.', '2025-05-30 10:00:00', 120, '2025-05-30 10:00:00', '2025-05-30 10:00:00'),
(4, 198108, 6, 3, 'Lead not interested at this time.', '2025-05-30 10:15:00', 60, '2025-05-30 10:15:00', '2025-05-30 10:15:00'),
(5, 198109, 5, 4, 'Scheduled a follow-up call for next week.', '2025-05-30 10:30:00', 180, '2025-05-30 10:30:00', '2025-05-30 10:30:00'),
(6, 198110, 6, 1, 'Left a voicemail, no answer.', '2025-05-31 09:00:00', 30, '2025-05-31 09:00:00', '2025-05-31 09:00:00'),
(7, 198111, 5, 2, 'Very interested, sent quote.', '2025-05-31 09:30:00', 240, '2025-05-31 09:30:00', '2025-05-31 09:30:00'),
(8, 198112, 6, 3, 'Not a good time, asked to be removed from list.', '2025-06-01 11:00:00', 45, '2025-06-01 11:00:00', '2025-06-01 11:00:00'),
(9, 198113, 5, 2, 'Lead seems qualified, moving to next stage.', '2025-06-01 11:30:00', 300, '2025-06-01 11:30:00', '2025-06-01 11:30:00'),
(10, 198114, 4, 1, 'No answer, left message.', '2025-06-02 09:00:00', 25, '2025-06-02 09:00:00', '2025-06-02 09:00:00'),
(11, 198115, 4, 2, 'Interested in a follow-up call today.', '2025-06-02 09:15:00', 90, '2025-06-02 09:15:00', '2025-06-02 09:15:00'),
(12, 198116, 4, 4, 'Follow up next week for a demo.', '2025-06-02 09:45:00', 150, '2025-06-02 09:45:00', '2025-06-02 09:45:00'),
(13, 198126, 5, 2, 'testing interested ', '2025-06-03 11:51:13', 55, '2025-06-03 11:51:13', '2025-06-03 11:51:13'),
(14, 198126, 5, 3, 'Lead requested to be removed from future calls.', '2025-06-05 01:31:05', 130, '2025-06-05 01:31:05', '2025-06-05 01:31:05'),
(15, 198126, 5, 4, '13', '2025-06-12 16:46:17', 13, '2025-06-12 16:46:17', '2025-06-12 16:46:17'),
(16, 198107, 5, 2, 'Lead very interested in health insurance.', '2025-06-01 10:00:00', 150, '2025-06-13 19:18:20', '2025-06-13 19:18:20'),
(17, 198108, 5, 3, 'Not interested. Asked not to be contacted again.', '2025-06-01 10:15:00', 60, '2025-06-13 19:18:20', '2025-06-13 19:18:20'),
(18, 198109, 5, 4, 'Follow-up scheduled for next week.', '2025-06-01 10:30:00', 180, '2025-06-13 19:18:20', '2025-06-13 19:18:20'),
(19, 198110, 5, 1, 'Left voicemail, no answer.', '2025-06-01 10:45:00', 30, '2025-06-13 19:18:20', '2025-06-13 19:18:20'),
(20, 198111, 5, 2, 'Interested in quote sent.', '2025-06-01 11:00:00', 240, '2025-06-13 19:18:20', '2025-06-13 19:18:20'),
(21, 198112, 5, 6, 'Do not call again requested.', '2025-06-01 11:15:00', 45, '2025-06-13 19:18:20', '2025-06-13 19:18:20'),
(22, 198113, 5, 5, 'Sale closed over the phone.', '2025-06-01 11:30:00', 300, '2025-06-13 19:18:20', '2025-06-13 19:18:20'),
(23, 198114, 5, 1, 'No answer, left message.', '2025-06-01 11:45:00', 25, '2025-06-13 19:18:20', '2025-06-13 19:18:20'),
(24, 198115, 6, 2, 'Interested in follow-up today.', '2025-06-01 13:00:00', 90, '2025-06-13 19:18:20', '2025-06-13 19:18:20'),
(25, 198116, 6, 4, 'Will follow up next week.', '2025-06-01 13:15:00', 150, '2025-06-13 19:18:20', '2025-06-13 19:18:20'),
(26, 198117, 6, 3, 'Not interested at this time.', '2025-06-01 13:30:00', 60, '2025-06-13 19:18:20', '2025-06-13 19:18:20'),
(27, 198118, 6, 1, 'Voicemail left.', '2025-06-01 13:45:00', 30, '2025-06-13 19:18:20', '2025-06-13 19:18:20'),
(28, 198119, 6, 5, 'Service sold after second call.', '2025-06-01 14:00:00', 200, '2025-06-13 19:18:20', '2025-06-13 19:18:20'),
(29, 198120, 6, 6, 'Asked to remove from list.', '2025-06-01 14:15:00', 60, '2025-06-13 19:18:20', '2025-06-13 19:18:20'),
(30, 198119, 6, 5, 'Service sold after second call.', '2025-06-01 14:00:00', 200, '2025-06-13 21:14:31', '2025-06-13 21:14:31'),
(31, 198120, 6, 6, 'Asked to remove from list.', '2025-06-01 14:15:00', 60, '2025-06-13 21:14:31', '2025-06-13 21:14:31'),
(32, 198117, 5, 2, 'Expressed interest in life insurance.', '2025-06-08 22:35:09', 180, '2025-06-13 21:35:09', '2025-06-13 21:35:09'),
(33, 198118, 5, 1, 'Left voicemail on mobile.', '2025-06-08 23:35:09', 45, '2025-06-13 21:35:09', '2025-06-13 21:35:09'),
(34, 198119, 5, 3, 'Not interested in current offerings.', '2025-06-09 00:35:09', 60, '2025-06-13 21:35:09', '2025-06-13 21:35:09'),
(35, 198120, 4, 4, 'Follow-up scheduled for next week.', '2025-06-09 22:35:09', 90, '2025-06-13 21:35:09', '2025-06-13 21:35:09'),
(36, 198121, 4, 2, 'Interested in home coverage.', '2025-06-09 23:35:09', 120, '2025-06-13 21:35:09', '2025-06-13 21:35:09'),
(37, 198122, 4, 1, 'No answer, left message.', '2025-06-10 00:35:09', 30, '2025-06-13 21:35:09', '2025-06-13 21:35:09'),
(38, 198123, 6, 5, 'Service sold after second call.', '2025-06-10 22:35:09', 200, '2025-06-13 21:35:09', '2025-06-13 21:35:09'),
(39, 198124, 6, 6, 'Do not call again.', '2025-06-10 23:35:09', 20, '2025-06-13 21:35:09', '2025-06-13 21:35:09'),
(40, 198125, 7, 2, 'Requested more information via email.', '2025-06-11 22:35:09', 150, '2025-06-13 21:35:09', '2025-06-13 21:35:09'),
(41, 198126, 7, 4, 'Will follow up tomorrow.', '2025-06-11 23:35:09', 100, '2025-06-13 21:35:09', '2025-06-13 21:35:09'),
(42, 198107, 6, 1, 'Left voicemail about life insurance.', '2025-06-09 22:22:34', 30, '2025-06-13 21:22:34', '2025-06-13 21:22:34'),
(43, 198108, 6, 2, 'Lead interested in home coverage.', '2025-06-09 23:22:34', 150, '2025-06-13 21:22:34', '2025-06-13 21:22:34'),
(44, 198109, 6, 3, 'Not interested at this time.', '2025-06-10 00:22:34', 60, '2025-06-13 21:22:34', '2025-06-13 21:22:34'),
(45, 198110, 7, 4, 'Follow-up scheduled for tomorrow.', '2025-06-10 22:22:34', 90, '2025-06-13 21:22:34', '2025-06-13 21:22:34'),
(46, 198111, 7, 2, 'Expressed interest in health plan.', '2025-06-10 23:22:34', 120, '2025-06-13 21:22:34', '2025-06-13 21:22:34'),
(47, 198112, 7, 1, 'No answer, left message.', '2025-06-11 00:22:34', 45, '2025-06-13 21:22:34', '2025-06-13 21:22:34'),
(48, 198113, 8, 5, 'Service sold over the phone.', '2025-06-11 22:22:34', 180, '2025-06-13 21:22:34', '2025-06-13 21:22:34'),
(49, 198114, 8, 6, 'Do not call again requested.', '2025-06-11 23:22:34', 20, '2025-06-13 21:22:34', '2025-06-13 21:22:34'),
(50, 198115, 9, 2, 'Very interested in auto insurance.', '2025-06-12 22:22:34', 200, '2025-06-13 21:22:34', '2025-06-13 21:22:34'),
(51, 198116, 9, 4, 'Will follow up next week.', '2025-06-12 23:22:34', 100, '2025-06-13 21:22:34', '2025-06-13 21:22:34'),
(55, 198107, 9, 1, 'Left voicemail about life insurance.', '2025-06-08 22:28:02', 30, '2025-06-13 21:28:02', '2025-06-13 21:28:02'),
(56, 198108, 9, 2, 'Interested in home coverage.', '2025-06-08 23:28:02', 150, '2025-06-13 21:28:02', '2025-06-13 21:28:02'),
(57, 198109, 9, 3, 'Not interested at this time.', '2025-06-09 00:28:02', 60, '2025-06-13 21:28:02', '2025-06-13 21:28:02'),
(58, 198107, 9, 1, 'Left voicemail about life insurance.', '2025-06-08 22:29:32', 30, '2025-06-13 21:29:32', '2025-06-13 21:29:32'),
(59, 198108, 9, 2, 'Interested in home coverage.', '2025-06-08 23:29:32', 150, '2025-06-13 21:29:32', '2025-06-13 21:29:32'),
(60, 198109, 9, 3, 'Not interested at this time.', '2025-06-09 00:29:32', 60, '2025-06-13 21:29:32', '2025-06-13 21:29:32'),
(61, 198110, 8, 4, 'Follow-up scheduled for tomorrow.', '2025-06-09 22:29:32', 90, '2025-06-13 21:29:32', '2025-06-13 21:29:32'),
(62, 198111, 8, 2, 'Expressed interest in health plan.', '2025-06-09 23:29:32', 120, '2025-06-13 21:29:32', '2025-06-13 21:29:32'),
(63, 198112, 8, 1, 'No answer, left message.', '2025-06-10 00:29:32', 45, '2025-06-13 21:29:32', '2025-06-13 21:29:32'),
(64, 198113, 2, 5, 'Service sold over the phone.', '2025-06-10 22:29:32', 180, '2025-06-13 21:29:32', '2025-06-13 21:29:32'),
(65, 198114, 2, 6, 'Do not call again requested.', '2025-06-10 23:29:32', 20, '2025-06-13 21:29:32', '2025-06-13 21:29:32'),
(69, 198107, 5, 1, 'Left voicemail about life insurance.', '2025-06-08 22:44:50', 30, '2025-06-13 21:44:50', '2025-06-13 21:44:50'),
(70, 198108, 5, 2, 'Interested in home coverage.', '2025-06-08 23:44:50', 150, '2025-06-13 21:44:50', '2025-06-13 21:44:50'),
(71, 198109, 5, 3, 'Not interested at this time.', '2025-06-09 00:44:50', 60, '2025-06-13 21:44:50', '2025-06-13 21:44:50'),
(72, 198110, 6, 4, 'Follow-up scheduled for tomorrow.', '2025-06-09 22:44:50', 90, '2025-06-13 21:44:50', '2025-06-13 21:44:50'),
(73, 198111, 6, 2, 'Expressed interest in health plan.', '2025-06-09 23:44:50', 120, '2025-06-13 21:44:50', '2025-06-13 21:44:50'),
(74, 198112, 6, 1, 'No answer, left message.', '2025-06-10 00:44:50', 45, '2025-06-13 21:44:50', '2025-06-13 21:44:50'),
(75, 198113, 7, 5, 'Service sold over the phone.', '2025-06-10 22:44:50', 180, '2025-06-13 21:44:50', '2025-06-13 21:44:50'),
(76, 198114, 7, 6, 'Do not call again requested.', '2025-06-10 23:44:50', 20, '2025-06-13 21:44:50', '2025-06-13 21:44:50'),
(77, 198115, 5, 2, 'Very interested in auto insurance.', '2025-06-11 22:44:50', 200, '2025-06-13 21:44:50', '2025-06-13 21:44:50'),
(78, 198116, 6, 4, 'Will follow up next week.', '2025-06-11 23:44:50', 100, '2025-06-13 21:44:50', '2025-06-13 21:44:50'),
(79, 198107, 5, 2, 'Lead interested in life insurance.', '2025-06-10 21:47:05', 150, '2025-06-13 21:47:05', '2025-06-13 21:47:05'),
(80, 198108, 5, 1, 'Left voicemail.', '2025-06-10 22:47:05', 30, '2025-06-13 21:47:05', '2025-06-13 21:47:05'),
(81, 198109, 6, 3, 'Not interested.', '2025-06-11 21:47:05', 60, '2025-06-13 21:47:05', '2025-06-13 21:47:05'),
(82, 198110, 6, 2, 'Interested in home coverage.', '2025-06-12 21:47:05', 120, '2025-06-13 21:47:05', '2025-06-13 21:47:05'),
(93, 198107, 5, 2, 'Expressed interest in life insurance.', '2025-06-08 21:57:49', 150, '2025-06-13 21:57:49', '2025-06-13 21:57:49'),
(94, 198108, 5, 1, 'Left voicemail on mobile.', '2025-06-08 22:57:49', 45, '2025-06-13 21:57:49', '2025-06-13 21:57:49'),
(95, 198109, 5, 3, 'Not interested in current offerings.', '2025-06-08 23:57:49', 60, '2025-06-13 21:57:49', '2025-06-13 21:57:49'),
(96, 198110, 6, 4, 'Follow-up scheduled for tomorrow.', '2025-06-09 21:57:49', 90, '2025-06-13 21:57:49', '2025-06-13 21:57:49'),
(97, 198111, 6, 2, 'Interested in home coverage.', '2025-06-09 22:57:49', 120, '2025-06-13 21:57:49', '2025-06-13 21:57:49'),
(98, 198112, 6, 1, 'No answer, left message.', '2025-06-09 23:57:49', 30, '2025-06-13 21:57:49', '2025-06-13 21:57:49'),
(99, 198113, 7, 5, 'Service sold after second call.', '2025-06-10 21:57:49', 200, '2025-06-13 21:57:49', '2025-06-13 21:57:49'),
(100, 198114, 7, 6, 'Do not call again.', '2025-06-10 22:57:49', 20, '2025-06-13 21:57:49', '2025-06-13 21:57:49'),
(101, 198115, 5, 2, 'Very interested in auto insurance.', '2025-06-11 21:57:49', 150, '2025-06-13 21:57:49', '2025-06-13 21:57:49'),
(102, 198116, 6, 4, 'Will follow up next week.', '2025-06-11 22:57:49', 100, '2025-06-13 21:57:49', '2025-06-13 21:57:49'),
(103, 198201, 5, 5, 'Successfully sold life insurance.', '2025-06-15 00:30:35', NULL, '2025-06-15 00:30:35', '2025-06-15 00:30:35'),
(104, 198200, 5, 6, 'Customer requested no further calls.', '2025-06-15 00:30:35', NULL, '2025-06-15 00:30:35', '2025-06-15 00:30:35'),
(105, 198199, 5, 3, 'Not interested at this time.', '2025-06-15 00:30:35', NULL, '2025-06-15 00:30:35', '2025-06-15 00:30:35'),
(106, 198198, 5, 4, 'Will follow up next week.', '2025-06-15 00:30:35', NULL, '2025-06-15 00:30:35', '2025-06-15 00:30:35'),
(113, 198205, 5, 2, 'Initial call: Interested', '2025-06-08 00:34:52', NULL, '2025-06-15 00:34:52', '2025-06-15 00:34:52'),
(114, 198205, 5, 5, 'Finalized sale over the phone.', '2025-06-15 00:34:52', NULL, '2025-06-15 00:34:52', '2025-06-15 00:34:52'),
(117, 198207, 5, 2, 'Initial call: Interested', '2025-06-08 00:40:11', NULL, '2025-06-15 00:40:11', '2025-06-15 00:40:11'),
(118, 198207, 5, 5, 'Finalized sale over the phone.', '2025-06-15 00:40:11', NULL, '2025-06-15 00:40:11', '2025-06-15 00:40:11'),
(119, 198208, 5, 5, 'Successfully sold life insurance.', '2025-06-14 00:44:11', NULL, '2025-06-15 00:44:11', '2025-06-15 00:44:11'),
(120, 198211, 5, 3, 'Customer not interested.', '2025-06-13 00:44:11', NULL, '2025-06-15 00:44:11', '2025-06-15 00:44:11'),
(121, 198209, 9, 6, 'Asked not to be contacted again.', '2025-06-12 00:44:11', NULL, '2025-06-15 00:44:11', '2025-06-15 00:44:11'),
(122, 198212, 9, 2, 'Interested in health coverage.', '2025-06-11 00:44:11', NULL, '2025-06-15 00:44:11', '2025-06-15 00:44:11'),
(123, 198210, 8, 4, 'Will follow up next week.', '2025-06-10 00:44:11', NULL, '2025-06-15 00:44:11', '2025-06-15 00:44:11'),
(124, 198213, 8, 5, 'Sold home insurance policy.', '2025-06-09 00:44:11', NULL, '2025-06-15 00:44:11', '2025-06-15 00:44:11');

-- --------------------------------------------------------

--
-- Table structure for table `language_codes`
--

CREATE TABLE `language_codes` (
  `code` char(2) NOT NULL,
  `description` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `language_codes`
--

INSERT INTO `language_codes` (`code`, `description`) VALUES
('E1', 'English'),
('S8', 'Spanish');

-- --------------------------------------------------------

--
-- Table structure for table `leads`
--

CREATE TABLE `leads` (
  `id` int(11) NOT NULL,
  `external_id` varchar(100) NOT NULL,
  `prefix` varchar(20) DEFAULT NULL,
  `first_name` varchar(50) NOT NULL,
  `mi` varchar(10) DEFAULT NULL,
  `last_name` varchar(50) NOT NULL,
  `phone` varchar(20) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address_line` varchar(150) DEFAULT NULL,
  `suite_apt` varchar(50) DEFAULT NULL,
  `city` varchar(50) DEFAULT NULL,
  `state` char(2) DEFAULT NULL,
  `zip5` char(5) DEFAULT NULL,
  `zip4` char(4) DEFAULT NULL,
  `delivery_point_bar_code` varchar(20) DEFAULT NULL,
  `carrier_route` varchar(20) DEFAULT NULL,
  `fips_county_code` char(5) DEFAULT NULL,
  `county_name` varchar(50) DEFAULT NULL,
  `age` int(11) DEFAULT NULL,
  `insurance_interest_id` int(11) DEFAULT NULL,
  `source_id` int(11) NOT NULL,
  `do_not_call` tinyint(1) NOT NULL DEFAULT 0,
  `taken_by` int(11) DEFAULT NULL,
  `taken_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `income` varchar(10) DEFAULT NULL,
  `language` varchar(10) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `uploaded_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `leads`
--

INSERT INTO `leads` (`id`, `external_id`, `prefix`, `first_name`, `mi`, `last_name`, `phone`, `email`, `address_line`, `suite_apt`, `city`, `state`, `zip5`, `zip4`, `delivery_point_bar_code`, `carrier_route`, `fips_county_code`, `county_name`, `age`, `insurance_interest_id`, `source_id`, `do_not_call`, `taken_by`, `taken_at`, `created_at`, `updated_at`, `income`, `language`, `notes`, `uploaded_by`) VALUES
(1, 'lead_1', NULL, 'Alice', NULL, 'Johnson', '1234567890', 'alice.johnson@example.com', '123 Main St', NULL, 'City A', 'GA', '30001', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, 0, 1, '2025-06-13 19:45:00', '2025-06-13 19:45:00', '2025-07-16 18:49:16', 'D', 'E1', 'Interested in health insurance', 5),
(2, 'lead_2', NULL, 'Bob', NULL, 'Williams', '2345678901', 'bob.williams@example.com', '456 Oak St', NULL, 'City B', 'GA', '30002', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 2, 0, 2, '2025-06-13 19:45:00', '2025-06-13 19:45:00', '2025-07-16 18:49:16', 'C', 'S8', 'Interested in home insurance', 5),
(3, 'lead_3', NULL, 'Charlie', NULL, 'Brown', '3456789012', 'charlie.brown@example.com', '789 Pine St', NULL, 'City C', 'GA', '30003', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 3, 0, 1, '2025-06-13 19:45:00', '2025-06-13 19:45:00', '2025-07-16 18:49:16', 'E', 'E1', 'Not interested in insurance', 5),
(198107, 'lead_682e936ce01889.79413787', 'MS', 'JANELLE', '', 'COLON', '4703992241', NULL, '15 FRANKLIN ST', 'UNIT 4', 'AVONDALE ESTATES', 'GA', '30002', '900', '42', 'C770', '13089', 'DEKALB', 46, 4, 4, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-07-16 18:49:16', 'D', 'E1', NULL, 5),
(198108, 'lead_682e936ce1a9a1.80711298', '', 'CHYNNA', 'J', 'CRUZ', '9176937257', NULL, '3352 ARCHGATE CT', '', 'ALPHARETTA', 'GA', '30004', '636', '521', 'R063', '13121', 'FULTON', 33, 4, 4, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-07-16 18:49:16', 'C', 'S8', NULL, 5),
(198109, 'lead_682e936ce1dae9.69794656', 'MR', 'MARGARITO', '', 'VARGASLOPEZ', '3055544866', NULL, '6465 ATLANTA HWY', 'LOT 3E', 'ALPHARETTA', 'GA', '30004', '707', '351', 'R146', '13121', 'FULTON', 53, 4, 4, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-07-16 18:49:16', 'E', 'S8', NULL, 5),
(198110, 'lead_682e936ce20491.54893928', 'MS', 'JAZMINE', '', 'ECHEVARRIA', '7703314132', NULL, '1513 OLD JONES RD', '', 'ALPHARETTA', 'GA', '30004', '2319', '134', 'R117', '13121', 'FULTON', 23, 4, 4, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-07-16 18:49:16', 'D', 'E1', NULL, 5),
(198111, 'lead_682e936ce40f76.27301633', 'MS', 'PATRICIA', '', 'GARCIA', '4045141050', NULL, '14295 BIRMINGHAM HWY', '', 'ALPHARETTA', 'GA', '30004', '3018', '957', 'R024', '13121', 'FULTON', 54, 4, 4, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-07-16 18:49:16', 'C', 'E1', NULL, 5),
(198112, 'lead_682e936ce43c71.21248717', 'MR', 'HECTOR', '', 'CASANOVA', '7704809554', NULL, '6465 ATLANTA HWY', 'LOT 4H', 'ALPHARETTA', 'GA', '30004', '3334', '488', 'R146', '13121', 'FULTON', 35, 4, 4, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-07-16 18:49:16', 'C', 'S8', NULL, 5),
(198113, 'lead_682e936ce47b46.45100126', 'MR', 'REINALDO', '', 'DA HORA', '6784995489', NULL, '602 MCFARLAND 400 DR', '', 'ALPHARETTA', 'GA', '30004', '3374', '24', 'R106', '13121', 'FULTON', 22, 4, 4, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-07-16 18:49:16', 'E', 'S8', NULL, 5),
(198114, 'lead_682e936ce4bb39.70784771', 'MR', 'FRANCISCO', '', 'HERRERA', '6302352514', NULL, '8185 INDUSTRIAL PL', '', 'ALPHARETTA', 'GA', '30004', '3381', '855', 'R106', '13121', 'FULTON', 48, 4, 4, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-07-16 18:49:16', 'E', 'S8', NULL, 5),
(198115, 'lead_682e936ce4ea79.10205495', 'MR', 'ANDRES', '', 'MARTINEZ', '4043758508', NULL, '20218 DEER TRL', '', 'ALPHARETTA', 'GA', '30004', '5017', '181', 'R110', '13121', 'FULTON', 53, 4, 4, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-07-16 18:49:16', 'E', 'S8', NULL, 5),
(198116, 'lead_682e936ce51a56.74433612', 'MS', 'ANNA', '', 'ALONSO', '8133357394', NULL, '22001 DEER TRL', '', 'ALPHARETTA', 'GA', '30004', '5085', '14', 'R110', '13121', 'FULTON', 45, 4, 4, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-07-16 18:49:16', 'D', 'E1', NULL, 5),
(198117, 'lead_682e936ce54ed3.42679338', 'MR', 'JORGE', '', 'RUIZ', '2292919907', NULL, '2014 LAKE UNION HILL WAY', '', 'ALPHARETTA', 'GA', '30004', '7457', '145', 'R126', '13121', 'FULTON', 52, 4, 4, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-07-16 18:49:16', 'E', 'S8', NULL, 5),
(198118, 'lead_682e936ce57aa4.72185817', 'MS', 'BEATRIZ', '', 'VILLALBA', '4049404380', NULL, '13250 KEMPER RD', '', 'ALPHARETTA', 'GA', '30004', '7637', '505', 'R136', '13121', 'FULTON', 56, 4, 4, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-07-16 18:49:16', 'F', 'S8', NULL, 5),
(198119, 'lead_682e936ce5a871.06068638', 'MS', 'ALEJANDRA', '', 'CARRAU', '4049063991', NULL, '3322 DEER TRL', '', 'ALPHARETTA', 'GA', '30004', '8562', '228', 'R110', '13121', 'FULTON', 46, 4, 4, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-07-16 18:49:16', 'D', 'S8', NULL, 5),
(198120, 'lead_682e936ce5d5b7.01607310', 'MS', 'ANGELICA', '', 'JIMENEZ', '5162506785', NULL, '7102 DEER CREEK PL', '', 'ALPHARETTA', 'GA', '30004', '5034', '29', 'R149', '13121', 'FULTON', 40, 4, 4, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-07-16 18:49:16', 'D', 'S8', NULL, 5),
(198121, 'lead_682e936ce5f782.24685594', '', 'CHRIS', 'M', 'DIAZ', '2544991606', NULL, '10325 DEER TRL', '', 'ALPHARETTA', 'GA', '30004', '8586', '259', 'R110', '13121', 'FULTON', 40, 4, 4, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-07-16 18:49:16', 'E', 'E1', NULL, 5),
(198122, 'lead_682e936ce61749.00399265', 'MR', 'JOSE', '', 'RAMIREZ', '6784997518', NULL, '735 DEERFIELD PT', '', 'ALPHARETTA', 'GA', '30004', '8937', '358', 'R128', '13121', 'FULTON', 50, 4, 4, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-07-16 18:49:16', 'C', 'S8', NULL, 5),
(198123, 'lead_682e936ce63654.69081258', 'MR', 'EUCARIS', '', 'CONTRERAS', '4048899306', NULL, '1624 DEERFIELD PT', '', 'ALPHARETTA', 'GA', '30004', '8956', '249', 'R128', '13121', 'FULTON', 52, 4, 4, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-07-16 18:49:16', 'D', 'S8', NULL, 5),
(198124, 'lead_682e936ce65247.36038052', 'MS', 'KARINA', 'I', 'CONTRERAS', '4045520678', NULL, '12881 DEER PARK LN', '', 'ALPHARETTA', 'GA', '30004', '8986', '813', 'R136', '13121', 'FULTON', 57, 4, 4, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-07-16 18:49:16', 'F', 'S8', NULL, 5),
(198125, 'lead_682e936ce66e65.11909909', 'MR', 'JUAN', 'F', 'MARTINEZ', '7705279194', NULL, '12101 CYPRESS CT', '', 'ALPHARETTA', 'GA', '30005', '3586', '19', 'R069', '13121', 'FULTON', 53, 4, 4, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-07-16 18:49:16', 'E', 'S8', NULL, 5),
(198126, 'lead_682e936ce68727.35164911', '', 'SANDRA', '', 'NAVARRO', '6512748557', NULL, '1816 ADDISON LN', '', 'ALPHARETTA', 'GA', '30005', '5001', '169', 'R055', '13121', 'FULTON', 33, 4, 4, 1, NULL, NULL, '2025-05-21 23:01:00', '2025-07-16 18:49:16', 'E', 'S8', NULL, 5),
(198131, 'lead_001', NULL, 'John', NULL, 'Doe', '5550101', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 5, 0, 8, '2025-06-01 10:00:00', '2025-05-20 09:00:00', '2025-07-16 18:49:16', NULL, NULL, NULL, 5),
(198132, 'lead_002', NULL, 'Jane', NULL, 'Smith', '5550102', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 5, 0, 8, '2025-06-02 11:00:00', '2025-05-21 09:00:00', '2025-07-16 18:49:16', NULL, NULL, NULL, 5),
(198133, 'lead_003', NULL, 'Bob', NULL, 'Johnson', '5550103', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 5, 0, 9, '2025-06-03 12:00:00', '2025-05-22 09:00:00', '2025-07-16 18:49:16', NULL, NULL, NULL, 5),
(198134, 'lead_004', NULL, 'Alice', NULL, 'Brown', '5550104', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 5, 0, 9, '2025-06-04 13:00:00', '2025-05-23 09:00:00', '2025-07-16 18:49:16', NULL, NULL, NULL, 5),
(198198, 'test_lead_010', NULL, 'Test', NULL, 'Ten', '5551110010', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, 4, 0, 5, '2025-06-08 21:54:51', '2025-06-13 21:54:51', '2025-07-16 18:49:16', NULL, NULL, NULL, 5),
(198199, 'test_lead_011', NULL, 'Test', NULL, 'Eleven', '5551110011', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 2, 4, 0, 6, '2025-06-09 21:54:51', '2025-06-13 21:54:51', '2025-07-16 18:49:16', NULL, NULL, NULL, 5),
(198200, 'test_lead_012', NULL, 'Test', NULL, 'Twelve', '5551110012', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 3, 4, 0, 7, '2025-06-10 21:54:51', '2025-06-13 21:54:51', '2025-07-16 18:49:16', NULL, NULL, NULL, 5),
(198201, 'lead_010', NULL, 'John', NULL, 'Doe', '5550101', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, 4, 0, 5, '2025-06-15 00:30:35', '2025-06-15 00:30:35', '2025-07-16 18:49:16', NULL, NULL, NULL, 5),
(198202, 'lead_011', NULL, 'Jane', NULL, 'Smith', '5550102', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 2, 4, 0, 5, '2025-06-15 00:30:35', '2025-06-15 00:30:35', '2025-07-16 18:49:16', NULL, NULL, NULL, 5),
(198203, 'lead_012', NULL, 'Bob', NULL, 'Johnson', '5550103', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 3, 4, 0, 5, '2025-06-15 00:30:35', '2025-06-15 00:30:35', '2025-07-16 18:49:16', NULL, NULL, NULL, 5),
(198204, 'lead_013', NULL, 'Alice', NULL, 'Brown', '5550104', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 4, 4, 0, 5, '2025-06-15 00:30:35', '2025-06-15 00:30:35', '2025-07-16 18:49:16', NULL, NULL, NULL, 5),
(198205, 'lead_test_001', NULL, 'John', NULL, 'Doe', '5550101', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 1, 4, 0, 5, '2025-06-15 00:34:52', '2025-06-15 00:34:52', '2025-07-16 18:49:16', NULL, NULL, NULL, 5),
(198207, 'lead_test_042', NULL, 'John', NULL, 'Doe', '5550101', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 4, 4, 0, 5, '2025-06-15 00:40:11', '2025-06-15 00:40:11', '2025-07-16 18:49:16', NULL, NULL, NULL, 5),
(198208, 'test_lead_101', NULL, 'John', NULL, 'Doe', '5550101', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 4, 4, 0, 5, '2025-06-15 00:44:11', '2025-06-15 00:44:11', '2025-07-16 18:49:16', NULL, NULL, NULL, 5),
(198209, 'test_lead_102', NULL, 'Jane', NULL, 'Smith', '5550102', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 4, 4, 0, 9, '2025-06-15 00:44:11', '2025-06-15 00:44:11', '2025-07-16 18:49:16', NULL, NULL, NULL, 5),
(198210, 'test_lead_103', NULL, 'Bob', NULL, 'Johnson', '5550103', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 4, 4, 0, 8, '2025-06-15 00:44:11', '2025-06-15 00:44:11', '2025-07-16 18:49:16', NULL, NULL, NULL, 5),
(198211, 'test_lead_104', NULL, 'Alice', NULL, 'Brown', '5550104', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 4, 4, 0, 5, '2025-06-15 00:44:11', '2025-06-15 00:44:11', '2025-07-16 18:49:16', NULL, NULL, NULL, 5),
(198212, 'test_lead_105', NULL, 'Charlie', NULL, 'Davis', '5550105', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 4, 4, 0, 9, '2025-06-15 00:44:11', '2025-06-15 00:44:11', '2025-07-16 18:49:16', NULL, NULL, NULL, 5),
(198213, 'test_lead_106', NULL, 'Emma', NULL, 'Wilson', '5550106', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 4, 4, 0, 8, '2025-06-15 00:44:11', '2025-06-15 00:44:11', '2025-07-16 18:49:16', NULL, NULL, NULL, 5);

-- --------------------------------------------------------

--
-- Table structure for table `lead_documents`
--

CREATE TABLE `lead_documents` (
  `id` int(11) NOT NULL,
  `lead_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(512) NOT NULL,
  `file_type` varchar(100) DEFAULT NULL,
  `uploaded_by` int(11) NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `lead_documents`
--

INSERT INTO `lead_documents` (`id`, `lead_id`, `title`, `file_name`, `file_path`, `file_type`, `uploaded_by`, `uploaded_at`) VALUES
(1, 198126, 'coldcall_db(4)', 'coldcall_db(4).sql', '/../uploads/lead_documents/doc_68387143c4d5c.sql', 'application/octet-stream', 5, '2025-05-29 10:37:55'),
(2, 198126, 'asdasdqe-5', 'asdasdqe-5.png', '/../uploads/lead_documents/doc_68388500bdaa9.png', 'image/png', 5, '2025-05-29 12:02:08');

-- --------------------------------------------------------

--
-- Table structure for table `lead_locks`
--

CREATE TABLE `lead_locks` (
  `lead_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `locked_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `lead_sources`
--

CREATE TABLE `lead_sources` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `lead_sources`
--

INSERT INTO `lead_sources` (`id`, `name`, `description`, `active`, `created_at`) VALUES
(1, 'Web Form', 'Captura web', 1, '2025-05-14 18:06:03'),
(2, 'Event', 'Evento presencial', 1, '2025-05-14 18:06:03'),
(3, 'Referral', 'Recomendación', 1, '2025-05-14 18:06:03'),
(4, 'Purchased Leads', 'Paquete de leads comprados', 1, '2025-05-15 13:21:45'),
(5, 'Sample Source', 'Used for testing', 1, '2025-06-13 16:28:26');

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `name`, `description`, `created_at`) VALUES
(1, 'admin', 'Acceso completo', '2025-05-14 18:06:03'),
(2, 'lead_manager', 'Gestión de leads', '2025-05-14 18:06:03'),
(3, 'sales', 'Representante de ventas', '2025-05-14 18:06:03'),
(4, 'viewer', 'Solo lectura', '2025-05-14 18:06:03'),
(5, 'owner', 'Project owner.', '2025-06-12 12:24:01');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password_hash`, `status`, `created_at`, `updated_at`) VALUES
(1, 'John Doe', 'johndoe@example.com', '$2y$10$WUXqsEhY/tuSyUVuyzxfbOdk5lyIS7u6moL1Y8VOucX.Ve.R0aene', 'active', '2025-06-13 19:45:00', '2025-06-13 19:45:00'),
(2, 'Jane Smith', 'janesmith@example.com', '$2y$10$WUXqsEhY/tuSyUVuyzxfbOdk5lyIS7u6moL1Y8VOucX.Ve.R0aene', 'active', '2025-06-13 19:45:00', '2025-06-13 19:45:00'),
(4, 'Dave Viewer', 'dave@empresa.local', '$2y$10$4BlrP0.lA1vu18Vr6I93r.baMXZGVdhGm8GuIes84qBAW7BFcKEDW', 'active', '2025-05-14 18:06:03', '2025-05-19 21:57:03'),
(5, 'Enmanuel Domínguez', 'enmandom@gmail.com', '$2y$10$1R/sc2snF3ISAX5BdiD7VeY/PW.Sn0ANz8d91OeX9/UvWmPiZWJ9y', 'active', '2025-05-14 22:50:54', '2025-05-14 22:50:54'),
(6, 'enmagent', 'bluefang05@gmail.com', '$2y$10$WUXqsEhY/tuSyUVuyzxfbOdk5lyIS7u6moL1Y8VOucX.Ve.R0aene', 'active', '2025-05-19 15:22:39', '2025-05-19 21:30:53'),
(7, 'Mami Boss', 'mami@empresa.local', '$2y$10$4BlrP0.lA1vu18Vr6I93r.baMXZGVdhGm8GuIes84qBAW7BFcKEDW', 'active', '2025-06-13 16:25:30', '2025-06-13 16:25:30'),
(8, 'Manuel Sales', 'manuel@empresa.local', '$2y$10$4BlrP0.lA1vu18Vr6I93r.baMXZGVdhGm8GuIes84qBAW7BFcKEDW', 'active', '2025-06-13 16:25:30', '2025-06-13 16:25:30'),
(9, 'Carlos Caller', 'carlos@empresa.local', '$2y$10$4BlrP0.lA1vu18Vr6I93r.baMXZGVdhGm8GuIes84qBAW7BFcKEDW', 'active', '2025-06-13 16:25:30', '2025-06-13 16:25:30'),
(12, 'Carlos Caller', 'carlos.caller@example.com', '$2y$10$WUXqsEhY/tuSyUVuyzxfbOdk5lyIS7u6moL1Y8VOucX.Ve.R0aene', 'active', '2025-06-13 19:58:07', '2025-06-13 19:58:07'),
(13, 'Manuel Sales', 'manuel.sales@example.com', '$2y$10$WUXqsEhY/tuSyUVuyzxfbOdk5lyIS7u6moL1Y8VOucX.Ve.R0aene', 'active', '2025-06-13 19:58:07', '2025-06-13 19:58:07'),
(14, 'Agent Alpha', 'alpha@test.local', '$2y$10$dummyhash1234567890', 'active', '2025-06-13 21:10:32', '2025-06-13 21:10:32'),
(15, 'Agent Beta', 'beta@test.local', '$2y$10$dummyhash1234567890', 'active', '2025-06-13 21:10:32', '2025-06-13 21:10:32'),
(16, 'Agent Gamma', 'gamma@test.local', '$2y$10$dummyhash1234567890', 'active', '2025-06-13 21:10:32', '2025-06-13 21:10:32'),
(17, 'Agent Delta', 'delta@test.local', '$2y$10$dummyhash1234567890', 'active', '2025-06-13 21:10:32', '2025-06-13 21:10:32');

-- --------------------------------------------------------

--
-- Table structure for table `user_roles`
--

CREATE TABLE `user_roles` (
  `user_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `user_roles`
--

INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES
(1, 5),
(4, 2),
(5, 1),
(6, 3),
(7, 5),
(8, 3),
(9, 3),
(12, 3),
(13, 3);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_audit_user` (`user_id`);

--
-- Indexes for table `dispositions`
--
ALTER TABLE `dispositions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_dispositions_name` (`name`);

--
-- Indexes for table `income_ranges`
--
ALTER TABLE `income_ranges`
  ADD PRIMARY KEY (`code`);

--
-- Indexes for table `insurance_interests`
--
ALTER TABLE `insurance_interests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_insurance_interests_name` (`name`);

--
-- Indexes for table `interactions`
--
ALTER TABLE `interactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_interactions_lead` (`lead_id`),
  ADD KEY `fk_interactions_user` (`user_id`),
  ADD KEY `fk_interactions_disposition` (`disposition_id`),
  ADD KEY `idx_interactions_time` (`interaction_time`);

--
-- Indexes for table `language_codes`
--
ALTER TABLE `language_codes`
  ADD PRIMARY KEY (`code`);

--
-- Indexes for table `leads`
--
ALTER TABLE `leads`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_leads_external` (`external_id`),
  ADD KEY `fk_leads_interest` (`insurance_interest_id`),
  ADD KEY `fk_leads_source` (`source_id`),
  ADD KEY `idx_leads_taken` (`taken_by`,`taken_at`),
  ADD KEY `idx_leads_phone` (`phone`),
  ADD KEY `idx_leads_do_not_call` (`do_not_call`),
  ADD KEY `fk_leads_uploaded_by` (`uploaded_by`);

--
-- Indexes for table `lead_documents`
--
ALTER TABLE `lead_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_lead_id` (`lead_id`),
  ADD KEY `fk_uploaded_by` (`uploaded_by`);

--
-- Indexes for table `lead_locks`
--
ALTER TABLE `lead_locks`
  ADD PRIMARY KEY (`lead_id`),
  ADD KEY `fk_lead_locks_user` (`user_id`);

--
-- Indexes for table `lead_sources`
--
ALTER TABLE `lead_sources`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_lead_sources_name` (`name`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_roles_name` (`name`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_email` (`email`);

--
-- Indexes for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD PRIMARY KEY (`user_id`,`role_id`),
  ADD KEY `fk_user_roles_role` (`role_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `dispositions`
--
ALTER TABLE `dispositions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `insurance_interests`
--
ALTER TABLE `insurance_interests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `interactions`
--
ALTER TABLE `interactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=125;

--
-- AUTO_INCREMENT for table `leads`
--
ALTER TABLE `leads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=198214;

--
-- AUTO_INCREMENT for table `lead_documents`
--
ALTER TABLE `lead_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `lead_sources`
--
ALTER TABLE `lead_sources`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `audit_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `interactions`
--
ALTER TABLE `interactions`
  ADD CONSTRAINT `interactions_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `interactions_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `interactions_ibfk_3` FOREIGN KEY (`disposition_id`) REFERENCES `dispositions` (`id`);

--
-- Constraints for table `leads`
--
ALTER TABLE `leads`
  ADD CONSTRAINT `fk_leads_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leads_ibfk_1` FOREIGN KEY (`insurance_interest_id`) REFERENCES `insurance_interests` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leads_ibfk_2` FOREIGN KEY (`source_id`) REFERENCES `lead_sources` (`id`),
  ADD CONSTRAINT `leads_ibfk_4` FOREIGN KEY (`taken_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `lead_documents`
--
ALTER TABLE `lead_documents`
  ADD CONSTRAINT `fk_document_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `fk_lead_id` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `lead_locks`
--
ALTER TABLE `lead_locks`
  ADD CONSTRAINT `lead_locks_ibfk_1` FOREIGN KEY (`lead_id`) REFERENCES `leads` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `lead_locks_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD CONSTRAINT `user_roles_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `user_roles_ibfk_2` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
