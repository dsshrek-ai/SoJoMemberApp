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

$SHEET_TABLES = [
  'Schedule' => ['table' => 'choir_schedule', 'columns' => [
    'Date' => 'entry_date', 'Time' => 'time_text', 'Type' => 'type', 'Title' => 'title',
    'Location' => 'location', 'ParkingNotes' => 'parking_notes', 'EntranceNotes' => 'entrance_notes',
    'Notes' => 'notes',
  ]],
  'Songs' => ['table' => 'choir_songs', 'columns' => [
    'Title' => 'title', 'RehearsalTrackURL' => 'rehearsal_track_url', 'YouTubeURL' => 'youtube_url',
    'LastRehearsedDate' => 'last_rehearsed_date', 'Status' => 'status',
  ]],
  'Lyrics' => ['table' => 'choir_lyrics', 'columns' => [
    'SongTitle' => 'song_title', 'Part' => 'part', 'LyricsText' => 'lyrics_text',
  ]],
  'Announcements' => ['table' => 'choir_announcements', 'columns' => [
    'Date' => 'entry_date', 'Author' => 'author', 'Message' => 'message', 'Pinned' => 'pinned',
  ]],
  'VolunteerTasks' => ['table' => 'choir_volunteer_tasks', 'columns' => [
    'Date' => 'entry_date', 'TaskName' => 'task_name', 'SlotsNeeded' => 'slots_needed',
  ]],
  'VolunteerSignups' => ['table' => 'choir_volunteer_signups', 'columns' => [
    'Date' => 'entry_date', 'TaskName' => 'task_name', 'VolunteerName' => 'volunteer_name',
    'PhoneNumber' => 'phone_number', 'Timestamp' => 'signed_up_at',
  ]],
  'Absences' => ['table' => 'choir_absences', 'columns' => [
    'Date' => 'entry_date', 'MemberName' => 'member_name', 'Position' => 'position',
    'Note' => 'note', 'Timestamp' => 'reported_at',
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
  ]],
];

// Columns that must round-trip as JSON numbers, not strings (so `>=` comparisons
// in the front end work correctly instead of comparing lexically).
$NUMERIC_COLUMNS = ['SlotsNeeded'];

// The public `settings` action must NOT return every row — Settings also holds
// DirectorEmail. Allowlist, not blocklist, matching the old Code.gs behavior.
$PUBLIC_SETTINGS_KEYS = [
  'WelcomeMessage', 'DonationURL', 'NewMemberFormURL', 'AuditionInfoText', 'AuditionFormURL',
  'InstructionsText',
];

function tableRowsWithId(string $sheetName): array {
  global $SHEET_TABLES;
  $spec = $SHEET_TABLES[$sheetName];
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
  global $SHEET_TABLES, $NUMERIC_COLUMNS;
  $spec = $SHEET_TABLES[$sheetName];
  $cols = []; $placeholders = []; $types = ''; $values = [];
  foreach ($spec['columns'] as $jsonKey => $dbCol) {
    $cols[] = $dbCol;
    $placeholders[] = '?';
    if (in_array($jsonKey, $NUMERIC_COLUMNS, true)) {
      $types .= 'i';
      $values[] = (int)($rowData[$jsonKey] ?? 0);
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
  global $SHEET_TABLES, $NUMERIC_COLUMNS;
  $spec = $SHEET_TABLES[$sheetName];
  $sets = []; $types = ''; $values = [];
  foreach ($spec['columns'] as $jsonKey => $dbCol) {
    $sets[] = "$dbCol = ?";
    if (in_array($jsonKey, $NUMERIC_COLUMNS, true)) {
      $types .= 'i';
      $values[] = (int)($rowData[$jsonKey] ?? 0);
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
  global $SHEET_TABLES;
  $spec = $SHEET_TABLES[$sheetName];
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
      'INSERT INTO choir_volunteer_signups (entry_date, task_name, volunteer_name, phone_number, signed_up_at)
       VALUES (?, ?, ?, ?, NOW())'
    );
    $stmt->bind_param('ssss', $date, $taskName, $volunteerName, $phoneNumber);
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
  $note = trim((string)($body['note'] ?? ''));

  $stmt = db()->prepare(
    'INSERT INTO choir_absences (entry_date, member_name, position, note, reported_at)
     VALUES (?, ?, ?, ?, NOW())'
  );
  $stmt->bind_param('ssss', $date, $memberName, $position, $note);
  $stmt->execute();
  $stmt->close();

  sendAbsenceEmail($memberName, $date, $position, $note);

  return ['ok' => true];
}

function sendAbsenceEmail(string $memberName, string $date, string $position, string $note): void {
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
    ($note ? "\n\nNote: " . $note : '');
  $headers = 'From: ' . FROM_EMAIL . "\r\n";
  @mail($leaderEmail, $subject, $body, $headers);
}

// ---- Router ----

$method = $_SERVER['REQUEST_METHOD'];
$body = $method === 'POST' ? jsonBody() : [];
$action = $method === 'GET' ? ($_GET['action'] ?? '') : ($body['action'] ?? '');

switch ($action) {

  // -- Public reads (GET) --

  case 'schedule':
  case 'songs':
  case 'lyrics':
  case 'announcements':
  case 'recognition':
  case 'sponsors': {
    $sheetByAction = [
      'schedule' => 'Schedule', 'songs' => 'Songs', 'lyrics' => 'Lyrics',
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
    if (!array_key_exists($sheet, $SHEET_TABLES)) {
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
    if (!array_key_exists($sheet, $SHEET_TABLES)) {
      respond(['ok' => false, 'reason' => 'unknown-sheet']);
    }
    $id = adminInsertRow($sheet, (array)($body['row'] ?? []));
    respond(['ok' => true, 'id' => $id]);
  }

  case 'adminUpdate': {
    $user = requireUser();
    requireChoirAdminAccess($user);
    $sheet = (string)($body['sheet'] ?? '');
    if (!array_key_exists($sheet, $SHEET_TABLES)) {
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
    if (!array_key_exists($sheet, $SHEET_TABLES)) {
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
