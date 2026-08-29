# Setup Guide

This app is backed by **MyDataWorld**, the same shared database as My Apps Hub, T-Minus, Shed
Inventory, and PWI Weight Tracker. Every page — member-facing and the **admin panel**
(`admin.html`) — now requires a MyDataWorld login with an `app_access` grant for
`south-jordan-choral-arts` (member pages) or `choir-admin-panel` (admin). See "SSO lock-down"
below for how that login actually reaches a member's browser and what the API enforces.

Separately: volunteer.html, absent.html, and myinfo.html also ask for your email so they can look
you up by name/phone/position/attendance against the SOJO Directory roster (the Google Sheet
behind the sojo-app repo) — not a second login, just an identity lookup, and every field it fills
in stays editable. See "7. Point the API at the SOJO roster" below.

## 1. Update the database

Open **phpMyAdmin**, select the MyDataWorld database, go to the **SQL** tab, and run everything
in [`api/schema.sql`](api/schema.sql). It's safe to run even if `users`/`sessions` already exist
(from My Apps Hub or another app) — those use `CREATE TABLE IF NOT EXISTS`. This also requires
My Apps Hub's own `api/schema.sql` to have already been run at least once (this app reads the
shared `apps`/`app_access` tables, including the `can_edit` column, that Hub's schema creates).

## 2. Deploy the API

1. Copy `api/config.example.php` to `api/config.php` and fill in the real `DB_NAME`, `DB_USER`,
   `DB_PASS` (same credentials as your other MyDataWorld apps) and `FROM_EMAIL`.
2. Upload the whole `api/` folder via FTP/File Manager — e.g. `seniorfamily.org/choir-api/`.

## 3. Point the site at your API

In `js/api.js`:

```js
const CONFIG = {
  API_URL: "https://seniorfamily.org/choir-api/api.php",
};
```

Every page (public and admin) reads this one constant — nothing else needs to change per page.

## 4. Publish

Push this repo (GitHub Pages) or upload the files directly to seniorfamily.org — same as before.

## 5. Grant yourself admin access

1. Sign up through **My Apps Hub** with your email, if you haven't already.
2. Either uncomment and run the bootstrap query at the bottom of `api/schema.sql` (fill in your
   email), or open the Hub's `admin.html` and grant yourself the "Choir Admin Panel" app — same
   two-step process as any other private app in the Hub.
3. Repeat for anyone else who does maintenance (section leaders, co-directors, etc.) — have them
   sign up through the Hub first, then grant them "Choir Admin Panel" access the same way.

There's no more `AdminPassword` — anyone with an `app_access` grant for `choir-admin-panel` can
get in, and anyone without one can't, regardless of what they know.

## 6. Section leaders (for absence-report emails, and the My Info contact card)

When a member reports an absence, the app emails whoever's listed for their Position — not a
single director inbox. The same table also drives the "tap to call/text/email your section
leader" card on `myinfo.html`. Set this up once you're in the admin panel:

1. Open `admin.html`, pick the **SectionLeaders** table.
2. Add one row per Position (Soprano - 1st, Soprano - 2nd, Alto - 1st, Alto - 2nd, Tenor - 1st,
   Tenor - 2nd, Bass - 1st, Bass - 2nd, HOLD) with that section's leader's name, email, **and
   phone** (LeaderPhone — new column, used for the Call/Text buttons; leave it blank if you'd
   rather that leader only be reachable by email).
3. If a Position has no row (or an empty LeaderEmail/LeaderPhone), the absence-notification email
   falls back to `Settings.DirectorEmail` if you've set one, and the My Info contact card does the
   same (showing "Director" with an Email button, no Call/Text since there's no director-phone
   setting); if neither exists, the absence is still recorded and the contact card just doesn't
   appear.

Position values match the SOJO roster app's list, but `choir_section_leaders` itself doesn't share
data with that app — it's its own small table, maintained here. (The roster *lookup* in step 7
below is the one place this app does read sojo-app's data, live.)

## 7. Point the API at the SOJO roster

`volunteer.html`, `absent.html`, and `myinfo.html` look a member up by email against the same
Google Sheet sojo-app reads, via that Sheet's Apps Script Web App — not a copy, the live sheet.

