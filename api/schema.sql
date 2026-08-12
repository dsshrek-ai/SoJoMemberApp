-- Choir App — schema for MyDataWorld
-- Run this in phpMyAdmin's SQL tab against the MyDataWorld database, AFTER
-- My Apps Hub's own api/schema.sql (this relies on the shared users/sessions/
-- apps/app_access tables, including the can_edit column, already existing).
--
-- Replaces the Google Sheets tabs entirely. Table names are prefixed
-- choir_ so they can't collide with another app's tables in this database.

CREATE TABLE IF NOT EXISTS users (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  username       VARCHAR(100) NOT NULL UNIQUE,
  password_hash  VARCHAR(255) NOT NULL,
  display_name   VARCHAR(100) NULL,
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sessions (
  token       CHAR(64) PRIMARY KEY,
  user_id     INT NOT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  expires_at  TIMESTAMP NOT NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS choir_schedule (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  entry_date      DATE NULL,
  time_text       VARCHAR(20) NULL,
  type            VARCHAR(50) NULL,
  title           VARCHAR(255) NULL,
  location        VARCHAR(255) NULL,
  parking_notes   TEXT NULL,
  entrance_notes  TEXT NULL,
  notes           TEXT NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS choir_songs (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  title                 VARCHAR(255) NULL,
  rehearsal_track_url   VARCHAR(500) NULL,
  youtube_url           VARCHAR(500) NULL,
  last_rehearsed_date   DATE NULL,
  status                VARCHAR(50) NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS choir_lyrics (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  song_title   VARCHAR(255) NULL,
  part         VARCHAR(100) NULL,
  lyrics_text  TEXT NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS choir_announcements (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  entry_date  DATE NULL,
  author      VARCHAR(100) NULL,
  message     TEXT NULL,
  pinned      VARCHAR(5) NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS choir_volunteer_tasks (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  entry_date    DATE NULL,
  task_name     VARCHAR(255) NULL,
  slots_needed  INT NOT NULL DEFAULT 0
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS choir_volunteer_signups (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  entry_date       DATE NULL,
  task_name        VARCHAR(255) NULL,
  volunteer_name   VARCHAR(255) NULL,
  phone_number     VARCHAR(30) NULL,
  signed_up_at     DATETIME NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Position matches the fixed list used by the SOJO roster app (kept in sync
-- by convention, not a foreign key -- the two apps' data stores are separate).
CREATE TABLE IF NOT EXISTS choir_absences (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  entry_date   DATE NULL,
  member_name  VARCHAR(255) NULL,
  position     VARCHAR(20) NULL,
  note         TEXT NULL,
  reported_at  DATETIME NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS choir_recognition (
  id           INT AUTO_INCREMENT PRIMARY KEY,
  entry_date   DATE NULL,
  member_name  VARCHAR(255) NULL,
  message      TEXT NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS choir_sponsors (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  sponsor_name  VARCHAR(255) NULL,
  logo_url      VARCHAR(500) NULL,
  message       TEXT NULL,
  tier          VARCHAR(50) NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Key/Value settings (WelcomeMessage, DonationURL, NewMemberFormURL,
-- AuditionInfoText, AuditionFormURL, InstructionsText, DirectorEmail).
-- No more AdminPassword row -- admin access is MyDataWorld app_access now.
CREATE TABLE IF NOT EXISTS choir_settings (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  setting_key    VARCHAR(100) NOT NULL UNIQUE,
  setting_value  TEXT NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who gets emailed when someone in a given Position reports an absence.
-- Position values match the SOJO roster app's POSITIONS list.
CREATE TABLE IF NOT EXISTS choir_section_leaders (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  position      VARCHAR(20) NOT NULL UNIQUE,
  leader_name   VARCHAR(100) NULL,
  leader_email  VARCHAR(255) NULL
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Grant yourself (and any section leaders/co-admins) access to the admin panel
-- once you've signed up through My Apps Hub -- otherwise nobody can get in.
-- INSERT INTO app_access (user_id, app_id)
-- SELECT u.id, a.id FROM users u, apps a
-- WHERE u.username = 'you@example.com' AND a.app_key = 'choir-admin-panel';
