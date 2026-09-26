<?php
require_once __DIR__ . '/config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Never let a raw PHP error/notice leak through as HTML — every response
// this API sends must be JSON.
ini_set('display_errors', '0');
set_exception_handler(function ($e) {
  http_response_code(500);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['error' => 'Server error: ' . $e->getMessage()]);
  exit;
});

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  http_response_code(204);
  exit;
}

function respond($data, int $status = 200): void {
  http_response_code($status);
  echo json_encode($data);
  exit;
}

function fail(string $message, int $status = 400): void {
  respond(['ok' => false, 'error' => $message], $status);
}

function db(): mysqli {
  static $conn = null;
  if ($conn === null) {
    try {
      $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
      $conn->set_charset('utf8mb4');
    } catch (mysqli_sql_exception $e) {
      fail('Database connection failed', 500);
    }
  }
  return $conn;
}

function jsonBody(): array {
  $raw = file_get_contents('php://input');
  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : [];
}

// ---- Auth — every action below requires a login now, except login/logout/
// ---- whoAmI, which is how a session gets established in the first place ----

function requireUser(): array {
  $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
  if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
    fail('Missing or invalid Authorization header', 401);
  }
  $token = $m[1];

  $stmt = db()->prepare(
    'SELECT u.id, u.username, u.display_name
     FROM sessions s JOIN users u ON u.id = s.user_id
     WHERE s.token = ? AND s.expires_at > NOW()'
  );
  $stmt->bind_param('s', $token);
  $stmt->execute();
  $result = $stmt->get_result()->fetch_assoc();
  $stmt->close();

  if (!$result) {
    fail('Session expired or invalid — please log in again', 401);
  }
  return $result;
}

function requireChoirAdminAccess(array $user): void {
  $stmt = db()->prepare(
    'SELECT 1 FROM app_access aa JOIN apps a ON a.id = aa.app_id
     WHERE aa.user_id = ? AND a.app_key = ?'
  );
  $appKey = 'choir-admin-panel';
  $stmt->bind_param('is', $user['id'], $appKey);
  $stmt->execute();
  $ok = $stmt->get_result()->fetch_row();
  $stmt->close();
  if (!$ok) {
    fail('Not authorized for the Choir Admin Panel', 403);
  }
}

function requireMemberAccess(array $user): void {
  $stmt = db()->prepare(
    'SELECT 1 FROM app_access aa JOIN apps a ON a.id = aa.app_id
     WHERE aa.user_id = ? AND a.app_key = ?'
  );
  $appKey = 'south-jordan-choral-arts';
  $stmt->bind_param('is', $user['id'], $appKey);
  $stmt->execute();
  $ok = $stmt->get_result()->fetch_row();
  $stmt->close();
  if (!$ok) {
    fail('Not authorized for the SoJo member app', 403);
  }
}

// Best-effort usage logging (see app_usage_log in My Apps Hub's schema.sql —
// shared across every MyDataWorld app, one row per user per app per day,
// upserted on each successful access). Wrapped in try/catch so a missing
// table (e.g. before that schema change has been run here) never breaks a
// member-facing request — this is purely for admin-side usage reporting.
function logAppUsage(int $userId): void {
  try {
    $appKey = 'south-jordan-choral-arts';
    $stmt = db()->prepare(
      'INSERT INTO app_usage_log (user_id, app_key, access_date, first_seen_at, last_seen_at, hit_count)
       VALUES (?, ?, CURDATE(), NOW(), NOW(), 1)
       ON DUPLICATE KEY UPDATE last_seen_at = NOW(), hit_count = hit_count + 1'
    );
    $stmt->bind_param('is', $userId, $appKey);
    $stmt->execute();
    $stmt->close();
  } catch (mysqli_sql_exception $e) {
    // Best-effort — see comment above.
  }
}

// Every member-facing action calls this first — a valid MyDataWorld login
// alone isn't enough, the account also needs an app_access grant for this
// app specifically (same model requireChoirAdminAccess already uses).
function requireMember(): array {
  $user = requireUser();
  requireMemberAccess($user);
  logAppUsage($user['id']);
  return $user;
}

// ---- Table registry: sheet name (as the front end already knows it) -> ----
// ---- MySQL table + PascalCase JSON key -> snake_case column mapping    ----

