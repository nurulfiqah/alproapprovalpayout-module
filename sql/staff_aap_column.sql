-- ============================================================
-- Outer-app dependency — NOT part of this module's own schema.
--
-- `staff` belongs to the shared odb app, not this repo (see CLAUDE.md /
-- Important Constraints). This column is required for AAP's SuperAdmin
-- union to work: aapIsAdmin() checks staff.aap = 1 alongside staff.grade
-- and staff.department, unioned with staff.okr / staff.atem via
-- aapFetchIsSuperAdmin() (aap_lib.php) — same pattern as the existing
-- staff.okr / staff.atem SuperAdmin flags used by the OKR and ATEM
-- modules.
--
-- Run this once against the shared `staff` table when deploying AAP to
-- an environment that doesn't have it yet. Check the column doesn't
-- already exist first (SHOW COLUMNS FROM staff LIKE 'aap') - MySQL's
-- ADD COLUMN has no IF NOT EXISTS (that's MariaDB-only), so re-running
-- this as-is on a column that already exists will error.
--
-- If this errors with "Invalid default value for '...'" on an unrelated
-- column: `staff` has a legacy DATE column defaulting to 0000-00-00,
-- which STRICT_TRANS_TABLES/NO_ZERO_DATE sql_mode rejects on ANY ALTER
-- to this table, not just this one. Work around it for this session only:
--   SET SESSION sql_mode = 'NO_ENGINE_SUBSTITUTION';
-- (run right before the ALTER below, in the same connection/session)
-- ============================================================

ALTER TABLE `staff`
  ADD COLUMN `aap` TINYINT(1) NOT NULL DEFAULT 0 AFTER `okr`;
