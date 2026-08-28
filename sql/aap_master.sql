-- ============================================================
-- AAP (Alpro Approval Protocol) — Case Management & Approval System
-- Schema dump — reference for column names/types, not a migration
-- system. If you change a table, update this dump to match.
--
-- Regenerated 2026-08-24 from the live schema (supersedes the old
-- aap_families/family_id-based version — Families were dropped in
-- favour of Case Types being scoped directly to a department via
-- aap_case_types.department_id; see sql/2026-08-19_drop_aap_families.sql
-- and sql/2026-08-19_add_department_id_to_aap_case_types.sql).
-- ============================================================

-- --------------------------------------------------------
-- aap_case_types
-- The Case Type Registry (Part D.2). Each row is a selectable case
-- type that drives the intake form, evidence requirement, physical
-- confirmation gate, and approval rule for every case raised against it.
-- Scoped to a department (staff_department, the outer app's shared
-- table) rather than the old Family grouping. Soft-deletable via
-- `recycle` — never hard-deleted, so historical cases keep referencing
-- retired types.
-- --------------------------------------------------------

CREATE TABLE `aap_case_types` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `department_id` INT UNSIGNED NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `code` VARCHAR(30) NOT NULL,
  `default_requester_type` ENUM('customer','outlet','bu') NOT NULL DEFAULT 'customer',
  `physical_confirm_required` TINYINT(1) NOT NULL DEFAULT 0,
  `approver_mode` ENUM('operations_tier','bu_signoff','cs_tier') NOT NULL DEFAULT 'operations_tier',
  `ops_tier_required` ENUM('executive','manager') NULL DEFAULT NULL,
  `turnaround_days` INT NULL DEFAULT NULL,
  `systems_note` VARCHAR(255) NULL,
  `description` VARCHAR(255) NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `recycle` TINYINT(1) NOT NULL DEFAULT 0,
  `timestamp` DATETIME NOT NULL,
  UNIQUE KEY `uq_aap_case_types_code` (`code`),
  KEY `idx_aap_case_types_department` (`department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Starter registry (department_id 27 = Customer Support in this
-- environment — check staff_department on the target environment before
-- reusing these ids as-is). Admin can add/edit/recycle further via
-- aap_admin.php.
INSERT INTO `aap_case_types` (`id`, `department_id`, `name`, `code`, `default_requester_type`, `physical_confirm_required`, `approver_mode`, `ops_tier_required`, `turnaround_days`, `systems_note`, `description`, `sort_order`, `recycle`, `timestamp`) VALUES
(1, 27, 'Customer Order Refund',              'CUST_ORDER_REFUND',    'customer', 1, 'operations_tier', NULL, 14, 'Shopify, OMC, CLS', 'Cancel / return / exchange on a customer order.', 1, 0, NOW()),
(2, 27, 'Outlet Rental / BU-Approved Refund',  'OUTLET_RENTAL_REFUND', 'outlet',   0, 'bu_signoff',      NULL,  3, 'CLS',                'Outlet-type refund approved directly by the owning BU before routing to Operations.', 2, 0, NOW()),
(3, 27, 'Membership Point Adjustment',         'MEMBERSHIP_ADJUST',    'customer', 0, 'operations_tier', NULL,  3, 'CLS',                'Point not credited / system bug — approved on full ID proof, no value threshold.', 3, 0, NOW()),
(4, 27, 'Membership Merge',                    'MEMBERSHIP_MERGE',     'outlet',   0, 'operations_tier', NULL, 14, 'CLS',                'Two accounts belong to the same identity — merge on relationship proof.', 4, 0, NOW()),
(5, 27, 'Campaign Points Adjustment',          'CAMPAIGN_ADJUST',      'outlet',   0, 'operations_tier', NULL,  5, 'CLS',                'Redemption not synced — evidence-based.', 5, 0, NOW()),
(6, 27, 'BU Programme Credit',                 'BU_PROGRAMME_CREDIT',  'bu',       0, 'bu_signoff',      NULL, 14, 'CLS',                'Business-unit-specific point credit — signed off by the owning BU.', 6, 0, NOW());

-- --------------------------------------------------------
-- aap_cases
-- The core case record — one row per raised case, carrying it through
-- the full lifecycle (Draft -> Open -> Physical Confirm -> Approval ->
-- Execution -> Closed/Rejected/Voided).
-- --------------------------------------------------------

CREATE TABLE `aap_cases` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `case_ref` VARCHAR(20) NOT NULL,
  `case_type_id` INT UNSIGNED NOT NULL,
  -- Originating Fixit ticket (fixit_record.id), when this case was raised
  -- via the Fixit handoff / Incoming from Fixit queue. NULL for cases
  -- raised without a Fixit ticket (not possible from the UI anymore, but
  -- kept nullable for any pre-existing rows / other future intake paths).
  `fixit_record_id` INT NULL DEFAULT NULL,
  `requester_type` ENUM('customer','outlet','bu') NOT NULL,
  `requesting_channel` VARCHAR(50) NOT NULL,
  `requester_staff_id` INT NOT NULL,
  `requester_department_id` INT NULL DEFAULT NULL,
  `customer_membership_id` VARCHAR(100) NULL,
  `transaction_ref` VARCHAR(100) NULL,
  `evidence_note` TEXT NULL,
  `calculated_value` DECIMAL(12,2) NULL DEFAULT NULL,
  `value_type` ENUM('cash','points') NULL DEFAULT NULL,
  `recommended_outcome` TEXT NULL,
  `physical_confirm_required` TINYINT(1) NOT NULL DEFAULT 0,
  `physical_confirm_status` ENUM('not_required','pending','tagged','confirmed') NOT NULL DEFAULT 'not_required',
  `physical_confirm_ref` VARCHAR(100) NULL,
  -- Exchange = item re-enters inventory via the warehouse return process
  -- (warehouse_return_ref), on top of the RFID/tag ref above. Return + Refund
  -- (return_only) never touches inventory - RFID ref only. Set together at
  -- the Tag Item step (see aap_update.php's tag_physical action).
  `physical_return_type` ENUM('return_only','exchange') NULL DEFAULT NULL,
  `warehouse_return_ref` VARCHAR(100) NULL DEFAULT NULL,
  `physical_tagged_by` INT NULL DEFAULT NULL,
  `physical_tagged_at` DATETIME NULL DEFAULT NULL,
  `physical_confirmed_by` INT NULL DEFAULT NULL,
  `physical_confirmed_at` DATETIME NULL DEFAULT NULL,
  `approver_mode` ENUM('operationsa_tier','bu_signoff','cs_tier') NOT NULL DEFAULT 'operations_tier',
  -- Copied from aap_case_types.ops_tier_required at case creation/edit time
  -- (same convention as approver_mode) - only meaningful for cs_tier, see
  -- aapCanApprove() in aap_lib.php.
  `ops_tier_required` ENUM('executive','manager') NULL DEFAULT NULL,
  `approval_tier` ENUM('executive','manager') NULL DEFAULT NULL,
  `approval_status` ENUM('pending','approved','corrected','rejected') NOT NULL DEFAULT 'pending',
  `approved_value` DECIMAL(12,2) NULL DEFAULT NULL,
  `approver_staff_id` INT NULL DEFAULT NULL,
  `approved_at` DATETIME NULL DEFAULT NULL,
  `approval_remark` VARCHAR(255) NULL,
  `execution_status` ENUM('pending','executed') NOT NULL DEFAULT 'pending',
  `execution_reference` VARCHAR(100) NULL,
  `executor_staff_id` INT NULL DEFAULT NULL,
  `executed_at` DATETIME NULL DEFAULT NULL,
  -- 'draft' = still gathering evidence, not yet in the approval workflow
  -- (see aap_add.php / index.php's Draft tab).
  `case_status` ENUM('draft','open','rejected','executed','closed','voided') NOT NULL DEFAULT 'draft',
  `created_by` INT NOT NULL,
  `timestamp` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  `closed_at` DATETIME NULL DEFAULT NULL,
  UNIQUE KEY `uq_aap_cases_ref` (`case_ref`),
  KEY `idx_aap_cases_type` (`case_type_id`),
  KEY `idx_aap_cases_status` (`case_status`),
  KEY `idx_aap_cases_created_by` (`created_by`),
  KEY `idx_aap_cases_fixit_record` (`fixit_record_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- aap_case_attachments
-- Evidence files per case. `stored_name` is the corporate NAS path
-- (see nas_config.php / lib/synologynas.php) — files only ever touch
-- local disk transiently (uploads/tmp/), never stored permanently under
-- uploads/.
-- --------------------------------------------------------

CREATE TABLE `aap_case_attachments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `case_id` INT UNSIGNED NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `stored_name` VARCHAR(255) NOT NULL,
  `uploaded_by` INT NOT NULL,
  `timestamp` DATETIME NOT NULL,
  KEY `idx_aap_attach_case` (`case_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- aap_case_notes
-- Additional notes added after case creation (via the "Add Evidence"
-- form on aap_update.php) — separate from the original
-- aap_cases.evidence_note captured at raise time.
-- --------------------------------------------------------

CREATE TABLE `aap_case_notes` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `case_id` INT UNSIGNED NOT NULL,
  `note` TEXT NOT NULL,
  `created_by` INT NOT NULL,
  `timestamp` DATETIME NOT NULL,
  KEY `idx_aap_notes_case` (`case_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- aap_audit_logs
-- Append-only event log per case — every status change, lock/unlock,
-- suspend/unsuspend, note/evidence add, etc. writes one row here.
-- --------------------------------------------------------

CREATE TABLE `aap_audit_logs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `case_id` INT UNSIGNED NOT NULL,
  `event` VARCHAR(50) NOT NULL,
  `actor_staff_id` INT NOT NULL,
  `summary` VARCHAR(255) NOT NULL,
  `changes` TEXT NULL,
  `timestamp` DATETIME NOT NULL,
  KEY `idx_aap_audit_case` (`case_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- aap_notifications
-- In-app notifications for the bell icon (aap_sidebar.php). One row per
-- event the case's issuer should be told about (`type`: case_approved,
-- case_rejected, case_closed) - see aapNotifyIssuer() in aap_lib.php.
-- --------------------------------------------------------

CREATE TABLE `aap_notifications` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `staff_id` INT NOT NULL,
  `case_id` INT UNSIGNED NOT NULL,
  `type` VARCHAR(50) NOT NULL,
  `read_at` DATETIME NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_aap_notif_staff` (`staff_id`),
  KEY `idx_aap_notif_case` (`case_id`),
  CONSTRAINT `fk_aap_notif_case` FOREIGN KEY (`case_id`) REFERENCES `aap_cases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- aap_staff_thresholds
-- Per-staff RM approval ceiling - replaced the old grade-based
-- Operations Executive/Manager tier and the CS Level 1/2 system entirely.
-- No row for a staff member means they have no approval rights at all;
-- a row with threshold_amount = NULL is an explicit "unlimited" ceiling
-- (can approve any value). Being in the right department "pool" for a
-- case's approver_mode (Operations / Customer Support / requester's own
-- department for BU Sign-off) is checked separately - see
-- aapGetStaffThreshold() / aapCanApprove() in aap_lib.php. Managed from
-- aap_admin.php's Staff Approval Ceilings panel.
-- --------------------------------------------------------

CREATE TABLE `aap_staff_thresholds` (
  `staff_id` INT UNSIGNED NOT NULL PRIMARY KEY,
  `threshold_amount` DECIMAL(12,2) NULL DEFAULT NULL,
  `updated_by` INT NULL DEFAULT NULL,
  `timestamp` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
