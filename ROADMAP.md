# Choir App Roadmap

Architecture: static HTML/CSS/JS (this repo) hosted on GitHub Pages, talking to a single
Google Apps Script Web App (`apps-script/Code.gs`), which reads/writes a Google Sheet.
No login for members — the Apps Script runs under the director's Google account, so it can
write to the Sheet without members ever signing in.

The director edits most content **directly in the Sheet** (announcements, schedule, songs,
recognition, sponsors, settings). Only volunteer-slot claims and absence marks are written
by the app itself, via the Apps Script API.

## Sheet tabs

| Tab | Columns |
|---|---|
| `Schedule` | Date, Time, Type, Title, Location, ParkingNotes, EntranceNotes, Notes |
| `Songs` | Title, RehearsalTrackURL, YouTubeURL, LastRehearsedDate, Status |
| `Lyrics` | SongTitle, Part, LyricsText |
| `Announcements` | Date, Author, Message, Pinned |
| `VolunteerTasks` | Date, TaskName, SlotsNeeded |
| `VolunteerSignups` | Date, TaskName, VolunteerName, PhoneNumber, Timestamp |
| `Absences` | Date, MemberName, Note, Timestamp |
| `Recognition` | Date, MemberName, Message |
| `MusicFolders` | MemberName, FolderAssignment |
| `Sponsors` | SponsorName, LogoURL, Message, Tier |
| `Settings` | Key, Value |

Starter CSVs for each tab are in `sheet-templates/`.

## Apps Script API

Public (no password, used by member-facing pages):
- `GET ?action=schedule|songs|lyrics|announcements|volunteerStatus|recognition|folders|sponsors|settings`
- `POST {action: "claimSlot", date, taskName, volunteerName, phoneNumber}` — guarded by `LockService`.
  Writes are header-driven (`headers.map(...)`), not positional, so adding/reordering
  VolunteerSignups columns in the Sheet won't break it as long as header names match.
- `POST {action: "markAbsent", date, memberName, note}` — emails `DirectorEmail` (from Settings) if set

Admin (all POST, all require `password` matching `Settings.AdminPassword`, checked server-side
in `requireAdmin`/`isAdmin`):
- `adminAuth` → `{password}` → `{ok}`
- `adminList` → `{password, sheet}` → `{ok, rows}` (rows include a `_row` sheet-row number)
- `adminAdd` → `{password, sheet, row: {...}}`
- `adminUpdate` → `{password, sheet, row: <number>, values: {...}}`
- `adminDelete` → `{password, sheet, row: <number>}`

`sheet` must be one of `ADMIN_SHEETS` in `Code.gs` (all 11 tabs).

## Phases

- **Phase 0 (done)** — Repo scaffold, shared CSS/JS, Sheet schema + CSV templates, Apps Script
  with all `GET` read actions and `claimSlot` wired, nav shell across all pages, deploy instructions.
- **Phase 1 (done)** — Read-only pages verified against real Sheet data: Schedule, Announcements,
  Songs, Volunteer status, Home welcome message.
- **Phase 2 (done)** — Lyrics-by-part quick view: song + part `<select>` dropdowns on `lyrics.html`
  filter the fetched `Lyrics` rows and render large-text lyrics only.
- **Phase 3 (done)** — Volunteer signup wired end-to-end: `volunteer.html` claims a slot via
  `postAction("claimSlot", ...)`, prompts for a name, disables/labels full slots, refreshes counts.
- **Phase 4 (done)** — Absence notifications: `absent.html` form (name/date/note) posts
  `markAbsent`; `Code.gs` appends to `Absences` and emails `DirectorEmail` if set.
- **Phase 5 (done)** — Polish: skip-to-content link + `scope="col"` on admin table headers,
  disabled-button contrast fix, mobile nav changed from multi-row wrap to a compact horizontal
  scroll strip (was ~245px tall on a 375px-wide screen, now ~46px), `DIRECTOR-GUIDE.md` added
  for non-technical content updates via the admin panel.
- **Phase 6 (done)** — Admin/maintenance panel: password-gated `admin.html` (not in the public
  nav — bookmark it directly) with a table picker and a generic add/edit/delete editor covering
  all 11 Sheet tabs. Password lives in `Settings.AdminPassword` and is checked server-side in
  `Code.gs`, not just client-side.

All six phases are done. Future work isn't pre-planned — pick up wherever the user wants next;
this file plus `SETUP.md` and `DIRECTOR-GUIDE.md` should be enough context to do that without
replaying prior conversations.

## Post-roadmap additions

- **Phone numbers + "Text All"**: `volunteer.html` now collects a phone number (inline form,
  not `prompt()`) alongside the name at signup, stored in `VolunteerSignups.PhoneNumber`.
  `admin.html`'s `VolunteerTasks` rows each get a **Text All** button that fetches matching
  `VolunteerSignups` rows (same Date + TaskName), collects non-empty phone numbers, and opens
  `sms:num1,num2,...` in the browser. Deliberately admin-only (password-gated) — phone numbers
  are never included in the public `volunteerStatus` response, to avoid the same kind of leak
  fixed in the AdminPassword/DirectorEmail incident below.
- **Security fix (public settings leak)**: the public `settings` GET action used to return the
  *entire* `Settings` tab, including `AdminPassword` and `DirectorEmail`, to any visitor. Fixed
  with a `PUBLIC_SETTINGS_KEYS` allowlist in `Code.gs` — only intentionally-public keys
  (WelcomeMessage, DonationURL, NewMemberFormURL, AuditionInfoText, AuditionFormURL) are
  returned publicly now. Keep this allowlist pattern in mind before ever adding a new
  public-facing settings key or a new admin-only field to a Sheet that a public action reads.
