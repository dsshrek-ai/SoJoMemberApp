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

-- ---------- SCHEMA CHANGE: email-based member identification ----------
-- Run once. volunteer.html and absent.html now look the member up by email
-- against the SOJO roster app's Google Sheet (via its Apps Script Web App --
-- see APPS_SCRIPT_URL in config.php) to auto-fill name/phone/position, and
-- record the email alongside whatever was actually submitted (the member can
-- still edit the auto-filled fields, so what's stored here isn't guaranteed
-- to equal the roster's own values). Absences didn't collect a phone number
-- at all before this -- added here as its own column, same as signups.

ALTER TABLE choir_volunteer_signups ADD COLUMN email VARCHAR(255) NULL AFTER phone_number;
ALTER TABLE choir_absences          ADD COLUMN email VARCHAR(255) NULL AFTER position;
ALTER TABLE choir_absences          ADD COLUMN phone_number VARCHAR(30) NULL AFTER email;

-- Section leader's phone, for the new tap-to-call/text button on the My Info
-- page (mirrors sojo-app's Call/Text/Email buttons on a singer's own contact
-- info). Nullable -- populate via admin.html same as leader_name/leader_email.
ALTER TABLE choir_section_leaders ADD COLUMN leader_phone VARCHAR(20) NULL AFTER leader_email;

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

-- ---------- SCHEMA CHANGE: multi-choir support ----------
-- Run once -- like the "email-based member identification" change above,
-- this section has ALTER TABLE ... ADD COLUMN / DROP INDEX statements that
-- fail if run a second time (unlike the CREATE TABLE IF NOT EXISTS
-- statements elsewhere in this file, which are always safe to re-run).
--
-- One deployment of this app can now serve more than one choir. Every
-- choir_* table gets a choir_id column; existing rows are backfilled to
-- choir_id = 1 (SOJO) so today's single-choir data keeps working unchanged.
-- app_access still gates "can this user open the choir-admin-panel tool at
-- all" (unchanged); choir_access is new and gates "which choir(s) can they
-- see/edit once inside" -- a user needs a row in both to administer a choir.
-- Member-facing pages have no login, so choir selection there is just a
-- public ?choir=<choir_key> / remembered localStorage value, not a security
-- boundary -- same trust level the roster-email lookup already relies on.

CREATE TABLE IF NOT EXISTS choirs (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  choir_key   VARCHAR(50) NOT NULL UNIQUE,
  name        VARCHAR(255) NOT NULL,
  is_active   TINYINT(1) NOT NULL DEFAULT 1,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO choirs (id, choir_key, name) VALUES
  (1, 'sojo', 'SOJO Choral Arts Seasons Chorale Choir')
ON DUPLICATE KEY UPDATE choir_key = choir_key;

CREATE TABLE IF NOT EXISTS choir_access (
  id         INT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NOT NULL,
  choir_id   INT NOT NULL,
  can_edit   TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uniq_user_choir (user_id, choir_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (choir_id) REFERENCES choirs(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Grant every existing choir-admin-panel user access to SOJO (choir_id 1),
-- so nobody currently able to log in loses access when this ships.
INSERT INTO choir_access (user_id, choir_id, can_edit)
SELECT aa.user_id, 1, 1
FROM app_access aa JOIN apps a ON a.id = aa.app_id
WHERE a.app_key = 'choir-admin-panel'
ON DUPLICATE KEY UPDATE can_edit = can_edit;

ALTER TABLE choir_schedule          ADD COLUMN choir_id INT NOT NULL DEFAULT 1 AFTER id;
ALTER TABLE choir_songs             ADD COLUMN choir_id INT NOT NULL DEFAULT 1 AFTER id;
ALTER TABLE choir_lyrics            ADD COLUMN choir_id INT NOT NULL DEFAULT 1 AFTER id;
ALTER TABLE choir_announcements     ADD COLUMN choir_id INT NOT NULL DEFAULT 1 AFTER id;
ALTER TABLE choir_volunteer_tasks   ADD COLUMN choir_id INT NOT NULL DEFAULT 1 AFTER id;
ALTER TABLE choir_volunteer_signups ADD COLUMN choir_id INT NOT NULL DEFAULT 1 AFTER id;
ALTER TABLE choir_absences          ADD COLUMN choir_id INT NOT NULL DEFAULT 1 AFTER id;
ALTER TABLE choir_recognition       ADD COLUMN choir_id INT NOT NULL DEFAULT 1 AFTER id;
ALTER TABLE choir_sponsors          ADD COLUMN choir_id INT NOT NULL DEFAULT 1 AFTER id;

-- Settings/SectionLeaders were unique per key/position globally; now unique
-- per choir instead, so two choirs can each have their own DirectorEmail,
-- WelcomeMessage, section leaders, etc.
ALTER TABLE choir_settings ADD COLUMN choir_id INT NOT NULL DEFAULT 1 AFTER id;
ALTER TABLE choir_settings DROP INDEX setting_key;
ALTER TABLE choir_settings ADD UNIQUE KEY uniq_choir_setting (choir_id, setting_key);

ALTER TABLE choir_section_leaders ADD COLUMN choir_id INT NOT NULL DEFAULT 1 AFTER id;
ALTER TABLE choir_section_leaders DROP INDEX position;
ALTER TABLE choir_section_leaders ADD UNIQUE KEY uniq_choir_position (choir_id, position);

-- ---------- SCHEMA CHANGE: configurable nav + Documents page ----------
-- Run once -- the INSERT INTO choir_nav_items seed rows below have no
-- unique constraint stopping a second run from duplicating them.
--
-- Nav links shown across every member-facing page are now admin-editable
-- (label/target page/order/visibility) instead of hardcoded HTML repeated
-- on all 12 pages. Documents is a new page listing links to PDFs/downloads,
-- following the same "just a URL, not a hosted file" pattern as Songs'
-- RehearsalTrackURL/YouTubeURL and Sponsors' LogoURL.

CREATE TABLE IF NOT EXISTS choir_nav_items (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  choir_id    INT NOT NULL,
  label       VARCHAR(100) NOT NULL,
  page_file   VARCHAR(100) NOT NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  visible     TINYINT(1) NOT NULL DEFAULT 1,
  FOREIGN KEY (choir_id) REFERENCES choirs(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO choir_nav_items (choir_id, label, page_file, sort_order) VALUES
  (1, 'Home',              'index.html',         10),
  (1, 'Countdown',         'countdown.html',      20),
  (1, 'Announcements',     'announcements.html',  30),
  (1, 'Schedule',          'schedule.html',       40),
  (1, 'Volunteer',         'volunteer.html',      50),
  (1, 'Songs',              'songs.html',          60),
  (1, 'Documents',          'documents.html',      65),
  (1, 'Report Absence',    'absent.html',         70),
  (1, 'My Info',           'myinfo.html',         80),
  (1, 'Recognition',       'recognition.html',    90),
  (1, 'Sponsors & Giving', 'sponsors.html',       100),
  (1, 'Join Us',           'join.html',           110),
  (1, 'Instructions',      'instructions.html',   120);

CREATE TABLE IF NOT EXISTS choir_documents (
  id          INT AUTO_INCREMENT PRIMARY KEY,
  choir_id    INT NOT NULL,
  title       VARCHAR(255) NOT NULL,
  url         VARCHAR(500) NOT NULL,
  category    VARCHAR(100) NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  FOREIGN KEY (choir_id) REFERENCES choirs(id) ON DELETE CASCADE
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- SCHEMA CHANGE: Documents page ----------
-- Run once. The multi-choir attempt above was rolled back (see ROADMAP.md),
-- so nothing in the app sets choir_id explicitly anymore -- every other
-- choir_* table's choir_id has DEFAULT 1 for exactly this reason, but
-- choir_documents (created after that attempt started) never got one,
-- which would make a plain INSERT (omitting choir_id, same as every other
-- admin-editable table) fail outright. This just brings it in line.
ALTER TABLE choir_documents MODIFY COLUMN choir_id INT NOT NULL DEFAULT 1;