$DATA_TABLES = [
  'Schedule' => ['table' => 'choir_schedule', 'columns' => [
    'Date' => 'entry_date', 'Time' => 'time_text', 'Type' => 'type', 'Title' => 'title',
    'Location' => 'location', 'ParkingNotes' => 'parking_notes', 'EntranceNotes' => 'entrance_notes',
    'Notes' => 'notes',
  ]],
  'Songs' => ['table' => 'choir_songs', 'columns' => [
    'Title' => 'title', 'FolderSlug' => 'folder_slug', 'RehearsalTrackURL' => 'rehearsal_track_url',
    'YouTubeURL' => 'youtube_url', 'LastRehearsedDate' => 'last_rehearsed_date', 'Status' => 'status',
    'Sequence' => 'sequence',
  ]],
  'Announcements' => ['table' => 'choir_announcements', 'columns' => [
    'Date' => 'entry_date', 'Author' => 'author', 'Message' => 'message', 'Pinned' => 'pinned',
  ]],
  'VolunteerTasks' => ['table' => 'choir_volunteer_tasks', 'columns' => [
    'Date' => 'entry_date', 'Time' => 'time_text', 'TaskName' => 'task_name', 'SlotsNeeded' => 'slots_needed',
  ]],
  'VolunteerSignups' => ['table' => 'choir_volunteer_signups', 'columns' => [
    'Date' => 'entry_date', 'TaskName' => 'task_name', 'VolunteerName' => 'volunteer_name',
    'PhoneNumber' => 'phone_number', 'Email' => 'email', 'Timestamp' => 'signed_up_at',
  ]],
  'Absences' => ['table' => 'choir_absences', 'columns' => [
    'Date' => 'entry_date', 'MemberName' => 'member_name', 'Position' => 'position',
    'Email' => 'email', 'PhoneNumber' => 'phone_number', 'Note' => 'note', 'Timestamp' => 'reported_at',
  ]],
  'Recognition' => ['table' => 'choir_recognition', 'columns' => [
    'Date' => 'entry_date', 'MemberName' => 'member_name', 'Message' => 'message',
  ]],
  'Sponsors' => ['table' => 'choir_sponsors', 'columns' => [
    'SponsorName' => 'sponsor_name', 'LogoURL' => 'logo_url', 'Message' => 'message', 'Tier' => 'tier',
  ]],
  'Settings' => ['table' => 'choir_settings', 'columns' => [
    'Key' => 'setting_key', 'Value' => 'setting_value',
  ]],
  'SectionLeaders' => ['table' => 'choir_section_leaders', 'columns' => [
    'Position' => 'position', 'LeaderName' => 'leader_name', 'LeaderEmail' => 'leader_email',
    'LeaderPhone' => 'leader_phone',
  ]],
  // Nav links shown across every member-facing page — admin-editable order
  // and visibility, replacing the old hardcoded <nav> duplicated per page.
  'NavItems' => ['table' => 'choir_nav_items', 'columns' => [
    'Label' => 'label', 'PageFile' => 'page_file', 'SortOrder' => 'sort_order', 'Visible' => 'visible',
  ]],
  // Links to PDFs/downloads, mostly hosted elsewhere (Google Drive, church
  // site, etc.) — just a URL, same pattern as Songs' RehearsalTrackURL and
  // Sponsors' LogoURL. No file upload/hosting built for this.
  'Documents' => ['table' => 'choir_documents', 'columns' => [
    'Title' => 'title', 'Url' => 'url', 'Category' => 'category', 'SortOrder' => 'sort_order',
  ]],
  // Part tracks + director notes for a song's own index page (song.html).
  // FileName is just the filename -- the real URL is built at read time from
  // SONG_FILES_BASE_URL + the owning song's FolderSlug (see the `song` action).
  'SongFiles' => ['table' => 'choir_song_files', 'columns' => [
    'SongID' => 'song_id', 'FileType' => 'file_type', 'PartLabel' => 'part_label',
    'FileName' => 'filename', 'SortOrder' => 'sort_order',
  ]],
];

// Columns that must round-trip as JSON numbers, not strings (so `>=` comparisons
// in the front end work correctly instead of comparing lexically).
$NUMERIC_COLUMNS = ['SlotsNeeded', 'SortOrder', 'Visible', 'SongID', 'Sequence'];

// Columns backed by a real SQL DATE column (see schema.sql). MySQL rejects an
// empty string for a DATE column outright (it's not a valid date), so a
// blank date field has to be sent as NULL instead of '' or the whole
// insert/update fails — that's what made LastRehearsedDate feel "required"
// even though the column itself is nullable.
$DATE_COLUMNS = ['Date', 'LastRehearsedDate'];

// The public `settings` action must NOT return every row — Settings also holds
// DirectorEmail. Allowlist, not blocklist, matching the old Code.gs behavior.
$PUBLIC_SETTINGS_KEYS = [
  'WelcomeMessage', 'DonationURL', 'NewMemberFormURL', 'AuditionInfoText', 'AuditionFormURL',
  'InstructionsText', 'Countdown',
];

function tableRowsWithId(string $sheetName): array {
  global $DATA_TABLES;
  $spec = $DATA_TABLES[$sheetName];
  $result = db()->query("SELECT * FROM {$spec['table']} ORDER BY id");
  $rows = $result->fetch_all(MYSQLI_ASSOC);
  return array_map(function ($row) use ($spec) {
    $shaped = ['_row' => (int)$row['id']];
    foreach ($spec['columns'] as $jsonKey => $dbCol) {
      $shaped[$jsonKey] = $row[$dbCol];
    }
    return $shaped;
  }, $rows);
}

function tableRows(string $sheetName): array {
  return array_map(function ($row) {
    unset($row['_row']);
    return $row;
  }, tableRowsWithId($sheetName));
}

function adminInsertRow(string $sheetName, array $rowData): int {
  global $DATA_TABLES, $NUMERIC_COLUMNS, $DATE_COLUMNS;
  $spec = $DATA_TABLES[$sheetName];
  $cols = []; $placeholders = []; $types = ''; $values = [];
  foreach ($spec['columns'] as $jsonKey => $dbCol) {
    $cols[] = $dbCol;
    $placeholders[] = '?';
    if (in_array($jsonKey, $NUMERIC_COLUMNS, true)) {
      $types .= 'i';
      $values[] = (int)($rowData[$jsonKey] ?? 0);
    } elseif (in_array($jsonKey, $DATE_COLUMNS, true)) {
      $types .= 's';
      $values[] = ($rowData[$jsonKey] ?? '') === '' ? null : (string)$rowData[$jsonKey];
    } else {
      $types .= 's';
      $values[] = (string)($rowData[$jsonKey] ?? '');
    }
  }
  $sql = "INSERT INTO {$spec['table']} (" . implode(',', $cols) . ') VALUES (' . implode(',', $placeholders) . ')';
  $stmt = db()->prepare($sql);
  $stmt->bind_param($types, ...$values);
  $stmt->execute();
  $id = $stmt->insert_id;
  $stmt->close();
  return $id;
}

function adminUpdateRow(string $sheetName, int $id, array $rowData): void {
  global $DATA_TABLES, $NUMERIC_COLUMNS, $DATE_COLUMNS;
  $spec = $DATA_TABLES[$sheetName];
  $sets = []; $types = ''; $values = [];
  foreach ($spec['columns'] as $jsonKey => $dbCol) {
    $sets[] = "$dbCol = ?";
    if (in_array($jsonKey, $NUMERIC_COLUMNS, true)) {
      $types .= 'i';
      $values[] = (int)($rowData[$jsonKey] ?? 0);
    } elseif (in_array($jsonKey, $DATE_COLUMNS, true)) {
      $types .= 's';
      $values[] = ($rowData[$jsonKey] ?? '') === '' ? null : (string)$rowData[$jsonKey];
    } else {
      $types .= 's';
      $values[] = (string)($rowData[$jsonKey] ?? '');
    }
  }
  $types .= 'i';
  $values[] = $id;
  $sql = "UPDATE {$spec['table']} SET " . implode(', ', $sets) . ' WHERE id = ?';
  $stmt = db()->prepare($sql);
  $stmt->bind_param($types, ...$values);
  $stmt->execute();
  $stmt->close();
}

