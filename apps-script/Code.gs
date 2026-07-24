// Choir App backend. Deploy as a Web App ("Execute as: Me", "Who has access: Anyone").
// See ../SETUP.md for step-by-step deployment instructions.

var READ_ACTIONS = {
  schedule: "Schedule",
  songs: "Songs",
  lyrics: "Lyrics",
  announcements: "Announcements",
  recognition: "Recognition",
  folders: "MusicFolders",
  sponsors: "Sponsors",
  settings: "Settings",
};

// Sheets the admin panel is allowed to touch. Guards against an arbitrary/typo'd
// sheet name being passed in from the client.
var ADMIN_SHEETS = [
  "Schedule", "Songs", "Lyrics", "Announcements", "VolunteerTasks",
  "VolunteerSignups", "Absences", "Recognition", "MusicFolders", "Sponsors", "Settings",
];

// The public `settings` action must NOT return every row in the Settings tab — that tab
// also holds AdminPassword and DirectorEmail. Only these keys are safe for every visitor
// to see. Add new public-facing settings keys here explicitly (allowlist, not blocklist).
var PUBLIC_SETTINGS_KEYS = [
  "WelcomeMessage", "DonationURL", "NewMemberFormURL", "AuditionInfoText", "AuditionFormURL",
];

function doGet(e) {
  var action = e.parameter.action;

  if (action === "volunteerStatus") {
    return jsonResponse(getVolunteerStatus());
  }
  if (action === "settings") {
    return jsonResponse(getPublicSettings());
  }

  var sheetName = READ_ACTIONS[action];
  if (!sheetName) {
    return jsonResponse({ error: "Unknown action: " + action });
  }
  return jsonResponse(sheetToObjects(sheetName));
}

function getPublicSettings() {
  return sheetToObjects("Settings").filter(function (s) {
    return PUBLIC_SETTINGS_KEYS.indexOf(s.Key) !== -1;
  });
}

function doPost(e) {
  var body;
  try {
    body = JSON.parse(e.postData.contents);
  } catch (err) {
    return jsonResponse({ ok: false, reason: "invalid-json" });
  }

  if (body.action === "claimSlot") {
    return jsonResponse(claimSlot(body));
  }
  if (body.action === "markAbsent") {
    return jsonResponse(markAbsent(body));
  }
  if (body.action === "adminAuth") {
    return jsonResponse({ ok: isAdmin(body.password) });
  }
  if (body.action === "adminList") {
    return jsonResponse(requireAdmin(body, function () {
      return { ok: true, rows: sheetToObjectsWithRow(body.sheet) };
    }));
  }
  if (body.action === "adminAdd") {
    return jsonResponse(requireAdmin(body, function () { return adminAdd(body); }));
  }
  if (body.action === "adminUpdate") {
    return jsonResponse(requireAdmin(body, function () { return adminUpdate(body); }));
  }
  if (body.action === "adminDelete") {
    return jsonResponse(requireAdmin(body, function () { return adminDelete(body); }));
  }
  return jsonResponse({ ok: false, reason: "unknown-action" });
}

// Reads a sheet tab into an array of objects keyed by its header row.
function sheetToObjects(sheetName) {
  var sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(sheetName);
  if (!sheet) return [];
  var values = sheet.getDataRange().getValues();
  if (values.length < 2) return [];
  var headers = values[0];
  var rows = [];
  for (var i = 1; i < values.length; i++) {
    var row = values[i];
    if (row.every(function (cell) { return cell === "" || cell === null; })) continue;
    var obj = {};
    for (var j = 0; j < headers.length; j++) {
      obj[headers[j]] = formatCell(row[j]);
    }
    rows.push(obj);
  }
  return rows;
}

// Sheets returns date/time cells as JS Date objects, which JSON.stringify turns into
// unreadable ISO strings (and time-only cells use the 1899-12-30 epoch). Format both
// as plain readable strings; leave numbers/text/booleans untouched.
function formatCell(value) {
  if (Object.prototype.toString.call(value) !== "[object Date]") {
    return value;
  }
  var tz = Session.getScriptTimeZone();
  if (value.getFullYear() === 1899) {
    return Utilities.formatDate(value, tz, "h:mm a");
  }
  return Utilities.formatDate(value, tz, "yyyy-MM-dd");
}

// Combines VolunteerTasks (what's needed) with a live count from VolunteerSignups.
function getVolunteerStatus() {
  var tasks = sheetToObjects("VolunteerTasks");
  var signups = sheetToObjects("VolunteerSignups");
  return tasks.map(function (task) {
    var filled = signups.filter(function (s) {
      return sameDate(s.Date, task.Date) && s.TaskName === task.TaskName;
    }).length;
    return {
      Date: task.Date,
      TaskName: task.TaskName,
      SlotsNeeded: task.SlotsNeeded,
      SlotsFilled: filled,
    };
  });
}

