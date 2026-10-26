-- =====================================================================
-- M3: add medicines.is_active  (lets the clinic RETIRE a medicine)
-- =====================================================================
-- STATUS: NOT RUN YET. Review it first.
--
-- WHEN TO RUN: once, after you approve it, and AFTER you have exported
--              a fresh backup of clinic_system_final_db in phpMyAdmin.
--              Run it before opening the Stage 2 pages.
--
-- HOW TO RUN: phpMyAdmin -> click clinic_system_final_db -> SQL tab ->
--             paste ONLY the ALTER TABLE statement below -> Go.
--
-- WHAT IT CHANGES: adds ONE column to ONE table. No rows are deleted
-- or edited, and no other table is touched. Every existing medicine
-- automatically gets is_active = 1 (active) from the DEFAULT.
-- =====================================================================

ALTER TABLE `medicines`
  ADD COLUMN `is_active` TINYINT(1) NOT NULL DEFAULT 1
  AFTER `reorder_level`;

-- ---------------------------------------------------------------------
-- CHECK IT WORKED (run these separately, they only read data):
--
--   SHOW COLUMNS FROM medicines LIKE 'is_active';
--       -> should return exactly 1 row.
--
--   SELECT medicine_id, medicine_name, is_active FROM medicines;
--       -> every row should show is_active = 1.
--
-- ---------------------------------------------------------------------
-- HOW TO UNDO (only if you ever need to; it removes just this column):
--
--   ALTER TABLE `medicines` DROP COLUMN `is_active`;
-- ---------------------------------------------------------------------
