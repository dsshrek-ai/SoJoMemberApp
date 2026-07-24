# Setup Guide

These steps use your own Google and GitHub accounts — nobody else needs to do this part.

## 1. Create the Google Sheet

1. Go to [sheets.google.com](https://sheets.google.com) and create a new blank spreadsheet.
   Name it something like "Choir App Data".
2. For each file in `sheet-templates/`, create a matching tab (right-click the tab bar → "Insert
   sheet") named exactly after the file (e.g. `Schedule`, `Songs`, `Lyrics`, …), then use
   **File → Import → Upload** and choose "Insert new sheet" replaced with "Replace current sheet"
   for that tab, so the header row and sample data land correctly. Delete the sample row(s) once
   you're comfortable, or leave them as a formatting reference.
3. Tab names must match exactly (case-sensitive): `Schedule`, `Songs`, `Lyrics`, `Announcements`,
   `VolunteerTasks`, `VolunteerSignups`, `Absences`, `Recognition`, `MusicFolders`, `Sponsors`,
   `Settings`.

## 2. Create and deploy the Apps Script Web App

1. In the Sheet, go to **Extensions → Apps Script**. This opens a script bound to your Sheet —
   that binding is what lets `SpreadsheetApp.getActiveSpreadsheet()` find it automatically.
2. Delete the default `Code.gs` contents and paste in the contents of this repo's
   `apps-script/Code.gs`.
3. Click **Deploy → New deployment**.
   - Type: **Web app**
   - Execute as: **Me**
   - Who has access: **Anyone**
4. Click **Deploy**, authorize the script when prompted (it needs access to your own Sheet),
   and copy the resulting web app URL (ends in `/exec`).
5. Keep this URL — you'll paste it into the site config next.

> Re-deploying: whenever you edit `Code.gs`, choose **Deploy → Manage deployments → Edit → New
> version** so the live URL picks up your changes.

## 3. Point the site at your deployment

1. Open `js/api.js` in this repo.
2. Replace `PASTE_YOUR_APPS_SCRIPT_WEB_APP_URL_HERE` with the URL from step 2.4.

## 4. Put it on GitHub Pages

1. Create a new GitHub repository (public or private both work with GitHub Pages, though a
   private repo needs GitHub Pro/Team/Enterprise for Pages).
2. Push this `choir-app/` folder's contents to the repo (ask your assistant to prepare the
   commit and confirm before it pushes, since pushing to GitHub is a shared/external action).
3. In the repo's **Settings → Pages**, set the source to the branch you pushed (e.g. `main`) and
   root folder, then save. GitHub will give you a URL like
   `https://<username>.github.io/<repo-name>/`.
4. Visit that URL — you should see the Home page with your welcome message (once `Settings` has
   a `WelcomeMessage` row) and working navigation across all pages.

## Ongoing content updates (no code changes needed)

- Schedule, songs, lyrics, announcements, recognition, sponsors, folder assignments, and all the
  `Settings` links/text (welcome message, donation link, registration/audition form links) are all
  edited directly in the Google Sheet. Changes show up on the site the next time a page loads —
  no redeploy needed.
- Volunteer tasks: add a row to `VolunteerTasks` for each week/task with how many slots are
  needed; the app fills in `VolunteerSignups` automatically as members claim slots (Phase 3).