function adminDeleteRow(string $sheetName, int $id): void {
  global $DATA_TABLES;
  $spec = $DATA_TABLES[$sheetName];
  $stmt = db()->prepare("DELETE FROM {$spec['table']} WHERE id = ?");
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $stmt->close();
}

function getSetting(string $key): ?string {
  $stmt = db()->prepare('SELECT setting_value FROM choir_settings WHERE setting_key = ?');
  $stmt->bind_param('s', $key);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $row ? $row['setting_value'] : null;
}

// ---- Volunteer tasks (public status + admin phone rollup) ----

// Minutes past midnight for a "7:00 PM"-style time_text, or null if blank or
// unparseable. Text times don't sort correctly as strings ("10:00 AM" < "9:00 AM").
function timeTextToMinutes(?string $time): ?int {
  if (!preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM)$/i', trim((string)$time), $m)) {
    return null;
  }
  $h = (int)$m[1] % 12;
  if (strtoupper($m[3]) === 'PM') {
    $h += 12;
  }
  return $h * 60 + (int)$m[2];
}

// Sorts task rows (keyed Date/Time/TaskName) chronologically: by date, then
// start time, then name. Undated tasks go last; untimed tasks go after the
// timed ones on the same date.
function sortVolunteerTasks(array $tasks): array {
  usort($tasks, function ($a, $b) {
    $dateA = (string)($a['Date'] ?? '');
    $dateB = (string)($b['Date'] ?? '');
    if ($dateA !== $dateB) {
      if ($dateA === '') {
        return 1;
      }
      if ($dateB === '') {
        return -1;
      }
      return strcmp($dateA, $dateB);
    }
    $minA = timeTextToMinutes($a['Time'] ?? null) ?? PHP_INT_MAX;
    $minB = timeTextToMinutes($b['Time'] ?? null) ?? PHP_INT_MAX;
    if ($minA !== $minB) {
      return $minA <=> $minB;
    }
    return strcasecmp((string)($a['TaskName'] ?? ''), (string)($b['TaskName'] ?? ''));
  });
  return $tasks;
}

function getVolunteerStatus(): array {
  $tasks = sortVolunteerTasks(tableRows('VolunteerTasks'));
  $signups = tableRows('VolunteerSignups');
  return array_map(function ($task) use ($signups) {
    $filled = count(array_filter($signups, function ($s) use ($task) {
      return $s['Date'] === $task['Date'] && $s['TaskName'] === $task['TaskName'];
    }));
    return ['Date' => $task['Date'], 'Time' => $task['Time'], 'TaskName' => $task['TaskName'],
            'SlotsNeeded' => $task['SlotsNeeded'], 'SlotsFilled' => $filled];
  }, $tasks);
}

function getVolunteerTasksWithPhones(): array {
  $tasks = sortVolunteerTasks(tableRowsWithId('VolunteerTasks'));
  $signups = tableRows('VolunteerSignups');
  return array_map(function ($task) use ($signups) {
    $phones = array_values(array_filter(array_map(function ($s) use ($task) {
      return ($s['Date'] === $task['Date'] && $s['TaskName'] === $task['TaskName']) ? $s['PhoneNumber'] : null;
    }, $signups)));
    $task['PhoneNumbers'] = $phones;
    return $task;
  }, $tasks);
}

// ---- Admin reports ----

