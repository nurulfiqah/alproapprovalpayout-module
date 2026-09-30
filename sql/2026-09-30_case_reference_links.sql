-- ============================================================
-- 2026-09-30 - Reference Links on the Add New Evidence panel
-- Run against production to catch up to sql/aap_master.sql.
-- ============================================================

CREATE TABLE `aap_case_reference_links` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `case_id` INT UNSIGNED NOT NULL,
  `url` TEXT NOT NULL,
  `label` VARCHAR(150) NOT NULL,
  `created_by` INT NOT NULL,
  `timestamp` DATETIME NOT NULL,
  KEY `idx_aap_ref_links_case` (`case_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
