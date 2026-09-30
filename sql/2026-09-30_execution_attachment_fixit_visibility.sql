-- ============================================================
-- 2026-09-30 - Per-file "show in Fixit" flag on Execution attachments
-- Run against production to catch up to sql/aap_master.sql.
-- ============================================================

ALTER TABLE aap_case_execution_attachments
  ADD COLUMN show_in_fixit TINYINT(1) NOT NULL DEFAULT 0;
