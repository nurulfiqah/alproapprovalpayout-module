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
--
-- BEFORE DEPLOYING TO PRODUCTION (2026-09-08 batch) - this dump alone does
-- NOT cover access-behavior changes, because they read the OUTER app's
-- shared `staff` table directly rather than any table this module owns:
--
-- 1. `staff.aap` is now read as a LEVEL, not a plain flag - 0 = no access,
--    1 = "Admin 1" (general admin, aapIsAdmin()), 2 = "Admin 2" (full
--    SuperAdmin, aapFetchIsSuperAdmin()'s union with staff.okr/atem). No
--    schema change needed on production - the column's type is unchanged
--    (existing 0/1 values still mean exactly what they did before: 0 = no
--    access, 1 = SuperAdmin); this only starts mattering the first time
--    someone is set to `aap = 2`, or promoted to `aap = 1` for the narrower
--    Admin 1 tier via admin/aap_settings.php's level picker. A short-lived
--    separate `staff.aap_approval_unit` column existed briefly for the same
--    purpose - dropped the same day in favour of this single leveled
--    column, so if your production `staff` table somehow already has that
--    column from an earlier deploy attempt, it's safe to drop:
--        ALTER TABLE staff DROP COLUMN aap_approval_unit;
--
-- 2. admin/aap_grouping_master.php (Approval Unit Master), admin/aap_settings.php,
--    and admin/aap_staff_assignments.php all require staff.aap = 2 (or
--    okr/atem = 1) specifically - a level-1 Admin 1 does not get into any
--    of the three, even though they pass the general aapIsAdmin() check
--    everywhere else (e.g. admin/aap_admin.php's Case Type Registry).
--
-- 3. admin/aap_admin.php's Case Type Level 1 picker and aap_add.php's
--    raise-a-case gate read `staff.grade`/`staff.department` directly - no
--    schema change needed, just be aware that on production, whoever
--    doesn't have grade >= 4, staff.aap = 2, staff.okr/atem = 1, or belong
--    to Customer Support (dept 27) / Operations (dept 13, raise-a-case
--    only) will now only see/manage their OWN department's Case Types, not
--    every department like before this batch. See
--    aapCanManageAllDepartments() in aap_lib.php. Approval Unit Master
--    itself is unaffected by this department scoping - see point 2 above,
--    it's SuperAdmin-only instead.
--
--    Gotcha this exact server has shown, relevant if you ever DO alter
--    `staff`: it carries a pre-existing `ap_status DATE NOT NULL DEFAULT
--    '0000-00-00'` column, an invalid default under a strict sql_mode with
--    NO_ZERO_DATE, which makes ANY `ALTER TABLE staff ...` fail regardless
--    of the column actually being touched. If an ALTER errors with "Invalid
--    default value for 'ap_status'", run `SET SESSION sql_mode = '';`
--    first (session-scoped, doesn't change ap_status itself, just permits
--    the ALTER through).
--
-- SUPERSEDED (2026-09-14): point 1/2 above no longer apply - the two-tier
-- "Admin 1"/"Admin 2 (SuperAdmin)" split was removed. `staff.aap` is back to
-- being a plain flag (0 = no access, 1 = full AAP admin access - every admin
-- page/feature, nothing held back). aapFetchIsSuperAdmin() now treats any
-- staff.aap >= 1 as part of the admin union, same weight as an okr/atem
-- SuperAdmin flag - see aap_lib.php. No schema change needed for this
-- either; existing aap=1 rows just mean more than they used to.
-- ============================================================

-- --------------------------------------------------------
-- aap_case_types
-- The Case Type Registry (Part D.2). Each row is a selectable case type
-- that drives the intake form, evidence requirement, and physical
-- confirmation gate for every case raised against it. Scoped to a
-- department (staff_department, the outer app's shared table). Soft-
-- deletable via `recycle` — never hard-deleted, so historical cases keep
-- referencing retired types.
--
-- Redesigned 2026-08-28 — dropped `name`/`code`, `default_requester_type`
-- (aap_add.php/aap_update.php now default requester_type to 'customer'),
-- and the whole approver_mode / ops_tier_required / turnaround_days /
-- systems_note / sort_order group - approval routing moved entirely to the
-- per-Case-Type Staff Tier model (aap_case_type_staff_tiers below);
-- turnaround/systems_note had no replacement and were simply dropped along
-- with the admin form fields that edited them.
--
-- Briefly split into this table + a separate aap_case_type_master lookup
-- table (case_type_master_id FK, name shared/deduplicated there) between
-- 2026-08-28 and 2026-09-03 - merged back into this single table on
-- 2026-09-03 (case_type_name is a plain column again, no more dedup-by-name
-- lookup) since the split added complexity nobody needed. See
-- aap_lib.php's aapCaseSelectSql()/aapFetchCaseType(s)() and
-- admin/aap_admin.php's save_case_type handler.
-- --------------------------------------------------------

CREATE TABLE `aap_case_types` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `case_type_name` VARCHAR(150) NOT NULL,
  `department_id` INT UNSIGNED NOT NULL,
  `physical_confirm_required` TINYINT(1) NOT NULL DEFAULT 0,
  `description` VARCHAR(255) NULL,
  `recycle` TINYINT(1) NOT NULL DEFAULT 0,
  `timestamp` DATETIME NOT NULL,
  KEY `idx_aap_case_types_department` (`department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- aap_bank_master
-- Lookup list of banks for the Bank Detail section's dropdown
-- (aap_add.php/aap_update.php) - aap_cases.bank_id references this table's
-- id instead of storing a free-text bank name. Soft-deletable via `recycle`
-- like the other lookup tables, so a retired bank stays intact on historical
-- cases that already reference it.
-- --------------------------------------------------------

CREATE TABLE `aap_bank_master` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `bank_name` VARCHAR(150) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `recycle` TINYINT(1) NOT NULL DEFAULT 0,
  `timestamp` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- aap_case_type_staff_tiers
-- Per-Case-Type approval gate (section='approval') and Exclusion
-- (section='exclusion') staff rosters - replaces the old department-pool
-- approver_mode system entirely (see aap_case_types' redesign note above).
-- Each row is one staff member's tier for one Case Type/section. `tier` is
-- the real tier NAME (e.g. 'Tier 1', 'Tier 2', 'Unlimited', or any custom
-- name a department added) from admin/aap_grouping_master.php's Approval
-- Unit Master - changed from a fixed ENUM('Unlimited','Tier 1','Tier 2')
-- with a hardcoded RM5,000 split (2026-09-01) to VARCHAR so it can hold
-- whatever tiers actually exist. department_id is the department the tier
-- name should be resolved against; it's the department the staff member was
-- picked from in the Staff Tier search UI (admin/aap_admin.php), not itself
-- a gate. Exclusion always overrides approval for the same staff
-- member+Case Type (see aapCanApprove()). Managed as a client-built table
-- serialized to JSON on submit and replaced wholesale server-side
-- (aapSaveCaseTypeStaffTiers() in admin/aap_admin.php) rather than diffed
-- row by row.
--
-- `group_id` added 2026-09-08 alongside the Approval Unit Master's Groups
-- feature - closes the KNOWN GAP flagged on aap_approval_unit_tiers below.
-- The Staff Tier picker (admin/aap_admin.php) now adds staff by picking a
-- Department + Group, so every row it creates is stamped with that Group's
-- id, letting aapTierValueByName()/aapCanApprove() resolve the tier NAME
-- against that exact Group's row instead of an ambiguous
-- (department_id, tier_name) match across every Group the department has.
-- NULL means either a legacy row from before this column existed, or one
-- added via the "Default (Universal)" fallback entry (a department with no
-- Groups set up) - both resolve against the Universal list
-- (department_id IS NULL), which is unambiguous on its own.
-- --------------------------------------------------------

CREATE TABLE `aap_case_type_staff_tiers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `case_type_id` INT UNSIGNED NOT NULL,
  `section` ENUM('approval','exclusion') NOT NULL,
  `department_id` INT UNSIGNED NOT NULL,
  `group_id` INT UNSIGNED NULL DEFAULT NULL,
  `staff_id` INT UNSIGNED NOT NULL,
  `tier` VARCHAR(100) NOT NULL,
  `created_by` INT NULL,
  `timestamp` DATETIME NOT NULL,
  UNIQUE KEY `uq_case_type_section_staff` (`case_type_id`, `section`, `staff_id`),
  KEY `idx_case_type_section` (`case_type_id`, `section`),
  KEY `idx_group` (`group_id`),
  CONSTRAINT `fk_case_type_staff_tiers_case_type` FOREIGN KEY (`case_type_id`) REFERENCES `aap_case_types` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- aap_cases
-- The core case record — one row per raised case, carrying it through
-- the full lifecycle (Draft -> Open -> Verification Required -> Approval ->
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
  -- Captured alongside customer_membership_id at raise time (aap_add.php's
  -- Customer/Membership ID typeahead, see aap_search_customer.php) - kept as
  -- its own column rather than folded back into one combined field, so the
  -- membership ID field itself only ever holds the clean ID.
  `customer_name` VARCHAR(255) NULL,
  -- Bank Detail section (aap_add.php/aap_update.php, between Case
  -- Summary/Details and Evidence Attachments) - collected for payout
  -- processing, editable same as the rest of the case's own fields.
  -- bank_name references aap_bank_master.id (dropdown, not free text).
  `bank_id` INT UNSIGNED NULL,
  `bank_account_number` VARCHAR(50) NULL,
  `bank_account_holder` VARCHAR(255) NULL,
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
  -- approver_mode/ops_tier_required (copied from aap_case_types at
  -- creation/edit time) dropped 2026-08-28 along with the same columns on
  -- aap_case_types - approval is now resolved live via case_type_id against
  -- aap_case_type_staff_tiers (aapCanApprove() in aap_lib.php), so nothing
  -- needs copying onto the case row anymore.
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
  -- Suspend (aap_update.php's 'suspend_case' action, Execution-only) sends a
  -- case back to Verification/Approval - never to 'draft', case_status stays
  -- 'open' throughout. `suspended_at` is the most recent suspend's
  -- timestamp, used as a hard floor by aapCaseCurrentPhaseStartedAt()
  -- (aap_lib.php) so evidence/notes/attachments from before it stay frozen
  -- forever, even for their own author, even once that phase would normally
  -- be editable again. `suspend_count` just flags a case that was EVER
  -- suspended, so the final Closed status can read "Closed (Suspended)"
  -- (aapCaseDisplayStatus()) - it doesn't otherwise change closed-case
  -- behavior.
  `suspended_at` DATETIME NULL DEFAULT NULL,
  `suspended_by` INT NULL DEFAULT NULL,
  `suspend_reason` VARCHAR(255) NULL,
  `suspend_count` INT UNSIGNED NOT NULL DEFAULT 0,
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
-- aap_case_execution_attachments
-- Execution-only evidence files (aap_update.php's Execution card) - kept as
-- its own table rather than a visibility flag on aap_case_attachments, so a
-- query that forgets to filter by visibility can never accidentally leak
-- these to the requester/approver/anyone else. Only queried, rendered,
-- uploaded to, and downloaded from when the viewer can execute the case
-- (aapCanExecute() - Operations dept or admin), checked independently at
-- every touchpoint in aap_update.php.
-- --------------------------------------------------------

CREATE TABLE `aap_case_execution_attachments` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `case_id` INT UNSIGNED NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `stored_name` VARCHAR(255) NOT NULL,
  `uploaded_by` INT NOT NULL,
  `timestamp` DATETIME NOT NULL,
  KEY `idx_aap_exec_attach_case` (`case_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- aap_admin_audit_logs
-- General-purpose audit log for admin pages whose actions aren't scoped to
-- one case (aap_audit_logs.case_id is NOT NULL, so it can't be reused here) -
-- e.g. admin/aap_staff_assignments.php's Remove/Reassign/Reassign All/
-- Remove All actions, which act on a staff member's rows across many
-- departments/Case Types at once. `page` identifies which admin page wrote
-- the row (currently only 'staff_assignments'), so more pages can share this
-- table later. Its Audit Trail section on aap_staff_assignments.php is only
-- ever shown to a general Admin ("Admin 1", staff.aap = 1), not SuperAdmin.
-- --------------------------------------------------------

CREATE TABLE `aap_admin_audit_logs` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `page` VARCHAR(50) NOT NULL,
  `event` VARCHAR(50) NOT NULL,
  `actor_staff_id` INT NOT NULL,
  `target_staff_id` INT UNSIGNED NULL,
  `summary` VARCHAR(255) NOT NULL,
  `timestamp` DATETIME NOT NULL,
  KEY `idx_aap_admin_audit_page` (`page`),
  KEY `idx_aap_admin_audit_target` (`target_staff_id`)
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
-- aap_staff_thresholds — DEAD as of 2026-09, code removed
-- No longer read or written by any PHP/JS in this app - aapGetStaffThreshold()
-- was removed from aap_lib.php, its save endpoint (update_aap_ceiling) had
-- already lost its UI, and aapCanApprove() never consulted it (approval
-- authority is decided entirely by the per-Case-Type Staff Tier model,
-- aap_case_type_staff_tiers). Left here only so the table's existence in
-- the live DB is documented; if you want it fully gone, DROP TABLE this
-- and re-run this dump. Original purpose: a flat per-staff RM ceiling
-- (no row = no approval rights, threshold_amount NULL = unlimited),
-- intended to replace the old grade-based Operations Executive/Manager
-- tier and CS Level 1/2 system, before the named-tier model below
-- superseded it instead.
-- --------------------------------------------------------

CREATE TABLE `aap_staff_thresholds` (
  `staff_id` INT UNSIGNED NOT NULL PRIMARY KEY,
  `threshold_amount` DECIMAL(12,2) NULL DEFAULT NULL,
  `updated_by` INT NULL DEFAULT NULL,
  `timestamp` DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- aap_approval_unit_tier_groups
-- Added 2026-09-08. A named/described Group of tiers within one
-- department (e.g. separate teams) - admin/aap_grouping_master.php's
-- "+ Add Group" (add_group action) creates one and seeds its
-- aap_approval_unit_tiers rows (group_id FK, see below) with a fresh copy
-- of the current Universal 6-tier set. group_name/description are plain
-- editable text (update_group action), not admin-only lookups. Deleting a
-- Group (delete_group) removes its aap_approval_unit_tiers rows first,
-- which cascades any aap_approval_unit_tier_staff overrides on them (that
-- table's own FK is ON DELETE CASCADE on tier_id).
-- --------------------------------------------------------

CREATE TABLE `aap_approval_unit_tier_groups` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `department_id` INT UNSIGNED NOT NULL,
  `group_name` VARCHAR(150) NOT NULL,
  `description` VARCHAR(255) NULL DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `updated_by` INT NULL DEFAULT NULL,
  `timestamp` DATETIME NOT NULL,
  KEY `idx_department` (`department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- aap_approval_unit_tiers
-- Replaced aap_approval_units (the old Department x staff_grade grid,
-- dropped 2026-09-01) with a named tier list managed from
-- admin/aap_grouping_master.php. department_id NULL = the shared Universal
-- tier list every department follows until it customizes; a department that
-- has ANY rows of its own (department_id = its id) is "Custom" and no longer
-- reads the Universal list at all - see aapFetchApprovalUnitTiers() in
-- admin/aap_grouping_master.php. This table DOES feed aapCanApprove() -
-- indirectly, via aapTierNameCoversValue()/aapTierValueByName() in
-- aap_lib.php, which resolve a tier NAME stored on an
-- aap_case_type_staff_tiers row to its live RM value here at approval time.
-- Nothing is snapshotted onto the assignment row, so editing a tier's value
-- here changes every staff member's ceiling for that tier name immediately
-- - see the impact-warning confirm in admin/aap_grouping_master.php before
-- editing a tier that's in active use.
--
-- Redesigned 2026-09-03 - both the Universal list (department_id IS NULL)
-- and every Group's own copy are now always exactly 6 rows, one fixed tier
-- per staff_grade (see aap_approval_unit_tier_grades below). Nobody can
-- add/remove a row or reassign a grade through the UI any more -
-- admin/aap_grouping_master.php's Edit only ever changes a row's RM value
-- (via update_tier). `reason` and the add_tier/remove_tier actions that
-- used it are gone - every row that will ever exist here is seeded by the
-- migration or by add_group, never typed in by an admin.
--
-- Split further on 2026-09-08 - a department's own rows now belong to a
-- Group (`group_id`, see aap_approval_unit_tier_groups below) instead of
-- being one flat per-department set; a department can have any number of
-- Groups, each an independent full copy of the fixed 6 rows (e.g. separate
-- teams within the same department). department_id is kept alongside
-- group_id on every department-scoped row (redundant with
-- aap_approval_unit_tier_groups.department_id, but avoids an extra join in
-- every hot-path query - see aapFetchDepartmentTierStaff() in
-- admin/aap_grouping_master.php). Still always NULL together on a Universal
-- row.
--
-- FORMER KNOWN GAP (fixed 2026-09-08): aapTierValueByName()/aapCanApprove()
-- in aap_lib.php used to resolve a tier by (department_id, tier_name) only,
-- with no concept of Group - ambiguous/arbitrary once a department had more
-- than one Group, since every Group gets the same fixed tier names. Fixed
-- by adding aap_case_type_staff_tiers.group_id (see that table above) and
-- threading an optional $group_id through aapTierValueByName()/
-- aapTierNameCoversValue()/aapCanApprove()/aapFetchEligibleApproverIds() -
-- when present, resolution is an exact (group_id, tier_name) match instead
-- of the old ambiguous department-wide one. Only rows assigned before this
-- fix (group_id NULL) still fall back to the old department/Universal
-- lookup, and are only ambiguous if that department has more than one Group
-- - re-adding those staff via the Staff Tier picker stamps them with a
-- Group and resolves it.
-- --------------------------------------------------------

CREATE TABLE `aap_approval_unit_tiers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `department_id` INT UNSIGNED NULL DEFAULT NULL,
  `group_id` INT UNSIGNED NULL DEFAULT NULL,
  `tier_name` VARCHAR(100) NOT NULL,
  `tier_value` DECIMAL(12,2) NULL DEFAULT NULL,
  -- Dead column as of the 2026-09-03 redesign - add_tier (the only thing
  -- that ever set it) was removed along with free-form tier creation. Kept
  -- rather than dropped in case a free-form reason is wanted again later.
  `reason` VARCHAR(255) NULL DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `updated_by` INT NULL DEFAULT NULL,
  `timestamp` DATETIME NOT NULL,
  KEY `idx_department` (`department_id`),
  KEY `idx_group` (`group_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed: the shared Universal tier list (department_id NULL) - one fixed tier
-- per staff_grade, CEO/Board always Unlimited (see grade pairing in the
-- aap_approval_unit_tier_grades seed below).
INSERT INTO `aap_approval_unit_tiers` (`department_id`, `tier_name`, `tier_value`, `sort_order`, `updated_by`, `timestamp`) VALUES
(NULL, 'Unlimited', NULL,      1, NULL, NOW()),
(NULL, 'Tier 4',    20000.00,  2, NULL, NOW()),
(NULL, 'Tier 3',    10000.00,  3, NULL, NOW()),
(NULL, 'Tier 2',    5000.00,   4, NULL, NOW()),
(NULL, 'Tier 1',    2000.00,   5, NULL, NOW()),
(NULL, 'Tier 0',    0.00,      6, NULL, NOW());

-- --------------------------------------------------------
-- aap_approval_unit_tier_staff
-- Back in use as of 2026-09-03 - manual per-staff tier overrides. A staff
-- member's tier normally comes from their grade (via
-- aap_approval_unit_tier_grades on the Universal list); admin can instead
-- assign them directly to one of a Group's own tier rows here
-- (assign_tier_staff in admin/aap_grouping_master.php), which takes over
-- from the grade default until unassign_tier_staff removes the row. Only
-- ever points at a Group-scoped tier row, never a Universal one - see
-- aapFetchDepartmentTierStaff(). Scoped 2026-09-08 to one row per staff
-- member per GROUP, not per department (assign_tier_staff only deletes a
-- prior override for that staff within the SAME Group before inserting the
-- new one) - since a department can have multiple Groups, the same staff
-- member can independently hold a different tier in each one. ON DELETE
-- CASCADE on tier_id, so deleting a Group (which deletes its tier rows)
-- cleans up its overrides automatically.
-- --------------------------------------------------------

CREATE TABLE `aap_approval_unit_tier_staff` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `tier_id` INT UNSIGNED NOT NULL,
  `staff_id` INT UNSIGNED NOT NULL,
  `created_by` INT NULL DEFAULT NULL,
  `timestamp` DATETIME NOT NULL,
  UNIQUE KEY `uq_tier_staff` (`tier_id`, `staff_id`),
  CONSTRAINT `fk_tier_staff_tier` FOREIGN KEY (`tier_id`) REFERENCES `aap_approval_unit_tiers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- aap_approval_unit_tier_grades
-- Which staff_grade sits in which Universal tier (department_id IS NULL
-- tiers only) - department-scoped tiers never use this table, only the
-- Universal list. Redesigned 2026-09-03 to a strict 1 grade : 1 tier
-- pairing (was many-grades-per-tier before) - every Universal tier row has
-- exactly one grade here, fixed at setup time; admin/aap_grouping_master.php
-- no longer offers a UI to reassign or unassign it, only to edit that row's
-- tier_value. staff_grade ids: 0 Non-Graded, 1 Frontline/Operational Staff,
-- 2 Middle Management, 3 Senior Management, 4 CSuite Executive, 5 CEO/Board.
-- --------------------------------------------------------

CREATE TABLE `aap_approval_unit_tier_grades` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `tier_id` INT UNSIGNED NOT NULL,
  `grade_id` TINYINT UNSIGNED NOT NULL,
  `created_by` INT NULL DEFAULT NULL,
  `timestamp` DATETIME NOT NULL,
  UNIQUE KEY `uq_tier_grade` (`tier_id`, `grade_id`),
  CONSTRAINT `fk_tier_grades_tier` FOREIGN KEY (`tier_id`) REFERENCES `aap_approval_unit_tiers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Seed: pair each Universal tier (by name, since its id is auto-assigned)
-- with its one fixed staff_grade.
INSERT INTO `aap_approval_unit_tier_grades` (`tier_id`, `grade_id`, `created_by`, `timestamp`)
SELECT t.id, g.grade_id, NULL, NOW()
FROM `aap_approval_unit_tiers` t
JOIN (
    SELECT 'Unlimited' AS tier_name, 5 AS grade_id UNION ALL
    SELECT 'Tier 4', 4 UNION ALL
    SELECT 'Tier 3', 3 UNION ALL
    SELECT 'Tier 2', 2 UNION ALL
    SELECT 'Tier 1', 1 UNION ALL
    SELECT 'Tier 0', 0
) g ON g.tier_name = t.tier_name
WHERE t.department_id IS NULL;

-- --------------------------------------------------------
-- aap_department_managers
-- A per-department allowlist of staff who can manage that department's
-- Case Types (admin/aap_admin.php), Approval Unit Groups (admin/
-- aap_grouping_master.php - their own department's Groups only, never the
-- shared Universal list), and Staff Assignments lookups (admin/
-- aap_staff_assignments.php) - granted on top of the normal
-- staff.department/grade-based scoping (aapDeptInScope() in aap_lib.php),
-- never in place of it. Deliberately ungated by grade or staff.department -
-- being on this list is enough by itself, same as how fixit_department's
-- Person Incharge names a department's contact without checking their
-- grade. Managed from admin/aap_department_managers.php (admin-only).
-- --------------------------------------------------------

CREATE TABLE `aap_department_managers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `department_id` INT UNSIGNED NOT NULL,
  `staff_id` INT UNSIGNED NOT NULL,
  `created_by` INT NULL DEFAULT NULL,
  `timestamp` DATETIME NOT NULL,
  UNIQUE KEY `uq_dept_manager` (`department_id`, `staff_id`),
  KEY `idx_dept_manager_dept` (`department_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;