// Volunteer Signups report: every task dated within [from, to] (inclusive;
// an empty `to` means no end date), each with the people signed up for it.
// Sorted by date, then start time, then task name; volunteers within a task
// sorted by name. Signups match tasks by date + task name, same as
// getVolunteerStatus().
function getVolunteerSignupsReport(string $from, string $to): array {
  $conn = db();
  $where = 'entry_date >= ?' . ($to !== '' ? ' AND entry_date <= ?' : '');
  $params = $to !== '' ? [$from, $to] : [$from];
  $types = str_repeat('s', count($params));

  $stmt = $conn->prepare("SELECT entry_date, time_text, task_name, slots_needed FROM choir_volunteer_tasks
                          WHERE $where");
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $tasks = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();

  $stmt = $conn->prepare("SELECT entry_date, task_name, volunteer_name, phone_number FROM choir_volunteer_signups
                          WHERE $where ORDER BY volunteer_name");
  $stmt->bind_param($types, ...$params);
  $stmt->execute();
  $signups = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();

  return sortVolunteerTasks(array_map(function ($task) use ($signups) {
    $volunteers = [];
    foreach ($signups as $s) {
      if ($s['entry_date'] === $task['entry_date'] && $s['task_name'] === $task['task_name']) {
        $volunteers[] = ['Name' => $s['volunteer_name'], 'PhoneNumber' => $s['phone_number']];
      }
    }
    return ['Date' => $task['entry_date'], 'Time' => $task['time_text'], 'TaskName' => $task['task_name'],
            'SlotsNeeded' => (int)$task['slots_needed'], 'Volunteers' => $volunteers];
  }, $tasks));
}

// Named lock mirrors the old Apps Script LockService: only one claim can be
// evaluated+inserted at a time, so two people can't fill the last slot at once.
function claimSlot(array $body): array {
  $date = (string)($body['date'] ?? '');
  $taskName = (string)($body['taskName'] ?? '');
  $volunteerName = trim((string)($body['volunteerName'] ?? ''));
  $phoneNumber = trim((string)($body['phoneNumber'] ?? ''));
  $email = trim((string)($body['email'] ?? ''));

  $conn = db();
  $gotLock = $conn->query("SELECT GET_LOCK('choir_claim_slot', 10) AS got")->fetch_assoc();
  if (!$gotLock || (int)$gotLock['got'] !== 1) {
    return ['ok' => false, 'reason' => 'locked'];
  }
  try {
    $match = null;
    foreach (getVolunteerStatus() as $s) {
      if ($s['Date'] === $date && $s['TaskName'] === $taskName) { $match = $s; break; }
    }
    if (!$match) {
      return ['ok' => false, 'reason' => 'not-found'];
    }
    if ($match['SlotsFilled'] >= $match['SlotsNeeded']) {
      return ['ok' => false, 'reason' => 'full'];
    }
    $stmt = $conn->prepare(
      'INSERT INTO choir_volunteer_signups (entry_date, task_name, volunteer_name, phone_number, email, signed_up_at)
       VALUES (?, ?, ?, ?, ?, NOW())'
    );
    $stmt->bind_param('sssss', $date, $taskName, $volunteerName, $phoneNumber, $email);
    $stmt->execute();
    $stmt->close();
    return ['ok' => true];
  } finally {
    $conn->query("SELECT RELEASE_LOCK('choir_claim_slot')");
  }
}

// Records the absence, then best-effort emails the reporter's section leader
// (falling back to Settings.DirectorEmail if that position has no leader on
// file). Email failure never blocks the record itself.
function markAbsent(array $body): array {
  $date = (string)($body['date'] ?? '');
  $memberName = trim((string)($body['memberName'] ?? ''));
  $position = trim((string)($body['position'] ?? ''));
  $email = trim((string)($body['email'] ?? ''));
  $phoneNumber = trim((string)($body['phoneNumber'] ?? ''));
  $note = trim((string)($body['note'] ?? ''));

  $stmt = db()->prepare(
    'INSERT INTO choir_absences (entry_date, member_name, position, email, phone_number, note, reported_at)
     VALUES (?, ?, ?, ?, ?, ?, NOW())'
  );
  $stmt->bind_param('ssssss', $date, $memberName, $position, $email, $phoneNumber, $note);
  $stmt->execute();
  $stmt->close();

  sendAbsenceEmail($memberName, $date, $position, $email, $phoneNumber, $note);

  return ['ok' => true];
}

function sendAbsenceEmail(string $memberName, string $date, string $position, string $memberEmail, string $memberPhone, string $note): void {
  $leaderEmail = null;
  if ($position !== '') {
    $stmt = db()->prepare('SELECT leader_email FROM choir_section_leaders WHERE position = ?');
    $stmt->bind_param('s', $position);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row && trim((string)$row['leader_email']) !== '') {
      $leaderEmail = trim($row['leader_email']);
    }
  }
  if (!$leaderEmail) {
    $leaderEmail = getSetting('DirectorEmail');
  }
  if (!$leaderEmail) {
    return;
  }

  $subject = 'Absence reported: ' . $memberName;
  $body = $memberName . " reported they can't make it on " . $date .
    ($position ? ' (' . $position . ')' : '') .
    ($memberEmail ? "\nEmail: " . $memberEmail : '') .
    ($memberPhone ? "\nPhone: " . $memberPhone : '') .
    ($note ? "\n\nNote: " . $note : '');
  $headers = 'From: ' . FROM_EMAIL . "\r\n" . 'Reply-To: ' . FROM_EMAIL . "\r\n";
  // Without -f, cPanel/Exim ignores the From: header for the SMTP envelope
  // sender and substitutes the hosting account's own identity instead —
  // that's what was showing up as the "from" address in recipients' inboxes.
  @mail($leaderEmail, $subject, $body, $headers, '-f' . FROM_EMAIL);
}

// ---- SOJO roster lookups (reads the same Google Sheet sojo-app maintains,
// via its Apps Script Web App -- see APPS_SCRIPT_URL in config.php) ----
//
// Only read actions (getSingers/getConfig) are called; both are open on the
// Apps Script side (no PIN), unlike sojo-app's own writes. To keep the same
// privacy boundary sojo-app's PHP proxy enforces for its logged-in users
// (never hand the full roster to a browser), every public action below
// fetches the whole roster server-side and returns only the ONE matching
// member's minimal fields -- never the raw roster, never this URL.

function fetchAppsScript(string $action, array $params = []): array {
  if (!defined('APPS_SCRIPT_URL') || APPS_SCRIPT_URL === '') {
    fail('The SOJO roster isn\'t configured yet', 502);
  }
  $query = http_build_query(array_merge(['action' => $action], $params));
  $ch = curl_init(APPS_SCRIPT_URL . '?' . $query);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_TIMEOUT => 20,
  ]);
  $raw = curl_exec($ch);
  if ($raw === false) {
    $err = curl_error($ch);
    curl_close($ch);
    fail('Could not reach the SOJO roster: ' . $err, 502);
  }
  curl_close($ch);
  $decoded = json_decode($raw, true);
  if (!is_array($decoded)) {
    fail('Unexpected response from the SOJO roster', 502);
  }
  return $decoded;
}

function findSingerByEmail(string $email): ?array {
  if ($email === '') {
    return null;
  }
  $target = mb_strtolower($email);
  $data = fetchAppsScript('getSingers');
  foreach (($data['singers'] ?? []) as $singer) {
    if (mb_strtolower(trim((string)($singer['email'] ?? ''))) === $target) {
      return $singer;
    }
  }
  return null;
}

// The minimal fields volunteer.html/absent.html/myinfo.html need to identify
// and contact a member -- deliberately not the raw singer object (no
// address, notes, pic, etc).
function memberSummary(array $singer): array {
  $firstname = trim((string)($singer['firstname'] ?? ''));
  $lastname = trim((string)($singer['lastname'] ?? ''));
  $name = ($firstname !== '' || $lastname !== '')
    ? trim($firstname . ' ' . $lastname)
    : (string)($singer['combined'] ?? '');
  return [
    'name' => $name,
    'email' => (string)($singer['email'] ?? ''),
    'cellPhone' => (string)($singer['cellPhone'] ?? ''),
    'homePhone' => (string)($singer['homePhone'] ?? ''),
    'position' => (string)($singer['position'] ?? ''),
    'section' => (string)($singer['section'] ?? ''),
  ];
}