// Placeholder for Phase 3: claim a slot in VolunteerTasks/VolunteerSignups,
// guarded by LockService so two people can't fill the last slot at once.
function claimSlot(body) {
  var lock = LockService.getScriptLock();
  lock.waitLock(10000);
  try {
    var status = getVolunteerStatus();
    var match = status.filter(function (s) {
      return sameDate(s.Date, body.date) && s.TaskName === body.taskName;
    })[0];
    if (!match) {
      return { ok: false, reason: "not-found" };
    }
    if (match.SlotsFilled >= match.SlotsNeeded) {
      return { ok: false, reason: "full" };
    }
    var sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName("VolunteerSignups");
    sheet.appendRow([body.date, body.taskName, body.volunteerName, new Date()]);
    return { ok: true };
  } finally {
    lock.releaseLock();
  }
}

// Records an absence and, if a DirectorEmail is set in the Settings tab, emails a heads-up.
function markAbsent(body) {
  var sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName("Absences");
  sheet.appendRow([body.date, body.memberName, body.note || "", new Date()]);

  var directorEmail = getSetting("DirectorEmail");
  if (directorEmail) {
    MailApp.sendEmail({
      to: directorEmail,
      subject: "Absence reported: " + body.memberName,
      body: body.memberName + " reported they can't make it on " + body.date +
        (body.note ? "\n\nNote: " + body.note : ""),
    });
  }
  return { ok: true };
}

function getSetting(key) {
  var match = sheetToObjects("Settings").filter(function (s) { return s.Key === key; })[0];
  return match ? match.Value : null;
}

function isAdmin(password) {
  var expected = getSetting("AdminPassword");
  return !!expected && password === expected;
}

// Runs `fn` only if body.password matches Settings.AdminPassword and body.sheet is a
// known admin-editable tab; otherwise returns a rejection the client can show.
function requireAdmin(body, fn) {
  if (!isAdmin(body.password)) {
    return { ok: false, reason: "unauthorized" };
  }
  if (body.sheet && ADMIN_SHEETS.indexOf(body.sheet) === -1) {
    return { ok: false, reason: "unknown-sheet" };
  }
  return fn();
}

// Like sheetToObjects, but includes each row's 1-indexed sheet row number as `_row`,
// so the admin UI can target that exact row for update/delete.
function sheetToObjectsWithRow(sheetName) {
  var sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(sheetName);
  if (!sheet) return [];
  var values = sheet.getDataRange().getValues();
  if (values.length < 2) return [];
  var headers = values[0];
  var rows = [];
  for (var i = 1; i < values.length; i++) {
    var row = values[i];
    if (row.every(function (cell) { return cell === "" || cell === null; })) continue;
    var obj = { _row: i + 1 };
    for (var j = 0; j < headers.length; j++) {
      obj[headers[j]] = formatCell(row[j]);
    }
    rows.push(obj);
  }
  return rows;
}

// Appends a new row, pulling values out of body.row in the sheet's own header order
// (so the client doesn't need to know column order, just column names).
function adminAdd(body) {
  var lock = LockService.getScriptLock();
  lock.waitLock(10000);
  try {
    var sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(body.sheet);
    if (!sheet) return { ok: false, reason: "unknown-sheet" };
    var headers = sheet.getDataRange().getValues()[0];
    var values = headers.map(function (h) { return cellValue(body.row, h); });
    sheet.appendRow(values);
    return { ok: true };
  } finally {
    lock.releaseLock();
  }
}

// Overwrites an existing row (body.row = 1-indexed sheet row number) with body.values.
function adminUpdate(body) {
  var lock = LockService.getScriptLock();
  lock.waitLock(10000);
  try {
    var sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(body.sheet);
    if (!sheet) return { ok: false, reason: "unknown-sheet" };
    var headers = sheet.getDataRange().getValues()[0];
    if (body.row < 2 || body.row > sheet.getLastRow()) {
      return { ok: false, reason: "invalid-row" };
    }
    var values = headers.map(function (h) { return cellValue(body.values, h); });
    sheet.getRange(body.row, 1, 1, headers.length).setValues([values]);
    return { ok: true };
  } finally {
    lock.releaseLock();
  }
}

function cellValue(source, key) {
  if (!source || source[key] === undefined || source[key] === null) return "";
  return source[key];
}

function adminDelete(body) {
  var lock = LockService.getScriptLock();
  lock.waitLock(10000);
  try {
    var sheet = SpreadsheetApp.getActiveSpreadsheet().getSheetByName(body.sheet);
    if (!sheet) return { ok: false, reason: "unknown-sheet" };
    if (body.row < 2 || body.row > sheet.getLastRow()) {
      return { ok: false, reason: "invalid-row" };
    }
    sheet.deleteRow(body.row);
    return { ok: true };
  } finally {
    lock.releaseLock();
  }
}

function sameDate(a, b) {
  if (!a || !b) return false;
  return new Date(a).toDateString() === new Date(b).toDateString();
}

function jsonResponse(data) {
  var output = ContentService.createTextOutput(JSON.stringify(data));
  output.setMimeType(ContentService.MimeType.JSON);
  return output;
}
