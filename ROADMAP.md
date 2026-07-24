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
| `VolunteerSignups` | Date, TaskName, VolunteerName, Timestamp |
| `Absences` | Date, MemberName, Note, Timestamp |
| `Recognition` | Date, MemberName, Message |
| `MusicFolders` | MemberName, FolderAssignment |
| `Sponsors` | SponsorName, LogoURL, Message, Tier |
| `Settings` | Key, Value |

Starter CSVs for each tab are in `sheet-templates/`.

## Apps Script API

- `GET ?action=schedule|songs|lyrics|announcements|volunteerStatus|recognition|folders|sponsors|settings`
- `POST {action: "claimSlot", date, taskName, volunteerName}` — implemented, guarded by `LockService`
- `POST {action: "markAbsent", date, memberName, note}` — stubbed, returns not-implemented until Phase 4

## Phases

- **Phase 0 (done)** — Repo scaffold, shared CSS/JS, Sheet schema + CSV templates, Apps Script
  with all `GET` read actions and `claimSlot` wired, nav shell across all pages, deploy instructions.
- **Phase 1** — Wire up the remaining read-only pages against a real deployed Sheet + Apps Script
  URL: Schedule, Announcements, Songs, Sponsors/Donation, Recognition, Music Folders, Join Us.
  (The page code already calls `fetchData(...)` — this phase is mostly testing against real data
  and refining the CSS/layout once real content is in.)
- **Phase 2** — Lyrics-by-part quick view: add song + part `<select>` dropdowns to `lyrics.html`
  that filter the already-fetched `Lyrics` rows and render large-text lyrics only.
- **Phase 3** — Wire the volunteer signup button in `volunteer.html` to `postAction("claimSlot", ...)`
  (currently disabled with "coming in Phase 3"), prompt for a name, refresh slot counts after signup,
  and disable/hide full slots.
- **Phase 4** — Absence notifications: a small form (date + name + note) on a new `absent.html` page
  posting `markAbsent`; implement the real logic in `Code.gs` (currently stubbed); optionally email the
  director via `MailApp.sendEmail` when one comes in.
- **Phase 5** — Polish: accessibility/large-text audit, mobile pass across all pages, and a short
  director-facing doc for editing the Sheet/Settings without touching code.

Each phase is meant to be its own session — this file plus `SETUP.md` should be enough context
to pick up work without replaying prior conversations.
