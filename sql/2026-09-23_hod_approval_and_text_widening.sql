-- ============================================================
-- 2026-09-23 - Fixit/AAP link column + HOD Approval Only + widen
-- free-text remark columns. Run against production to catch up to
-- sql/aap_master.sql. Each ALTER is safe to run on its own - if one
-- errors "Duplicate column" it just means that particular change is
-- already applied there; skip it and run the rest.
-- ============================================================

-- fixit_category.aap_link - lives on the OUTER odb app's own table, not an
-- aap_ prefixed one, so it's easy to miss when migrating just "the AAP
-- module". Flags which Fixit categories feed AAP's "Incoming from Fixit"
-- queue (aapIncomingFixitWhereSql() in aap_lib.php) - without this column,
-- every page that reads the incoming-Fixit queue (index.php) crashes with
-- "Unknown column 'fc.aap_link'".
-- NOTE: same column as fixit/sql/2026-08-19_add_aap_link_to_fixit_category.sql
-- - run only ONE of the two per environment; if either already ran, this
-- errors "Duplicate column 'aap_link'" and can be skipped.
ALTER TABLE fixit_category
  ADD COLUMN aap_link INT NOT NULL DEFAULT 0 AFTER access;

-- HOD Approval Only (Level 2/Level 3): a Case Type can be flagged so that
-- level's approver is the HOD (iidas_department, read live) of whichever
-- department actually raised that specific case
-- (aap_cases.requester_department_id) - resolved per case, not stored here.
-- See aapCanApprove()/aapFetchEligibleApproverIds() in aap_lib.php.
-- NOTE: already confirmed applied on production (2026-09-23 run errored
-- "Duplicate column 'level2_hod_only'") - re-running this one is not
-- needed, kept here only for reference/other environments.
ALTER TABLE aap_case_types
  ADD COLUMN level2_hod_only TINYINT(1) NOT NULL DEFAULT 0 AFTER description,
  ADD COLUMN level3_hod_only TINYINT(1) NOT NULL DEFAULT 0 AFTER level2_hod_only;

-- These three were VARCHAR(100)/VARCHAR(255) - a long free-text reason typed
-- by an approver/executor has no natural length cap, and used to crash the
-- whole request outright under strict SQL mode once the text ran over the
-- limit (hit in practice on execution_reference and approval_remark during
-- testing - fixed here proactively for suspend_reason too, same bug class).
-- MODIFY is always safe to re-run - it never errors just because the column
-- is already the target type.
ALTER TABLE aap_cases MODIFY execution_reference TEXT NULL;
ALTER TABLE aap_cases MODIFY approval_remark TEXT NULL;
ALTER TABLE aap_cases MODIFY suspend_reason TEXT NULL;
