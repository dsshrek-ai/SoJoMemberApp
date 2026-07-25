# Director's Guide to Updating the Website

You don't need to know any code to keep the site up to date. Everything below is done through
the **Admin Panel**, a page on the site made just for this.

## Getting in

1. Go to `https://dsshrek-ai.github.io/SoJoMemberApp/admin.html` (bookmark this — it's not on
   the regular site menu, on purpose, so members don't stumble into it).
2. Enter the admin password and click **Unlock**. (If you don't know it, see "Changing the admin
   password" below — someone with access to the Google Sheet can look it up or change it.)
3. Use the **Table** dropdown to pick what you want to update.

Every table works the same way: each row has a **Save** button (after you edit any box in that
row) and a **Delete** button, and there's always a blank row at the bottom with an **Add**
button to create a new entry.

## Common tasks

**Add a rehearsal or performance to the schedule**
→ Table: `Schedule`. Fill in the bottom row — Date, Time, Type (Rehearsal/Performance/Event),
Title, Location, and if it applies, Parking and Entrance notes — then click **Add**.

**Add a song, or update its rehearsal track / YouTube link**
→ Table: `Songs`. Add takes a Title and, if you have them, links for the rehearsal track and
YouTube video, plus a Status like "Learning" or "Concert Ready".

**Add lyrics for a song**
→ Table: `Lyrics`. One row per song *and* voice part — so "Amazing Grace" needs up to four
rows (Soprano, Alto, Tenor, Bass), each with that part's lyrics in the LyricsText box. This is
what powers the "Lyrics by Part" page members use to look up just their part.

**Post an announcement**
→ Table: `Announcements`. Add a Date, your name as Author, and the Message. Set Pinned to `Y`
if it should stay at the top of the list (e.g. something time-sensitive); otherwise leave it
blank and it'll sort in by date automatically.

**Post a volunteer task (e.g. "set up chairs" for a rehearsal)**
→ Table: `VolunteerTasks`. Add the Date, a TaskName, and how many people you need
(SlotsNeeded). Members sign up for it themselves on the Volunteer page — you don't need to fill
in who's coming; that happens automatically in the `VolunteerSignups` table as people sign up
(including their phone number, so leave it blank when adding a task yourself).
You generally shouldn't need to edit `VolunteerSignups` yourself, except to fix a mistake or
remove a duplicate.

**Text everyone signed up for a task**
→ Table: `VolunteerTasks`. Each row has a **Copy Numbers** button showing how many phone numbers
are on file for that task. Tap it to copy the full list to your clipboard, then paste it into
the "To:" field of a new message in your phone's texting app. (Phones/carriers don't reliably
support pre-filling multiple recipients from a link, so copy-and-paste is the dependable way.)

**Recognize a member (birthday, milestone, shout-out)**
→ Table: `Recognition`. Add a Date, the MemberName, and a short Message.

**Add or update a sponsor**
→ Table: `Sponsors`. SponsorName, an optional link (LogoURL), a thank-you Message, and a Tier
if you use sponsor levels (e.g. Gold/Silver).

**Update the welcome message, donation link, registration link, or instructions**
→ Table: `Settings`. This is a simple list of Key/Value pairs that control text and links across
the site:
- `WelcomeMessage` — shown on the home page
- `DonationURL` — the "Support the Choir" button link
- `NewMemberFormURL` — the registration link members copy from the Join Us page to invite others
- `AuditionInfoText` / `AuditionFormURL` — text and link on the Join Us page
- `InstructionsText` — shown on the Instructions page (last item in the site menu)
- `DirectorEmail` — if set, you'll get an email whenever someone reports an absence
- `AdminPassword` — the password for this admin panel (see below)

To change any of these, find that row in the `Settings` table and edit the Value box, then
**Save**. Don't change the Key names — the site looks for them by that exact spelling.

**Invite someone to join the choir**
→ There's nothing to set up here beyond `NewMemberFormURL` above — on the Join Us page, any
member can tap **Copy Link** to copy that registration link to their clipboard and paste it into
a text or email to invite someone.

## Formatting your text

Message-style boxes (Announcements, Recognition, Sponsors, and the WelcomeMessage/
AuditionInfoText Settings) support a few simple symbols instead of a formatting toolbar:

- `**bold**` → **bold**
- `*italic*` → *italic*
- A blank line between lines starts a new paragraph
- Lines starting with `- ` become a bulleted list
- Lines starting with `1. `, `2. `, etc. become a numbered list

For example, typing:
```
Reminders for this week:

- Bring your **black folder**
- Arrive by *6:45 PM*
```
shows up as a paragraph followed by a bulleted list, with "black folder" bold and "6:45 PM"
italic. This only applies to those message-style boxes — things like Title, Date, or Location
just show up as plain text exactly as typed.

## Changing the admin password

Table: `Settings` → find the `AdminPassword` row → edit the Value → **Save**. Anyone using the
old password will be locked out immediately; share the new one with whoever else does
maintenance.

## A few things to know

- Changes show up on the site right away — no waiting, no redeploying anything.
- If you ever add a brand-new Settings key by mistake, it just won't do anything (the site only
  looks for the specific keys listed above) — safe to delete it.
- The **Lock** button on the admin page clears your password from that browser tab. Use it on a
  shared computer. It also clears automatically when you close the tab.
- If something looks wrong on the live site right after a change, try refreshing the page —
  occasionally there's a short delay before it shows up.
