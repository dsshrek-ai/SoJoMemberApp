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

function doGet(e) {
  var action = e.parameter.action;

  if (action === "volunteerStatus") {
    return jsonResponse(getVolunteerStatus());
  }

  var sheetName = READ_ACTIONS[action];
  if (!sheetName) {
    return jsonResponse({ error: "Unknown action: " + action });
  }
  return jsonResponse(sheetToObjects(sheetName));
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
    return jsonResponse({ ok: false, reason: "not-implemented-until-phase-4" });
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

function sameDate(a, b) {
  if (!a || !b) return false;
  return new Date(a).toDateString() === new Date(b).toDateString();
}

function jsonResponse(data) {
  var output = ContentService.createTextOutput(JSON.stringify(data));
  output.setMimeType(ContentService.MimeType.JSON);
  return output;
}