// Most-recent-first list of { date, code, label, color } built from the
// singer's own attendance{} (keyed by sheet column index) plus getConfig()'s
// ordered date/attendanceCode reference data.
function buildAttendanceList(array $singer): array {
  $config = fetchAppsScript('getConfig');
  $codes = [];
  foreach (($config['attendanceCodes'] ?? []) as $c) {
    $codes[(string)($c['code'] ?? '')] = $c;
  }
  $attendance = $singer['attendance'] ?? [];
  $out = [];
  foreach (($config['dates'] ?? []) as $d) {
    $code = (string)($attendance[(string)($d['col'] ?? '')] ?? '');
    $info = $codes[$code] ?? ['label' => 'Unknown', 'color' => 'gray'];
    $out[] = [
      'date' => (string)($d['label'] ?? ''),
      'code' => $code,
      'label' => (string)($info['label'] ?? ''),
      'color' => (string)($info['color'] ?? 'gray'),
    ];
  }
  return array_reverse($out);
}

// Attendance by Day report: for each rehearsal date column in the SOJO
// roster sheet (getConfig's dates, labeled M/D/YYYY), counts how the active
// participants -- SEQ 1-60, i.e. every voice part but not HOLD (90) or a
// blank SEQ -- are marked: X present, O absent or E exempt (both counted as
// absent -- the choir treats them the same), blank not marked.
// Returns only these counts, never the roster itself. `from`/`to` are
// YYYY-MM-DD or '' for no limit.
function getAttendanceByDayReport(string $from, string $to): array {
  $config = fetchAppsScript('getConfig');
  $data = fetchAppsScript('getSingers');
  $participants = array_values(array_filter($data['singers'] ?? [], function ($s) {
    $seq = (int)($s['seq'] ?? 0);
    return $seq > 0 && $seq <= 60;
  }));

  $rows = [];
  foreach (($config['dates'] ?? []) as $d) {
    if (!preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', trim((string)($d['label'] ?? '')), $m)) {
      continue;
    }
    $iso = sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2]);
    if (($from !== '' && $iso < $from) || ($to !== '' && $iso > $to)) {
      continue;
    }
    $counts = ['Present' => 0, 'Absent' => 0, 'NotMarked' => 0];
    $col = (string)($d['col'] ?? '');
    foreach ($participants as $s) {
      $code = strtoupper(trim((string)($s['attendance'][$col] ?? '')));
      if ($code === 'X') $counts['Present']++;
      elseif ($code === 'O' || $code === 'E') $counts['Absent']++;
      else $counts['NotMarked']++;
    }
    $rows[] = ['Date' => $iso] + $counts;
  }
  usort($rows, function ($a, $b) { return strcmp($a['Date'], $b['Date']); });

  return ['participants' => count($participants), 'rows' => $rows];
}

// Frequent Absences report: participants (SEQ 1-60) with more than 3
// absences in [from, to], plus anyone who hasn't attended at all. An
// absence is O or E (the choir treats them the same), with E also counted
// separately. For a New 2026 singer, absences before their first X are
// ignored -- they hadn't joined yet. Someone with no X at all gets a
// "Has not attended" note and every absence counted (there's no first
// attendance to measure from), and is listed at any absence count.
// Sorted by absences (most first), then last/first name. Returns only the
// fields shown -- never the raw singer rows.
function getFrequentAbsencesReport(string $from, string $to): array {
  $config = fetchAppsScript('getConfig');
  $data = fetchAppsScript('getSingers');

  $dates = [];
  foreach (($config['dates'] ?? []) as $d) {
    if (!preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', trim((string)($d['label'] ?? '')), $m)) {
      continue;
    }
    $iso = sprintf('%04d-%02d-%02d', $m[3], $m[1], $m[2]);
    if (($from !== '' && $iso < $from) || ($to !== '' && $iso > $to)) {
      continue;
    }
    $dates[$iso] = (string)($d['col'] ?? '');
  }
  ksort($dates);

  $rows = [];
  foreach (($data['singers'] ?? []) as $s) {
    $seq = (int)($s['seq'] ?? 0);
    if ($seq <= 0 || $seq > 60) {
      continue;
    }
    $isNew = strtoupper(trim((string)($s['new2026'] ?? ''))) === 'Y';
    $codes = [];
    foreach ($dates as $col) {
      $codes[] = strtoupper(trim((string)($s['attendance'][$col] ?? '')));
    }
    $hasAttended = in_array('X', $codes, true);

    $absent = 0; $exempt = 0; $started = !$isNew || !$hasAttended;
    foreach ($codes as $code) {
      if ($code === 'X') {
        $started = true;
      } elseif ($started && ($code === 'O' || $code === 'E')) {
        $absent++;
        if ($code === 'E') $exempt++;
      }
    }

    if ($absent > 3 || (!$hasAttended && $absent > 0)) {
      $summary = memberSummary($s);
      $rows[] = [
        'LastName' => trim((string)($s['lastname'] ?? '')),
        'FirstName' => trim((string)($s['firstname'] ?? '')),
        'Name' => $summary['name'],
        'PhoneNumber' => $summary['cellPhone'] !== '' ? $summary['cellPhone'] : $summary['homePhone'],
        'Position' => $summary['position'],
        'New2026' => $isNew,
        'Absent' => $absent,
        'Exempt' => $exempt,
        'Notes' => $hasAttended ? '' : 'Has not attended',
      ];
    }
  }

  usort($rows, function ($a, $b) {
    return [$b['Absent'], mb_strtolower($a['LastName']), mb_strtolower($a['FirstName'])]
       <=> [$a['Absent'], mb_strtolower($b['LastName']), mb_strtolower($b['FirstName'])];
  });

  return ['dateCount' => count($dates), 'rows' => $rows];
}

