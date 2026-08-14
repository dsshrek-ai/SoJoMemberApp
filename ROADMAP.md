# Choir App Roadmap

**Architecture as of the MyDataWorld migration** (see "Post-roadmap additions" for the full
writeup): static HTML/CSS/JS (this repo) hosted on GitHub Pages, talking to `api/api.php` on
**MyDataWorld** (shared MySQL database with My Apps Hub, T-Minus, Shed Inventory, and PWI
Weight Tracker). No login for members — public actions (schedule, songs, announcements,
volunteer signup, absence reporting, etc.) are unauthenticated, same as before. Only the admin
panel (`admin.html`) requires logging in, via the shared MyDataWorld account.

The phase history below (0–6) describes the **original Google Sheets/Apps Script** version.
It's kept for historical context — the Sheet-specific details (tab names, `Code.gs`, CSV
templates) no longer reflect how the app actually works, but the *behavior* they describe
(what each page does, what the admin panel edits) carried over unchanged into the MySQL
version. See `api/schema.sql` for the current table layout and `SETUP.md` for deployment.

## Data tables (formerly Sheet tabs)

| Table | Columns |
|---|---|
| `Schedule` | Date, Time, Type, Title, Location, ParkingNotes, EntranceNotes, Notes |
| `Songs` | Title, RehearsalTrackURL, YouTubeURL, LastRehearsedDate, Status |
| `Announcements` | Date, Author, Message, Pinned |
| `VolunteerTasks` | Date, TaskName, SlotsNeeded |
| `VolunteerSignups` | Date, TaskName, VolunteerName, PhoneNumber, Timestamp |
| `Absences` | Date, MemberName, Position, Note, Timestamp |
| `Recognition` | Date, MemberName, Message |
| `Sponsors` | SponsorName, LogoURL, Message, Tier |
| `Settings` | Key, Value |
| `SectionLeaders` | Position, LeaderName, LeaderEmail |

`MusicFolders` was an app feature in an earlier phase — it was unwired entirely (no page, no
admin table, no API action) per the user's request, and doesn't exist in the MySQL schema at
all. `Lyrics` (SongTitle, Part, LyricsText) was removed the same way — no page, no admin
table entry, no API action — though `choir_lyrics` is left in place in MySQL, just unused.
`SectionLeaders` is new — see "Post-roadmap additions".

## api/api.php

Public (no auth, used by member-facing pages) — same contract as the old Apps Script version:
- `GET ?action=schedule|songs|lyrics|announcements|volunteerStatus|recognition|sponsors|settings`
- `POST {action: "claimSlot", date, taskName, volunteerName, phoneNumber}` — guarded by a MySQL
  named lock (`GET_LOCK`), same role `LockService` played before.
- `POST {action: "markAbsent", date, memberName, position, note}` — emails the `SectionLeaders`
  row for `position`, falling back to `Settings.DirectorEmail` if unset.

Admin (all POST, require `Authorization: Bearer <MyDataWorld session token>` +
`app_access` for `choir-admin-panel`, checked in `requireUser`/`requireChoirAdminAccess`):
- `login` / `logout` / `checkAccess`
- `adminList` → `{sheet}` → `{ok, rows}` (rows include a `_row` id — the table's MySQL `id` now,
  not a Sheet row number, but the same JSON key so the front end didn't need to change)
- `adminVolunteerTasks` → `{ok, rows}` — VolunteerTasks rows (with `_row`) plus each row's
  `PhoneNumbers` array, computed in one query (see "Post-roadmap additions" for why)
- `adminAdd` → `{sheet, row: {...}}`
- `adminUpdate` → `{sheet, row: <id>, values: {...}}`
- `adminDelete` → `{sheet, row: <id>}`

`sheet` must be one of the keys in `$SHEET_TABLES` in `api/api.php` (the 11 tables above).

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
- **Migrated off Google Sheets/Apps Script to MyDataWorld**: `apps-script/Code.gs` and
  `sheet-templates/` are gone; `api/api.php` on MyDataWorld (MySQL) replaces them entirely,
  mirroring the old JSON contract exactly for every public action (same PascalCase field names,
  same action names) so `js/api.js` only needed its endpoint URL changed — no public page
  (`schedule.html`, `songs.html`, etc.) required any edits. What did change:
  - **Admin login**: the shared `Settings.AdminPassword` is gone. `admin.html` now logs in with
    the same MyDataWorld account as My Apps Hub/T-Minus/Shed Inventory/PWI, and access is gated
    by an `app_access` grant for `choir-admin-panel` (managed from the Hub's own admin tool,
    exactly like any other private app there) — not a shared secret typed into a page. Supports
    the same `?token=` SSO handoff from the Hub as the other apps. Deliberately kept in
    `sessionStorage` rather than `localStorage` (unlike the other apps' `localStorage`-based
    auth) to preserve the old "clears on tab close" property, since this panel touches real
    member data (phone numbers, absence notes) and may run on a shared computer.
  - **Absence emails now route by section, not to one inbox**: `absent.html` gained a required
    Position dropdown (matching the SOJO roster app's position list, though the two apps don't
    share any data). A new `SectionLeaders` table (Position → LeaderName/LeaderEmail, editable
    from the admin panel) drives who gets emailed; `Settings.DirectorEmail` is now only a
    fallback for a Position with no leader on file, not the sole recipient. Email sends via
    PHP's `mail()` (was Google's `MailApp`) and is best-effort — a failed send never blocks the
    absence from being recorded.
  - **Row identity**: `_row` in admin API responses is now each table's MySQL auto-increment
    `id` rather than a literal Sheet row number — same JSON key, so `admin.html`'s existing
    save/delete-by-`_row` logic needed no changes.
  - Data migration (existing Sheet content) is a manual one-time CSV export/import per tab —
    see `SETUP.md`.
