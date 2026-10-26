-- =====================================================================
-- M5: create the `users` table (login accounts and roles)
-- =====================================================================
-- WHEN TO RUN: once, BEFORE you open the Stage 5 pages, and AFTER you
--              have exported a fresh backup of clinic_system_final_db.
--
-- HOW TO RUN (phpMyAdmin):
--   1. Click  clinic_system_final_db  in the left panel.
--   2. Open the  SQL  tab.
--   3. Paste the CREATE TABLE statement below  ->  Go.
--
-- HOW TO RUN (MySQL command line, Windows):
--   C:\xampp\mysql\bin\mysql.exe -u root clinic_system_final_db < C:\xampp\htdocs\clinic-system-stage5\migrations\M5_create_users_table.sql
--
-- WHAT IT CHANGES: creates ONE new table. It does NOT touch students,
-- medicines, medicine_batches, symptoms, visits, visit_symptoms or
-- medication_dispensed. No rows are deleted, edited or reset, and no
-- stock quantity changes. It creates NO accounts: the first Super Admin
-- is created by you on setup.php (see README_STAGE5.txt).
--
-- SAFE TO RE-RUN: "IF NOT EXISTS" means running it a second time does
-- nothing (it will not wipe your accounts).
-- =====================================================================

CREATE TABLE IF NOT EXISTS `users` (
  `user_id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`             VARCHAR(50)  NOT NULL,
  `full_name`            VARCHAR(100) NOT NULL,
  -- password_hash() output (about 60 characters). NEVER the real password.
  `password_hash`        VARCHAR(255) NOT NULL,
  `role`                 ENUM('super_admin','clinic_personnel') NOT NULL,
  `is_active`            TINYINT(1)   NOT NULL DEFAULT 1,
  -- 1 = the person must choose a new password at the next login
  -- (set when an admin creates the account or resets the password).
  `must_change_password` TINYINT(1)   NOT NULL DEFAULT 0,
  -- Simple brute-force protection: 5 wrong passwords = locked 15 minutes.
  `failed_attempts`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `locked_until`         DATETIME NULL DEFAULT NULL,
  `last_login_at`        DATETIME NULL DEFAULT NULL,
  `created_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uq_users_username` (`username`),
  KEY `idx_users_role_active` (`role`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- CHECK IT WORKED (run separately; read-only):
--
--   SHOW TABLES LIKE 'users';          -> 1 row
--   SELECT COUNT(*) FROM users;        -> 0 (no accounts yet)
--   SELECT COUNT(*) FROM students;     -> same number as before
--   SELECT COUNT(*) FROM visits;       -> same number as before
-- ---------------------------------------------------------------------
-- HOW TO UNDO (only if you ever need to; removes just this new table):
--
--   DROP TABLE `users`;
-- ---------------------------------------------------------------------