// Member List report: everyone connected to the app, each person once,
// grouped Choir Admins (choir-admin-panel access) > Section Leaders
// (choir_section_leaders rows) > Members (south-jordan-choral-arts access),
// highest group wins. People are matched across the three by email
// (users.username is the login email). Name/position/phone come from the
// SOJO roster where the email matches, falling back to the account's
// display name / the SectionLeaders row; a section leader's own
// leader_phone wins over the roster's. Sorted by name within each group.
function getMemberListReport(): array {
  $conn = db();
  $roster = [];
  foreach ((fetchAppsScript('getSingers')['singers'] ?? []) as $s) {
    $email = mb_strtolower(trim((string)($s['email'] ?? '')));
    if ($email !== '') $roster[$email] = $s;
  }

  $people = []; // email (or "leader:<id>" if no email) => row
  $add = function (string $key, string $group, string $name, string $position, string $phone, string $email) use (&$people, $roster) {
    if (isset($people[$key])) return; // already in a higher group
    $singer = $email !== '' ? ($roster[mb_strtolower($email)] ?? null) : null;
    if ($singer) {
      $last = trim((string)($singer['lastname'] ?? ''));
      $first = trim((string)($singer['firstname'] ?? ''));
      if ($last !== '' || $first !== '') $name = $last !== '' && $first !== '' ? "$last, $first" : trim("$last$first");
      if ($position === '') $position = (string)($singer['position'] ?? '');
      if ($phone === '') {
        $phone = trim((string)($singer['cellPhone'] ?? ''));
        if ($phone === '') $phone = trim((string)($singer['homePhone'] ?? ''));
      }
    }
    $people[$key] = ['Group' => $group, 'Name' => $name !== '' ? $name : $email,
                     'Position' => $position, 'PhoneNumber' => $phone, 'Email' => $email];
  };

  $usersWithAccess = function (string $appKey) use ($conn): array {
    $stmt = $conn->prepare(
      'SELECT u.username, u.display_name FROM app_access aa
       JOIN apps a ON a.id = aa.app_id JOIN users u ON u.id = aa.user_id
       WHERE a.app_key = ?'
    );
    $stmt->bind_param('s', $appKey);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
  };

  foreach ($usersWithAccess('choir-admin-panel') as $u) {
    $email = trim((string)$u['username']);
    $add(mb_strtolower($email), 'Choir Admin', trim((string)$u['display_name']), '', '', $email);
  }
  $leaders = $conn->query('SELECT id, position, leader_name, leader_email, leader_phone FROM choir_section_leaders')
                  ->fetch_all(MYSQLI_ASSOC);
  foreach ($leaders as $l) {
    $name = trim((string)$l['leader_name']);
    $email = trim((string)$l['leader_email']);
    if ($name === '' && $email === '') continue; // unfilled position
    $key = $email !== '' ? mb_strtolower($email) : 'leader:' . $l['id'];
    $add($key, 'Section Leader', $name, (string)$l['position'], trim((string)($l['leader_phone'] ?? '')), $email);
  }
  foreach ($usersWithAccess('south-jordan-choral-arts') as $u) {
    $email = trim((string)$u['username']);
    $add(mb_strtolower($email), 'Member', trim((string)$u['display_name']), '', '', $email);
  }

  $order = ['Choir Admin' => 0, 'Section Leader' => 1, 'Member' => 2];
  $rows = array_values($people);
  usort($rows, function ($a, $b) use ($order) {
    return [$order[$a['Group']], mb_strtolower($a['Name'])] <=> [$order[$b['Group']], mb_strtolower($b['Name'])];
  });
  return ['rows' => $rows];
}

// App Users by Day report: distinct member-app users per day from the
// shared app_usage_log (see logAppUsage -- only requireMember() actions log,
// so admin-panel use isn't counted). Every day in [from, to] gets a row,
// including zero days, most recent first. A blank `to` is today (MySQL's
// clock, same one logAppUsage stamps with); a blank `from` is the earliest
// logged day. Capped at 366 days so an open range can't build a huge list.
function getAppUsageReport(string $from, string $to): array {
  $conn = db();
  $appKey = 'south-jordan-choral-arts';
  if ($to === '') {
    $to = $conn->query('SELECT CURDATE() AS d')->fetch_assoc()['d'];
  }
  if ($from === '') {
    $stmt = $conn->prepare('SELECT MIN(access_date) AS d FROM app_usage_log WHERE app_key = ?');
    $stmt->bind_param('s', $appKey);
    $stmt->execute();
    $from = $stmt->get_result()->fetch_assoc()['d'] ?? $to;
    $stmt->close();
  }

  $stmt = $conn->prepare(
    'SELECT access_date, COUNT(DISTINCT user_id) AS users FROM app_usage_log
     WHERE app_key = ? AND access_date BETWEEN ? AND ? GROUP BY access_date'
  );
  $stmt->bind_param('sss', $appKey, $from, $to);
  $stmt->execute();
  $byDate = [];
  foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
    $byDate[$r['access_date']] = (int)$r['users'];
  }
  $stmt->close();

  $stmt = $conn->prepare(
    'SELECT COUNT(DISTINCT user_id) AS users FROM app_usage_log
     WHERE app_key = ? AND access_date BETWEEN ? AND ?'
  );
  $stmt->bind_param('sss', $appKey, $from, $to);
  $stmt->execute();
  $uniqueUsers = (int)$stmt->get_result()->fetch_assoc()['users'];
  $stmt->close();

  $rows = [];
  $day = new DateTime($to);
  $start = new DateTime($from);
  while ($day >= $start && count($rows) < 366) {
    $iso = $day->format('Y-m-d');
    $rows[] = ['Date' => $iso, 'Users' => $byDate[$iso] ?? 0];
    $day->modify('-1 day');
  }

  return ['from' => $from, 'to' => $to, 'uniqueUsers' => $uniqueUsers, 'rows' => $rows];
}