1. Open sojo-app's own `api/config.php` and copy its `APPS_SCRIPT_URL` value.
2. Paste that same URL into this app's `api/config.php` as `APPS_SCRIPT_URL`.
3. That's it — no `ADMIN_PIN` needed here, since this app only ever reads (`getSingers`/
   `getConfig`), never writes to the sheet. If you ever redeploy the Apps Script Web App and its
   URL changes, update it in both apps' `config.php` files.

If this isn't set (or the Apps Script is unreachable), the email-lookup step in each page fails
gracefully — members can still fill in Name/Phone/Position by hand exactly like before this
feature existed, they just don't get auto-filled or see the "not found in the SoJo Roster"
message.

## Data migration (Google Sheets → MyDataWorld)

If you're moving existing content out of the old Sheet, for each tab you want to keep:

1. Export it as CSV (**File → Download → Comma-separated values**) from the Google Sheet.
2. Import that CSV into a staging table in phpMyAdmin (**Import** tab), then run an
   `INSERT ... SELECT` mapping its columns into the matching `choir_*` table (see
   `api/schema.sql` for the column names). Small tabs (Settings, Sponsors, VolunteerTasks) are
   usually easiest to just hand-enter directly through the admin panel instead.
3. `Absences` gained a `Position` column that the old Sheet never had — existing rows can be left
   blank there; only new reports (via the updated form) fill it in.
4. Drop the `AdminPassword` row entirely — it's no longer read anywhere. Keep `DirectorEmail` if
   you want it as an absence-email fallback (see "Section leaders" above).
5. Once verified, the old Apps Script deployment and Google Sheet can be retired.

## Single sign-on

If `apps.sso_enabled = 1` is set for `choir-admin-panel` in My Apps Hub's database, launching
the admin panel from the Hub skips its login screen entirely (`?token=...` handoff). The login
is session-scoped (`sessionStorage`, not `localStorage`) even with SSO, since this panel edits
real member data (phone numbers, absence notes) and may run on a shared computer — it clears
itself when the browser tab closes.

## Nav links (Home, Schedule, Songs, etc.) and the Documents page — run this first

Both features need their tables created — **neither had actually been created yet**, confirmed via
`SHOW TABLES LIKE 'choir%'` in phpMyAdmin, which only showed the original 11 tables from before
these features existed. Until this runs, the nav has been silently working off a hardcoded fallback
list (`DEFAULT_NAV_ITEMS` in `js/api.js`) instead of the real admin-editable one, and adding a
Documents row fails outright. Run the `CREATE TABLE`/`INSERT` statements for `choir_nav_items` and
`choir_documents` from the "configurable nav + Documents page" section of `api/schema.sql` in
phpMyAdmin — safe to re-run later (`CREATE TABLE IF NOT EXISTS`), except the `choir_nav_items` seed
`INSERT`, which would duplicate rows on a second run.

Once that's run: the top nav on every page is admin-editable — pick **NavItems** in `admin.html` to
add/reorder (`SortOrder`, lower shows first)/hide (`Visible`) any link, instead of editing HTML in
12 files. `Label` is the link text, `PageFile` is the target page (e.g. `schedule.html`).

`documents.html` lists links to PDFs/downloads (not hosted files — paste a link to wherever the
file already lives, e.g. Google Drive). Manage entries via **Documents** in `admin.html`: `Title`
is the link text, `Url` the link, `Category` optionally groups related documents together (e.g.
"Sheet Music", "Forms"), `SortOrder` controls order within a category.

## Song parts + director notes (song.html)

Each song in the Song Library can now link to its own page listing part tracks (Soprano, Alto,
Tenor, Bass, etc. — play or download) and director notes PDFs. Unlike every other URL field in
this app, these files are real uploads, not pasted links:

1. Run the "per-song part tracks + director notes" section of `api/schema.sql` in phpMyAdmin —
   adds `choir_songs.folder_slug` and creates `choir_song_files`.
2. Add `SONG_FILES_BASE_URL` to `api/config.php` (see `config.example.php`) — a public URL on
   your own host, e.g. `https://seniorfamily.org/choir-song-files`. Create that folder via
   FTP/File Manager if it doesn't exist yet.
3. For each song, pick a folder name and set it as that song's `FolderSlug` in `admin.html`'s
   **Songs** table, then FTP a folder with that exact name into `SONG_FILES_BASE_URL`, containing
   that song's audio files and director-notes PDF.
