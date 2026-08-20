# Director's Guide to Updating the Website

You don't need to know any code to keep the site up to date. Everything below is done through
the **Admin Panel**, a page on the site made just for this.

## Getting in

1. Go to `https://dsshrek-ai.github.io/SoJoMemberApp/admin.html` (bookmark this — it's not on
   the regular site menu, on purpose, so members don't stumble into it).
2. Log in with your email and password — the same account you use for My Apps Hub and any other
   apps there. If you don't have one yet, sign up through the Hub first, then ask to be granted
   access to the "Choir Admin Panel" app.
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
YouTube video, plus a Status of "Active" or "On Hold" from the dropdown. LastRehearsedDate is
optional — leave it blank for a brand-new song.

**Post an announcement**
→ Table: `Announcements`. Add a Date, your name as Author, and the Message. Set Pinned to
"Yes" if it should stay at the top of the list (e.g. something time-sensitive); otherwise
leave it "No" and it'll sort in by date automatically.

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

**Set up who gets emailed about absences (and shown as a member's section leader)**
→ Table: `SectionLeaders`. One row per Position (e.g. "Soprano - 1st") with that section's
leader's name, email, and phone (LeaderPhone). When someone reports an absence, whoever's listed
for their Position gets the email — not one shared inbox. The same row also powers the "call,
text, or email your section leader" card members see on the My Info page — leave LeaderPhone
blank if that leader would rather only be reached by email. A Position with no row (or a blank
email/phone) falls back to `Settings.DirectorEmail` for both the absence email and the My Info
contact card, showing "Director" instead of a leader's name.

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
- `DirectorEmail` — fallback email for absence reports when a Position has no one listed in
  `SectionLeaders` (see above)
- `Countdown` — drives the **Countdown** page (first item in the site menu). See below for the
  format.

To change any of these, find that row in the `Settings` table and edit the Value box, then
**Save**. Don't change the Key names — the site looks for them by that exact spelling.

**Set up the countdown to an upcoming event**
→ Table: `Settings`, row with Key `Countdown`. The Value must be exactly:

```
[Event][mm/dd/yy][HHMM]
```

- `Event` — whatever text you want shown, e.g. `SoJo Performance - Bingham`
- Date — two-digit month/day/year, e.g. `08/20/26`
- Time — 24-hour, no colon, always 4 digits, e.g. `1500` for 3:00 PM, `0800` for 8:00 AM

So a performance on August 20, 2026 at 3:00 PM would be:

```
[SoJo Performance - Bingham][08/20/26][1500]
```

The Countdown page shows that event name with a live ticking countdown (days/hours/minutes/
seconds) to that exact date and time. There's only ever **one** countdown — adding a new
`Countdown` row's Value replaces whatever was there before, it doesn't add a second one. If the
Value is missing or doesn't match that exact bracket format, the page just says no countdown has
been set up yet, rather than showing something broken.

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

## Managing who has admin access

There's no shared password anymore — access is per-person, through My Apps Hub's admin tool
(same place you'd manage access to any other app there). Grant or remove someone's "Choir Admin
Panel" access there; they need their own account (sign up through the Hub) but nothing else.

## The "My Info" page and email lookup

The Volunteer, Report Absence, and My Info pages all now start by asking for the member's email.
If it matches someone in the SOJO Directory roster (the Google Sheet the section-leader app
reads), their name/phone/section fill in automatically — they can still change any of it, this
is just a shortcut, not a lock. If the email doesn't match anyone, they'll see a message saying
so, but the form still works by hand, exactly like before this existed.

My Info specifically shows a member their own attendance history and section leader's contact
card (with Call/Text/Email buttons) once they've looked themselves up, plus a note pointing them
to their section leader if anything about their attendance or contact info needs fixing — nothing
for you to maintain here beyond keeping `SectionLeaders` current, above.

This lookup depends on the SOJO Directory app's Google Sheet staying reachable — if that sheet or
its underlying Apps Script ever moves, whoever manages `api/config.php` (`APPS_SCRIPT_URL`) needs
to update it here too, or the auto-fill/attendance features will stop working (the rest of the
site is unaffected either way).

## A few things to know

- Changes show up on the site right away — no waiting, no redeploying anything.
- If you ever add a brand-new Settings key by mistake, it just won't do anything (the site only
  looks for the specific keys listed above) — safe to delete it.
- The **Log Out** button on the admin page clears your login from that browser tab. It also
  clears automatically when you close the tab — useful on a shared computer.
- If something looks wrong on the live site right after a change, try refreshing the page —
  occasionally there's a short delay before it shows up.