// Same fallback-to-director logic as sendAbsenceEmail, but for display
// rather than a notification: no leader row (or an empty one) on file falls
// back to Settings.DirectorEmail with the name "Director" -- there's no
// director phone number setting, so that field stays blank in that case.
function getSectionLeaderContact(string $position): array {
  $leaderName = null;
  $leaderEmail = null;
  $leaderPhone = null;

  if ($position !== '') {
    $stmt = db()->prepare('SELECT leader_name, leader_email, leader_phone FROM choir_section_leaders WHERE position = ?');
    $stmt->bind_param('s', $position);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
      $leaderName = trim((string)$row['leader_name']) !== '' ? trim($row['leader_name']) : null;
      $leaderEmail = trim((string)$row['leader_email']) !== '' ? trim($row['leader_email']) : null;
      $leaderPhone = trim((string)($row['leader_phone'] ?? '')) !== '' ? trim($row['leader_phone']) : null;
    }
  }

  if (!$leaderEmail && !$leaderPhone) {
    $directorEmail = getSetting('DirectorEmail');
    if ($directorEmail) {
      $leaderName = $leaderName ?: 'Director';
      $leaderEmail = $directorEmail;
    }
  }

  return ['name' => $leaderName, 'email' => $leaderEmail, 'phone' => $leaderPhone];
}

// ---- Router ----

$method = $_SERVER['REQUEST_METHOD'];
$body = $method === 'POST' ? jsonBody() : [];
$action = $method === 'GET' ? ($_GET['action'] ?? '') : ($body['action'] ?? '');

