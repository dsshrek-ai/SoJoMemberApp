# Setup Guide

This app is backed by **MyDataWorld**, the same shared database as My Apps Hub, T-Minus, Shed
Inventory, and PWI Weight Tracker. Public pages (schedule, songs, lyrics, announcements,
volunteer signup, absence reporting, etc.) stay exactly as open as they've always been — no
login. Only the **admin panel** (`admin.html`) requires logging in now, using the same account
as every other MyDataWorld app.

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

## 6. Section leaders (for absence-report emails)

When a member reports an absence, the app emails whoever's listed for their Position — not a
single director inbox. Set this up once you're in the admin panel:

1. Open `admin.html`, pick the **SectionLeaders** table.
2. Add one row per Position (Soprano - 1st, Soprano - 2nd, Alto - 1st, Alto - 2nd, Tenor - 1st,
   Tenor - 2nd, Bass - 1st, Bass - 2nd, HOLD) with that section's leader's name and email.
3. If a Position has no row (or an empty LeaderEmail), the email falls back to
   `Settings.DirectorEmail` if you've set one; if neither exists, the absence is still recorded,
   it just doesn't email anyone.

Position values match the SOJO roster app's list, but the two apps don't share data — this is
its own small table, maintained here.

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
