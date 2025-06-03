-- phpMyAdmin SQL Dump
-- version 5.1.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 29, 2025 at 03:42 PM
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
(4, 'Follow Up', '2025-05-14 18:06:03');

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
  `status_id` int(11) NOT NULL DEFAULT 1,
  `do_not_call` tinyint(1) NOT NULL DEFAULT 0,
  `taken_by` int(11) DEFAULT NULL,
  `taken_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `income` varchar(10) DEFAULT NULL,
  `language` varchar(10) DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `leads`
--

INSERT INTO `leads` (`id`, `external_id`, `prefix`, `first_name`, `mi`, `last_name`, `phone`, `email`, `address_line`, `suite_apt`, `city`, `state`, `zip5`, `zip4`, `delivery_point_bar_code`, `carrier_route`, `fips_county_code`, `county_name`, `age`, `insurance_interest_id`, `source_id`, `status_id`, `do_not_call`, `taken_by`, `taken_at`, `created_at`, `updated_at`, `income`, `language`, `notes`) VALUES
(198107, 'lead_682e936ce01889.79413787', 'MS', 'JANELLE', '', 'COLON', '4703992241', NULL, '15 FRANKLIN ST', 'UNIT 4', 'AVONDALE ESTATES', 'GA', '30002', '900', '42', 'C770', '13089', 'DEKALB', 46, 4, 4, 1, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-05-21 23:01:00', 'D', 'E1', NULL),
(198108, 'lead_682e936ce1a9a1.80711298', '', 'CHYNNA', 'J', 'CRUZ', '9176937257', NULL, '3352 ARCHGATE CT', '', 'ALPHARETTA', 'GA', '30004', '636', '521', 'R063', '13121', 'FULTON', 33, 4, 4, 1, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-05-21 23:01:00', 'C', 'S8', NULL),
(198109, 'lead_682e936ce1dae9.69794656', 'MR', 'MARGARITO', '', 'VARGASLOPEZ', '3055544866', NULL, '6465 ATLANTA HWY', 'LOT 3E', 'ALPHARETTA', 'GA', '30004', '707', '351', 'R146', '13121', 'FULTON', 53, 4, 4, 1, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-05-21 23:01:00', 'E', 'S8', NULL),
(198110, 'lead_682e936ce20491.54893928', 'MS', 'JAZMINE', '', 'ECHEVARRIA', '7703314132', NULL, '1513 OLD JONES RD', '', 'ALPHARETTA', 'GA', '30004', '2319', '134', 'R117', '13121', 'FULTON', 23, 4, 4, 1, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-05-21 23:01:00', 'D', 'E1', NULL),
(198111, 'lead_682e936ce40f76.27301633', 'MS', 'PATRICIA', '', 'GARCIA', '4045141050', NULL, '14295 BIRMINGHAM HWY', '', 'ALPHARETTA', 'GA', '30004', '3018', '957', 'R024', '13121', 'FULTON', 54, 4, 4, 1, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-05-21 23:01:00', 'C', 'E1', NULL),
(198112, 'lead_682e936ce43c71.21248717', 'MR', 'HECTOR', '', 'CASANOVA', '7704809554', NULL, '6465 ATLANTA HWY', 'LOT 4H', 'ALPHARETTA', 'GA', '30004', '3334', '488', 'R146', '13121', 'FULTON', 35, 4, 4, 1, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-05-21 23:01:00', 'C', 'S8', NULL),
(198113, 'lead_682e936ce47b46.45100126', 'MR', 'REINALDO', '', 'DA HORA', '6784995489', NULL, '602 MCFARLAND 400 DR', '', 'ALPHARETTA', 'GA', '30004', '3374', '24', 'R106', '13121', 'FULTON', 22, 4, 4, 1, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-05-21 23:01:00', 'E', 'S8', NULL),
(198114, 'lead_682e936ce4bb39.70784771', 'MR', 'FRANCISCO', '', 'HERRERA', '6302352514', NULL, '8185 INDUSTRIAL PL', '', 'ALPHARETTA', 'GA', '30004', '3381', '855', 'R106', '13121', 'FULTON', 48, 4, 4, 1, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-05-21 23:01:00', 'E', 'S8', NULL),
(198115, 'lead_682e936ce4ea79.10205495', 'MR', 'ANDRES', '', 'MARTINEZ', '4043758508', NULL, '20218 DEER TRL', '', 'ALPHARETTA', 'GA', '30004', '5017', '181', 'R110', '13121', 'FULTON', 53, 4, 4, 1, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-05-21 23:01:00', 'E', 'S8', NULL),
(198116, 'lead_682e936ce51a56.74433612', 'MS', 'ANNA', '', 'ALONSO', '8133357394', NULL, '22001 DEER TRL', '', 'ALPHARETTA', 'GA', '30004', '5085', '14', 'R110', '13121', 'FULTON', 45, 4, 4, 1, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-05-21 23:01:00', 'D', 'E1', NULL),
(198117, 'lead_682e936ce54ed3.42679338', 'MR', 'JORGE', '', 'RUIZ', '2292919907', NULL, '2014 LAKE UNION HILL WAY', '', 'ALPHARETTA', 'GA', '30004', '7457', '145', 'R126', '13121', 'FULTON', 52, 4, 4, 1, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-05-21 23:01:00', 'E', 'S8', NULL),
(198118, 'lead_682e936ce57aa4.72185817', 'MS', 'BEATRIZ', '', 'VILLALBA', '4049404380', NULL, '13250 KEMPER RD', '', 'ALPHARETTA', 'GA', '30004', '7637', '505', 'R136', '13121', 'FULTON', 56, 4, 4, 1, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-05-21 23:01:00', 'F', 'S8', NULL),
(198119, 'lead_682e936ce5a871.06068638', 'MS', 'ALEJANDRA', '', 'CARRAU', '4049063991', NULL, '3322 DEER TRL', '', 'ALPHARETTA', 'GA', '30004', '8562', '228', 'R110', '13121', 'FULTON', 46, 4, 4, 1, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-05-21 23:01:00', 'D', 'S8', NULL),
(198120, 'lead_682e936ce5d5b7.01607310', 'MS', 'ANGELICA', '', 'JIMENEZ', '5162506785', NULL, '7102 DEER CREEK PL', '', 'ALPHARETTA', 'GA', '30004', '5034', '29', 'R149', '13121', 'FULTON', 40, 4, 4, 1, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-05-21 23:01:00', 'D', 'S8', NULL),
(198121, 'lead_682e936ce5f782.24685594', '', 'CHRIS', 'M', 'DIAZ', '2544991606', NULL, '10325 DEER TRL', '', 'ALPHARETTA', 'GA', '30004', '8586', '259', 'R110', '13121', 'FULTON', 40, 4, 4, 1, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-05-21 23:01:00', 'E', 'E1', NULL),
(198122, 'lead_682e936ce61749.00399265', 'MR', 'JOSE', '', 'RAMIREZ', '6784997518', NULL, '735 DEERFIELD PT', '', 'ALPHARETTA', 'GA', '30004', '8937', '358', 'R128', '13121', 'FULTON', 50, 4, 4, 1, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-05-21 23:01:00', 'C', 'S8', NULL),
(198123, 'lead_682e936ce63654.69081258', 'MR', 'EUCARIS', '', 'CONTRERAS', '4048899306', NULL, '1624 DEERFIELD PT', '', 'ALPHARETTA', 'GA', '30004', '8956', '249', 'R128', '13121', 'FULTON', 52, 4, 4, 1, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-05-21 23:01:00', 'D', 'S8', NULL),
(198124, 'lead_682e936ce65247.36038052', 'MS', 'KARINA', 'I', 'CONTRERAS', '4045520678', NULL, '12881 DEER PARK LN', '', 'ALPHARETTA', 'GA', '30004', '8986', '813', 'R136', '13121', 'FULTON', 57, 4, 4, 1, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-05-21 23:01:00', 'F', 'S8', NULL),
(198125, 'lead_682e936ce66e65.11909909', 'MR', 'JUAN', 'F', 'MARTINEZ', '7705279194', NULL, '12101 CYPRESS CT', '', 'ALPHARETTA', 'GA', '30005', '3586', '19', 'R069', '13121', 'FULTON', 53, 4, 4, 1, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-05-21 23:01:00', 'E', 'S8', NULL),
(198126, 'lead_682e936ce68727.35164911', '', 'SANDRA', '', 'NAVARRO', '6512748557', NULL, '1816 ADDISON LN', '', 'ALPHARETTA', 'GA', '30005', '5001', '169', 'R055', '13121', 'FULTON', 33, 4, 4, 1, 0, NULL, NULL, '2025-05-21 23:01:00', '2025-05-21 23:01:00', 'E', 'S8', NULL);

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
(4, 'Purchased Leads', 'Paquete de leads comprados', 1, '2025-05-15 13:21:45');

-- --------------------------------------------------------

--
-- Table structure for table `lead_statuses`
--

CREATE TABLE `lead_statuses` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `lead_statuses`
--

INSERT INTO `lead_statuses` (`id`, `name`, `created_at`) VALUES
(1, 'New', '2025-05-14 18:06:03'),
(2, 'Contacted', '2025-05-14 18:06:03'),
(3, 'Qualified', '2025-05-14 18:06:03'),
(4, 'Closed', '2025-05-14 18:06:03');

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
(4, 'viewer', 'Solo lectura', '2025-05-14 18:06:03');

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
(4, 'Dave Viewer', 'dave@empresa.local', '$2y$10$4BlrP0.lA1vu18Vr6I93r.baMXZGVdhGm8GuIes84qBAW7BFcKEDW', 'active', '2025-05-14 18:06:03', '2025-05-19 21:57:03'),
(5, 'Enmanuel Domínguez', 'enmandom@gmail.com', '$2y$10$1R/sc2snF3ISAX5BdiD7VeY/PW.Sn0ANz8d91OeX9/UvWmPiZWJ9y', 'active', '2025-05-14 22:50:54', '2025-05-14 22:50:54'),
(6, 'enmagent', 'bluefang05@gmail.com', '$2y$10$WUXqsEhY/tuSyUVuyzxfbOdk5lyIS7u6moL1Y8VOucX.Ve.R0aene', 'active', '2025-05-19 15:22:39', '2025-05-19 21:30:53');

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
(4, 2),
(5, 1),
(6, 3);

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
  ADD KEY `fk_leads_status` (`status_id`),
  ADD KEY `idx_leads_taken` (`taken_by`,`taken_at`),
  ADD KEY `idx_leads_phone` (`phone`),
  ADD KEY `idx_leads_do_not_call` (`do_not_call`);

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
-- Indexes for table `lead_statuses`
--
ALTER TABLE `lead_statuses`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_lead_statuses_name` (`name`);

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
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `insurance_interests`
--
ALTER TABLE `insurance_interests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `interactions`
--
ALTER TABLE `interactions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leads`
--
ALTER TABLE `leads`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=198127;

--
-- AUTO_INCREMENT for table `lead_documents`
--
ALTER TABLE `lead_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `lead_sources`
--
ALTER TABLE `lead_sources`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `lead_statuses`
--
ALTER TABLE `lead_statuses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

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
  ADD CONSTRAINT `leads_ibfk_1` FOREIGN KEY (`insurance_interest_id`) REFERENCES `insurance_interests` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `leads_ibfk_2` FOREIGN KEY (`source_id`) REFERENCES `lead_sources` (`id`),
  ADD CONSTRAINT `leads_ibfk_3` FOREIGN KEY (`status_id`) REFERENCES `lead_statuses` (`id`),
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