4. In `admin.html`'s **SongFiles** table, add one row per file: `SongID` (the song's `Id` — visible
   in the songs API response, or count rows in the Songs table), `FileType` (`Track` or
   `Document`), `PartLabel` (e.g. "Soprano", "Director Notes"), `FileName` (must exactly match the
   uploaded file's name), `SortOrder`.

`song.html?id={SongID}` builds each file's real URL as `SONG_FILES_BASE_URL/{FolderSlug}/{FileName}`
at read time — nothing is stored as a full URL, so renaming `SONG_FILES_BASE_URL` or a song's
`FolderSlug` only requires updating that one field, not every row.

5. `song.html` itself is served from GitHub Pages, a different origin than the files on
   `seniorfamily.org` — so the Download button has to fetch the file via JavaScript and save it
   from there (plain cross-origin `<a download>` links are ignored by browsers). That needs the
   file host to allow cross-origin reads: create/upload an `.htaccess` file directly inside the
   `SONG_FILES_BASE_URL` folder (e.g. `choir-song-files/.htaccess`) containing:
   ```
   <IfModule mod_headers.c>
     Header set Access-Control-Allow-Origin "*"
   </IfModule>
   ```
   Until this is in place, Download falls back to just opening the file in a new tab (same as
   before this fix).

## SSO lock-down (every member page now requires login)

Every action in `api/api.php` except `login`/`logout`/`whoAmI` now calls `requireMember()` —
same shape as the admin panel's own `requireUser()` + `requireChoirAdminAccess()`, checking a
Bearer token against `sessions`, then an `app_access` grant for `south-jordan-choral-arts` on
that user. There's no separate signup here: it's the same MyDataWorld login every other app uses.

**How the login actually reaches a member's browser:** opening this app from My Apps Hub passes
`?token=...` in the URL — that's a real row in the shared `sessions` table already, the same one
`requireMember()` checks, so `js/api.js`'s shared bootstrap just saves it directly as this app's
own Bearer token (`captureSsoEmail()` → `saveMemberToken()`) instead of exchanging it for
anything new. No token in the URL and nothing saved from a previous visit shows a login-required
card in place of the page's real content (`renderMemberGate()` in `js/api.js`), with a manual
email/password login form as a fallback — for a bookmarked direct link that skipped the Hub, or a
first visit before anyone's ever launched it from there. That fallback reuses the existing
`login` action, so no separate account or password exists for this fallback path.

**One-time setup**: grant `south-jordan-choral-arts` `app_access` to every real member via My Apps
Hub's `admin.html` (Admin Tool → their email → **Access**) before rolling this out — anyone
without that grant is locked out immediately, including via the manual login form (a correct
password alone isn't enough, `login` doesn't check `app_access` but every actual data action does).

**Real behavior change worth announcing**: before this, the raw GitHub Pages link worked for
anyone, no login at all. After this, a member who bookmarked that link directly (instead of always
launching through the Hub) hits the login-required card the next time their saved token expires —
worth a heads-up via Announcements so it doesn't look broken.

## Usage logging

Every successful `requireMember()` check (i.e. every real member-facing action) also upserts a row
into `app_usage_log` — one row per user per calendar day, with a running `hit_count` and
first/last-seen times for that day. This table lives in **My Apps Hub's** `schema.sql`, not this
app's own — it's shared across every MyDataWorld app by design (`app_key` says which app logged the
row), so run that schema change there before this logging does anything. Until it's run, logging
fails silently (caught, not surfaced) and the app behaves exactly as before — nothing breaks.

Usage by day for this app:
```sql
SELECT access_date, COUNT(DISTINCT user_id) AS active_members
FROM app_usage_log
WHERE app_key = 'south-jordan-choral-arts'
GROUP BY access_date
ORDER BY access_date;
```

## A note on multi-choir support

An attempt to let one deployment serve several choirs (see `ROADMAP.md`) was rolled back before
going live — too much complexity for what was needed. It was initially assumed the database
migration for it had already run, so those tables were left alone rather than dropped — but
`SHOW TABLES LIKE 'choir%'` (above) confirmed `choirs`/`choir_access` were never actually created
either. `api/schema.sql` has been corrected to remove that dead SQL entirely — there's nothing left
to clean up, and no `choir_id` column anywhere in this schema.
`choir_documents` is the one exception — see "Documents page" above.