switch ($action) {

  // -- Member reads (GET, requires login + app_access) --

  case 'schedule':
  case 'announcements':
  case 'recognition':
  case 'sponsors': {
    requireMember();
    $sheetByAction = [
      'schedule' => 'Schedule',
      'announcements' => 'Announcements', 'recognition' => 'Recognition', 'sponsors' => 'Sponsors',
    ];
    respond(tableRows($sheetByAction[$action]));
  }

  // Includes each row's id (as Id) so songs.html can link to song.html?id=...
  // Ordered by the admin-set Sequence (ascending); songs left at 0 fall to the
  // bottom, in title order.
  case 'songs': {
    requireMember();
    $rows = array_map(function ($row) {
      $row['Id'] = $row['_row'];
      unset($row['_row']);
      return $row;
    }, tableRowsWithId('Songs'));
    usort($rows, function ($a, $b) {
      $sa = (int)($a['Sequence'] ?? 0) ?: PHP_INT_MAX;
      $sb = (int)($b['Sequence'] ?? 0) ?: PHP_INT_MAX;
      if ($sa !== $sb) {
        return $sa <=> $sb;
      }
      return strcasecmp((string)$a['Title'], (string)$b['Title']);
    });
    respond($rows);
  }

  // One song's part tracks + director notes for song.html. Each SongFiles row
  // comes back with a real Url built from SONG_FILES_BASE_URL + the song's
  // FolderSlug + the row's FileName -- see the SongFiles comment in $DATA_TABLES.
  case 'song': {
    requireMember();
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
      fail('Missing song id', 400);
    }
    $song = null;
    foreach (tableRowsWithId('Songs') as $row) {
      if ($row['_row'] === $id) { $song = $row; break; }
    }
    if (!$song) {
      fail('Song not found', 404);
    }
    $folder = trim((string)($song['FolderSlug'] ?? ''));
    $files = array_values(array_filter(tableRows('SongFiles'), function ($f) use ($id) {
      return (int)$f['SongID'] === $id;
    }));
    usort($files, function ($a, $b) { return $a['SortOrder'] <=> $b['SortOrder']; });
    $files = array_map(function ($f) use ($folder) {
      $f['Url'] = $folder !== '' ? SONG_FILES_BASE_URL . '/' . rawurlencode($folder) . '/' . rawurlencode($f['FileName']) : null;
      return $f;
    }, $files);
    respond(['Title' => $song['Title'], 'Status' => $song['Status'], 'Files' => $files]);
  }

  // Nav links for the top of every page — admin-editable order/visibility.
  // Same login requirement as schedule/songs/etc.; a logged-out visitor
  // falls back to DEFAULT_NAV_ITEMS in js/api.js instead of an empty nav.
  case 'navItems': {
    requireMember();
    $rows = array_values(array_filter(tableRows('NavItems'), function ($row) {
      return (int)$row['Visible'] === 1;
    }));
    usort($rows, function ($a, $b) { return $a['SortOrder'] <=> $b['SortOrder']; });
    respond($rows);
  }

  case 'documents': {
    requireMember();
    $rows = tableRows('Documents');
    usort($rows, function ($a, $b) { return $a['SortOrder'] <=> $b['SortOrder']; });
    respond($rows);
  }

  case 'settings': {
    requireMember();
    $rows = array_values(array_filter(tableRows('Settings'), function ($row) use ($PUBLIC_SETTINGS_KEYS) {
      return in_array($row['Key'], $PUBLIC_SETTINGS_KEYS, true);
    }));
    respond($rows);
  }

  case 'volunteerStatus':
    requireMember();
    respond(getVolunteerStatus());

  // -- SOJO roster lookups (GET, same login + app_access requirement) --

  case 'lookupMember': {
    requireMember();
    $email = trim((string)($_GET['email'] ?? ''));
    if ($email === '') {
      respond(['ok' => false, 'reason' => 'invalid']);
    }
    $singer = findSingerByEmail($email);
    if (!$singer) {
      respond(['ok' => false, 'reason' => 'not-found']);
    }
    respond(['ok' => true, 'member' => memberSummary($singer)]);
  }

  case 'myAttendance': {
    requireMember();
    $email = trim((string)($_GET['email'] ?? ''));
    if ($email === '') {
      respond(['ok' => false, 'reason' => 'invalid']);
    }
    $singer = findSingerByEmail($email);
    if (!$singer) {
      respond(['ok' => false, 'reason' => 'not-found']);
    }
    respond(['ok' => true, 'member' => memberSummary($singer), 'attendance' => buildAttendanceList($singer)]);
  }

  case 'sectionLeader': {
    requireMember();
    $position = trim((string)($_GET['position'] ?? ''));
    respond(['ok' => true, 'leader' => getSectionLeaderContact($position)]);
  }

  // Resolves a My Apps Hub SSO handoff token (?token=... on launch) to the
  // logged-in member's email, for the roster lookup pre-fill -- see
  // captureSsoEmail() in js/api.js. The token itself is saved separately as
  // this app's own Bearer token (requireMember() above checks it against the
  // same sessions table); this action only ever needs to prove the token is
  // some valid login, not that it's specifically authorized for this app --
  // that's checked per-action, same as everything else. Deliberately returns
  // {ok:false} rather than a 401 for a missing/expired/invalid token: this
  // part is a best-effort convenience, not the auth gate itself.
  case 'whoAmI': {
    $token = trim((string)($_GET['token'] ?? ''));
    if ($token === '') {
      respond(['ok' => false]);
    }
    $stmt = db()->prepare(
      'SELECT u.username FROM sessions s JOIN users u ON u.id = s.user_id
       WHERE s.token = ? AND s.expires_at > NOW()'
    );
    $stmt->bind_param('s', $token);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
      respond(['ok' => false]);
    }
    respond(['ok' => true, 'email' => $row['username']]);
  }

  // -- Member writes (POST, same login + app_access requirement) --

  case 'claimSlot':
    requireMember();
    respond(claimSlot($body));

  case 'markAbsent':
    requireMember();
    respond(markAbsent($body));

  // -- MyDataWorld login (shared with My Apps Hub/T-Minus/Shed Inventory/PWI) --

  case 'login': {
    $username = trim((string)($body['username'] ?? ''));
    $password = (string)($body['password'] ?? '');
    if ($username === '' || $password === '') {
      fail('Username and password are required');
    }
    $stmt = db()->prepare('SELECT id, password_hash, display_name FROM users WHERE username = ?');
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$user || !password_verify($password, $user['password_hash'])) {
      fail('Invalid username or password', 401);
    }
    $token = bin2hex(random_bytes(32));
    $days = SESSION_LIFETIME_DAYS;
    $ins = db()->prepare('INSERT INTO sessions (token, user_id, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))');
    $ins->bind_param('sii', $token, $user['id'], $days);
    $ins->execute();
    $ins->close();
    respond(['token' => $token, 'displayName' => $user['display_name']]);
  }

  case 'logout': {
    $token = (string)($body['token'] ?? '');
    if ($token !== '') {
      $stmt = db()->prepare('DELETE FROM sessions WHERE token = ?');
      $stmt->bind_param('s', $token);
      $stmt->execute();
      $stmt->close();
    }
    respond(['ok' => true]);
  }

  case 'checkAccess': {
    $user = requireUser();
    requireChoirAdminAccess($user);
    respond(['ok' => true, 'displayName' => $user['display_name']]);
  }

  // -- Admin panel (MyDataWorld auth + app_access, replaces the shared password) --

  case 'adminList': {
    $user = requireUser();
    requireChoirAdminAccess($user);
    $sheet = (string)($body['sheet'] ?? '');
    if (!array_key_exists($sheet, $DATA_TABLES)) {
      respond(['ok' => false, 'reason' => 'unknown-sheet']);
    }
    respond(['ok' => true, 'rows' => tableRowsWithId($sheet)]);
  }

  case 'adminVolunteerTasks': {
    $user = requireUser();
    requireChoirAdminAccess($user);
    respond(['ok' => true, 'rows' => getVolunteerTasksWithPhones()]);
  }

  case 'adminReport': {
    $user = requireUser();
    requireChoirAdminAccess($user);
    $report = (string)($body['report'] ?? '');
    $from = (string)($body['from'] ?? '');
    $to = (string)($body['to'] ?? '');
    $isDate = function ($d) { return (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d); };
    if (($from !== '' && !$isDate($from)) || ($to !== '' && !$isDate($to))) {
      respond(['ok' => false, 'reason' => 'invalid-date']);
    }
    if ($report === 'volunteerSignups') {
      // A blank `from` means "no start date" -- earliest valid MySQL DATE.
      respond(['ok' => true, 'rows' => getVolunteerSignupsReport($from !== '' ? $from : '1000-01-01', $to)]);
    }
    if ($report === 'memberList') {
      respond(['ok' => true] + getMemberListReport());
    }
    if ($report === 'frequentAbsences') {
      respond(['ok' => true] + getFrequentAbsencesReport($from, $to));
    }
    if ($report === 'appUsage') {
      respond(['ok' => true] + getAppUsageReport($from, $to));
    }
    if ($report === 'attendanceByDay') {
      respond(['ok' => true] + getAttendanceByDayReport($from, $to));
    }
    respond(['ok' => false, 'reason' => 'unknown-report']);
  }

  case 'adminAdd': {
    $user = requireUser();
    requireChoirAdminAccess($user);
    $sheet = (string)($body['sheet'] ?? '');
    if (!array_key_exists($sheet, $DATA_TABLES)) {
      respond(['ok' => false, 'reason' => 'unknown-sheet']);
    }
    $id = adminInsertRow($sheet, (array)($body['row'] ?? []));
    respond(['ok' => true, 'id' => $id]);
  }

  case 'adminUpdate': {
    $user = requireUser();
    requireChoirAdminAccess($user);
    $sheet = (string)($body['sheet'] ?? '');
    if (!array_key_exists($sheet, $DATA_TABLES)) {
      respond(['ok' => false, 'reason' => 'unknown-sheet']);
    }
    $rowId = (int)($body['row'] ?? 0);
    if ($rowId <= 0) {
      respond(['ok' => false, 'reason' => 'invalid-row']);
    }
    adminUpdateRow($sheet, $rowId, (array)($body['values'] ?? []));
    respond(['ok' => true]);
  }

  case 'adminDelete': {
    $user = requireUser();
    requireChoirAdminAccess($user);
    $sheet = (string)($body['sheet'] ?? '');
    if (!array_key_exists($sheet, $DATA_TABLES)) {
      respond(['ok' => false, 'reason' => 'unknown-sheet']);
    }
    $rowId = (int)($body['row'] ?? 0);
    if ($rowId <= 0) {
      respond(['ok' => false, 'reason' => 'invalid-row']);
    }
    adminDeleteRow($sheet, $rowId);
    respond(['ok' => true]);
  }

  default:
    respond(['ok' => false, 'error' => 'Unknown action: ' . $action], 404);
}
