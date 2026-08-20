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

// ---- Auth (admin panel only — everything below is unauthenticated/public) ----

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

// ---- Table registry: sheet name (as the front end already knows it) -> ----
// ---- MySQL table + PascalCase JSON key -> snake_case column mapping    ----

$DATA_TABLES = [
  'Schedule' => ['table' => 'choir_schedule', 'columns' => [
    'Date' => 'entry_date', 'Time' => 'time_text', 'Type' => 'type', 'Title' => 'title',
    'Location' => 'location', 'ParkingNotes' => 'parking_notes', 'EntranceNotes' => 'entrance_notes',
    'Notes' => 'notes',
  ]],
  'Songs' => ['table' => 'choir_songs', 'columns' => [
    'Title' => 'title', 'RehearsalTrackURL' => 'rehearsal_track_url', 'YouTubeURL' => 'youtube_url',
    'LastRehearsedDate' => 'last_rehearsed_date', 'Status' => 'status',
  ]],
  'Announcements' => ['table' => 'choir_announcements', 'columns' => [
    'Date' => 'entry_date', 'Author' => 'author', 'Message' => 'message', 'Pinned' => 'pinned',
  ]],
  'VolunteerTasks' => ['table' => 'choir_volunteer_tasks', 'columns' => [
    'Date' => 'entry_date', 'TaskName' => 'task_name', 'SlotsNeeded' => 'slots_needed',
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
];

// Columns that must round-trip as JSON numbers, not strings (so `>=` comparisons
// in the front end work correctly instead of comparing lexically).
$NUMERIC_COLUMNS = ['SlotsNeeded'];

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

function getVolunteerStatus(): array {
  $tasks = tableRows('VolunteerTasks');
  $signups = tableRows('VolunteerSignups');
  return array_map(function ($task) use ($signups) {
    $filled = count(array_filter($signups, function ($s) use ($task) {
      return $s['Date'] === $task['Date'] && $s['TaskName'] === $task['TaskName'];
    }));
    return ['Date' => $task['Date'], 'TaskName' => $task['TaskName'],
            'SlotsNeeded' => $task['SlotsNeeded'], 'SlotsFilled' => $filled];
  }, $tasks);
}

function getVolunteerTasksWithPhones(): array {
  $tasks = tableRowsWithId('VolunteerTasks');
  $signups = tableRows('VolunteerSignups');
  return array_map(function ($task) use ($signups) {
    $phones = array_values(array_filter(array_map(function ($s) use ($task) {
      return ($s['Date'] === $task['Date'] && $s['TaskName'] === $task['TaskName']) ? $s['PhoneNumber'] : null;
    }, $signups)));
    $task['PhoneNumbers'] = $phones;
    return $task;
  }, $tasks);
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

  // -- Public reads (GET) --

  case 'schedule':
  case 'songs':
  case 'announcements':
  case 'recognition':
  case 'sponsors': {
    $sheetByAction = [
      'schedule' => 'Schedule', 'songs' => 'Songs',
      'announcements' => 'Announcements', 'recognition' => 'Recognition', 'sponsors' => 'Sponsors',
    ];
    respond(tableRows($sheetByAction[$action]));
  }

  case 'settings': {
    $rows = array_values(array_filter(tableRows('Settings'), function ($row) use ($PUBLIC_SETTINGS_KEYS) {
      return in_array($row['Key'], $PUBLIC_SETTINGS_KEYS, true);
    }));
    respond($rows);
  }

  case 'volunteerStatus':
    respond(getVolunteerStatus());

  // -- Public SOJO roster lookups (GET, no auth — see the section above) --

  case 'lookupMember': {
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
    $position = trim((string)($_GET['position'] ?? ''));
    respond(['ok' => true, 'leader' => getSectionLeaderContact($position)]);
  }

  // Resolves a My Apps Hub SSO handoff token (?token=... on launch) to the
  // logged-in member's email, so it can pre-fill the same lookup box a
  // typed email would -- see captureSsoEmail() in js/api.js. Deliberately
  // returns {ok:false} rather than a 401 for a missing/expired/invalid
  // token: this is a best-effort convenience, not an auth gate, and a
  // failure here should just fall through to "ask for the email" like
  // always, not surface as an error.
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

  // -- Public writes (POST, no auth — matches the old no-login behavior) --

  case 'claimSlot':
    respond(claimSlot($body));

  case 'markAbsent':
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
