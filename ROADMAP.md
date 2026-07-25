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
| `Sponsors` | SponsorName, LogoURL, Message, Tier |
| `Settings` | Key, Value |

Starter CSVs for each tab are in `sheet-templates/`. `MusicFolders` was an app feature in an
earlier phase — it's been unwired entirely (no page, no admin table, no API action) per the
user's request; the tab may still exist in an existing Sheet, the app just no longer touches it.

## Apps Script API

Public (no password, used by member-facing pages):
- `GET ?action=schedule|songs|lyrics|announcements|volunteerStatus|recognition|sponsors|settings`
- `POST {action: "claimSlot", date, taskName, volunteerName, phoneNumber}` — guarded by `LockService`.
  Writes are header-driven (`headers.map(...)`), not positional, so adding/reordering
  VolunteerSignups columns in the Sheet won't break it as long as header names match.
- `POST {action: "markAbsent", date, memberName, note}` — emails `DirectorEmail` (from Settings) if set

Admin (all POST, all require `password` matching `Settings.AdminPassword`, checked server-side
in `requireAdmin`/`isAdmin`):
- `adminAuth` → `{password}` → `{ok}`
- `adminList` → `{password, sheet}` → `{ok, rows}` (rows include a `_row` sheet-row number)
- `adminVolunteerTasks` → `{password}` → `{ok, rows}` — VolunteerTasks rows (with `_row`) plus
  each row's `PhoneNumbers` array, computed in one execution (see "Post-roadmap additions" below
  for why this exists instead of two `adminList` calls)
- `adminAdd` → `{password, sheet, row: {...}}`
- `adminUpdate` → `{password, sheet, row: <number>, values: {...}}`
- `adminDelete` → `{password, sheet, row: <number>}`

`sheet` must be one of `ADMIN_SHEETS` in `Code.gs` (all 10 tabs — `MusicFolders` was removed).

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

- **Phone numbers + "Copy Numbers"**: `volunteer.html` now collects a phone number (inline
  form, not `prompt()`) alongside the name at signup, stored in `VolunteerSignups.PhoneNumber`.
  `admin.html`'s `VolunteerTasks` rows each get a **Copy Numbers** button that copies every
  distinct phone number signed up for that task to the clipboard, for pasting into a texting
  app. Deliberately admin-only (password-gated) — phone numbers are never included in the
  public `volunteerStatus` response, to avoid the same kind of leak fixed in the
  AdminPassword/DirectorEmail incident below.
  - This went through a few iterations, each worth remembering if something similar comes up:
    1. Originally an `sms:num1,num2,...` link. `window.location.href = 'sms:...'` set inside an
       async click handler (after an `await`) silently failed on iOS Safari — it only trusts
       sms:/tel: navigation as the *direct* result of a click. Fixed by building a real
       `<a href="sms:...">` at render time instead.
    2. Fetching VolunteerTasks and VolunteerSignups as two separate admin-gated requests
       (tried sequentially, then in parallel via `Promise.all`) was unreliably slow — one
       report of a 5-minute stall, though a plain wrong-password round trip completed in a
       few seconds. Fixed with a dedicated `adminVolunteerTasks` action
       (`getVolunteerTasksWithPhones()` in `Code.gs`) that reads both tabs in one execution —
       matching how the already-reliable public `volunteerStatus` endpoint does it. If a
       similar stall ever shows up elsewhere, prefer this pattern (one request, server-side
       join) over multiple concurrent/sequential admin-gated calls.
    3. Still hung after that fix — turned out to be a real bug, not slowness: Google Sheets
       auto-detects all-digit phone numbers as a Number cell, not text, and the number-cleanup
       code called `.replace()` directly on the raw value, throwing on an actual number and
       silently breaking mid-render. Fixed by coercing with `String(raw ?? '')` first.
    4. Even once fixed, the `sms:` link itself turned out to only reliably open Messages to the
       *first* recipient — iOS/Android inconsistently honor multiple comma-separated recipients
       in an `sms:` URI, and there's no reliable web-side fix for that. Removed the `sms:` link
       entirely and kept only **Copy Numbers**, which sidesteps the platform limitation.
- **Security fix (public settings leak)**: the public `settings` GET action used to return the
  *entire* `Settings` tab, including `AdminPassword` and `DirectorEmail`, to any visitor. Fixed
  with a `PUBLIC_SETTINGS_KEYS` allowlist in `Code.gs` — only intentionally-public keys
  (WelcomeMessage, DonationURL, NewMemberFormURL, AuditionInfoText, AuditionFormURL) are
  returned publicly now. Keep this allowlist pattern in mind before ever adding a new
  public-facing settings key or a new admin-only field to a Sheet that a public action reads.
- **Lightweight text formatting**: `js/api.js` adds `formatText(raw)` — a small Markdown-style
  renderer (not real Google Sheets cell formatting, which isn't reachable through the plain
  `getValues()`/`setValues()` API this app uses). Supports `**bold**`, `*italic*`, blank-line
  paragraphs, `- ` bullet lists, and `1. ` numbered lists; escapes the input first via
  `escapeHtml()` so the Sheet cell just holds plain text with no injection risk. Used for
  Announcements/Recognition/Sponsors `Message`, and the `WelcomeMessage`/`AuditionInfoText`
  Settings values — not for `LyricsText` (which keeps its own simpler line-break-only
  rendering in `lyrics.html`) or short fields like Title/Date/Location. Documented for the
  director in `DIRECTOR-GUIDE.md`.
- **Instructions page**: new `instructions.html`, last item in the nav on every page, renders
  `Settings.InstructionsText` through `formatText` — same pattern as the Home welcome message,
  just a dedicated page. Added `InstructionsText` to `PUBLIC_SETTINGS_KEYS` in `Code.gs` since
  it's read by the public `settings` action. Ships with default placeholder content (a bulleted
  tour of the site's pages) in `sheet-templates/Settings.csv`; fully editable via the admin
  panel afterward.
- **Music Folders unwired**: removed entirely as an app feature per the user's request — deleted
  `folders.html`, the nav link on every page, `sheet-templates/MusicFolders.csv`, the `folders`
  public read action, and the `MusicFolders` entry in both `ADMIN_SHEETS` (`Code.gs`) and the
  admin panel's `TABLES` config. The `MusicFolders` tab itself is untouched in the Sheet — the
  app just no longer reads or exposes it anywhere.
- **Join Us reframed as an invite tool**: since browsing the app already implies membership (no
  login required), the "New Member Registration" card became **Invite a New Member** — a
  **Copy Link** button (using the shared `copyToClipboard` helper in `js/api.js`, also used by
  admin.html's Copy Numbers) that copies `NewMemberFormURL` to the clipboard, with instructions
  to paste it into a text or email. Falls back to showing the raw link as text if clipboard
  access fails, same pattern as Copy Numbers.
