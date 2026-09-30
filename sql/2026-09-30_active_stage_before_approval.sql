-- ============================================================
-- 2026-09-30 - New "Active" stage before the Approval gate
-- Run against production to catch up to sql/aap_master.sql.
-- ============================================================

ALTER TABLE aap_cases
  ADD COLUMN submitted_for_approval TINYINT(1) NOT NULL DEFAULT 0 AFTER physical_confirm_required;

-- Backfill: every case that already existed before this feature shipped
-- effectively already "submitted" under the old automatic-submit behavior -
-- without this, every non-draft case in production would suddenly show as
-- stuck in Active needing a fresh manual Submit for Approval click.
UPDATE aap_cases SET submitted_for_approval = 1 WHERE case_status != 'draft';
