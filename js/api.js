// Fill this in after deploying api/api.php (see SETUP.md).
const CONFIG = {
  API_URL: "https://seniorfamily.org/choir-api/api.php",
};

function isConfigured() {
  return CONFIG.API_URL && !CONFIG.API_URL.includes("YOUR_");
}

// ---- Member login (My Apps Hub SSO token, or a manual fallback login) ----
// The whole app requires a login now -- every action in api.php except
// login/logout/whoAmI checks this token against the shared sessions table
// and an app_access grant. Stored in localStorage (not sessionStorage) since
// members should stay logged in across visits, same as the saved email below.

const MEMBER_TOKEN_KEY = 'choirMemberToken';
function getMemberToken() { return localStorage.getItem(MEMBER_TOKEN_KEY) || ''; }
function saveMemberToken(token) { localStorage.setItem(MEMBER_TOKEN_KEY, token); }
function clearMemberToken() { localStorage.removeItem(MEMBER_TOKEN_KEY); }

function memberAuthHeaders() {
  const token = getMemberToken();
  return token ? { Authorization: `Bearer ${token}` } : {};
}

// Reads one data domain, e.g. fetchData("schedule").
// Returns parsed JSON, or throws if the request fails -- throws an Error
// with message "not-authorized" specifically for a 401/403, so callers can
// tell "not logged in" apart from a plain network/server failure.
async function fetchData(action) {
  if (!isConfigured()) {
    throw new Error("not-configured");
  }
  const url = `${CONFIG.API_URL}?action=${encodeURIComponent(action)}`;
  const res = await fetch(url, { headers: memberAuthHeaders() });
  if (res.status === 401 || res.status === 403) {
    throw new Error("not-authorized");
  }
  if (!res.ok) {
    throw new Error(`Request failed: ${res.status}`);
  }
  return res.json();
}

// Sends a write action, e.g. postAction("claimSlot", {date, taskName, volunteerName}).
async function postAction(action, payload) {
  if (!isConfigured()) {
    throw new Error("not-configured");
  }
  const res = await fetch(CONFIG.API_URL, {
    method: "POST",
    headers: { "Content-Type": "text/plain", ...memberAuthHeaders() },
    body: JSON.stringify({ action, ...payload }),
  });
  if (res.status === 401 || res.status === 403) {
    throw new Error("not-authorized");
  }
  if (!res.ok) {
    throw new Error(`Request failed: ${res.status}`);
  }
  return res.json();
}

// Renders a friendly placeholder into a container when the API isn't configured yet
// or the request failed. Used by placeholder pages until each phase wires up real data.
function showPlaceholder(container, message) {
  container.innerHTML = `<p class="placeholder-note">${message}</p>`;
}

// Renders a login-required or access-denied card into `container`, in place
// of whatever the page was trying to load. "no-token" includes a manual
// login form (reusing the same `login` action admin.html's own form calls)
// for a bookmarked direct visit that skipped the Hub's SSO handoff -- most
// members never see this, since opening the app from My Apps Hub logs them
// in automatically. "denied" means the login itself is fine, but that
// account has no app_access grant for this app yet.
function renderMemberGate(container, reason) {
  if (reason === 'denied') {
    container.innerHTML = `
      <div class="card">
        <p class="placeholder-note">Your login isn't set up for this app yet. Ask your director to grant you access, or open the app again from My Apps Hub.</p>
      </div>
    `;
    return;
  }
  container.innerHTML = `
    <div class="card">
      <h3>Log In</h3>
      <p class="placeholder-note">Normally you won't see this — opening the app from My Apps Hub logs you in automatically. Use this only if you got here another way.</p>
      <label for="member-login-email">Email</label>
      <input type="email" id="member-login-email" autocomplete="username">
      <label for="member-login-pw">Password</label>
      <input type="password" id="member-login-pw" autocomplete="current-password">
      <p><button type="button" class="btn" id="member-login-btn">Log In</button></p>
      <div id="member-login-msg"></div>
    </div>
  `;
  const emailInput = document.getElementById('member-login-email');
  const pwInput = document.getElementById('member-login-pw');
  const msgEl = document.getElementById('member-login-msg');
  const loginBtn = document.getElementById('member-login-btn');

  async function attemptLogin() {
    const username = emailInput.value.trim();
    const password = pwInput.value;
    if (!username || !password) return;
    loginBtn.disabled = true;
    msgEl.innerHTML = '';
    try {
      const res = await fetch(CONFIG.API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'text/plain' },
        body: JSON.stringify({ action: 'login', username, password }),
      });
      const data = await res.json();
      if (!res.ok || !data.token) {
        msgEl.innerHTML = `<p class="placeholder-note">${escapeHtml(data.error || 'Incorrect email or password.')}</p>`;
        loginBtn.disabled = false;
        return;
      }
      saveMemberToken(data.token);
      window.location.reload();
    } catch (e) {
      msgEl.innerHTML = `<p class="placeholder-note">Something went wrong logging in. Please try again.</p>`;
      loginBtn.disabled = false;
    }
  }

  loginBtn.addEventListener('click', attemptLogin);
  pwInput.addEventListener('keydown', (ev) => { if (ev.key === 'Enter') attemptLogin(); });
}

// Pages call this from their fetch catch blocks instead of showPlaceholder
// directly, so a login/access problem shows the right message (and, for a
// missing login, a way to fix it) instead of a generic "couldn't load."
function handleFetchError(container, error) {
  if (error && error.message === 'not-authorized') {
    renderMemberGate(container, getMemberToken() ? 'denied' : 'no-token');
  } else {
    showPlaceholder(container, "Couldn't load right now — check your connection and try refreshing.");
  }
}

// Escapes text before dropping it into innerHTML, since sheet content is free-form text.
function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML;
}

// Renders lightweight, Markdown-style plain text as safe HTML: **bold**, *italic*,
// ![alt](url) images, blank-line paragraphs, "- " bullet lists, and "1. " numbered
// lists. The Sheet cell itself just holds plain text with these symbols typed in —
// no real formatting is stored, only interpreted here.
function formatText(raw) {
  const escaped = escapeHtml(raw ?? '');
  const blocks = escaped.split(/\n\s*\n/);
  return blocks
    .map(block => {
      const lines = block.split('\n').filter(line => line.trim() !== '');
      if (!lines.length) return '';
      if (lines.every(line => /^-\s+/.test(line))) {
        const items = lines.map(line => `<li>${inlineFormat(line.replace(/^-\s+/, ''))}</li>`).join('');
        return `<ul>${items}</ul>`;
      }
      if (lines.every(line => /^\d+\.\s+/.test(line))) {
        const items = lines.map(line => `<li>${inlineFormat(line.replace(/^\d+\.\s+/, ''))}</li>`).join('');
        return `<ol>${items}</ol>`;
      }
      return `<p>${lines.map(inlineFormat).join('<br>')}</p>`;
    })
    .join('');
}

function inlineFormat(text) {
  return text
    // Image syntax first, so a * inside alt text or a URL can't be mistaken for
    // bold/italic markers. `text` is already HTML-escaped by formatText, but that
    // escaping doesn't cover quotes (fine for text nodes, not for attribute
    // values), so quotes are escaped here specifically for the src/alt attributes.
    .replace(/!\[([^\]]*)\]\(([^)]+)\)/g, (match, alt, url) =>
      `<img src="${url.replace(/"/g, '&quot;')}" alt="${alt.replace(/"/g, '&quot;')}">`
    )
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/\*(.+?)\*/g, '<em>$1</em>');
}

// Copies `text` to the clipboard and briefly changes `button`'s label to confirm it. If the
// clipboard API is unavailable/blocked, calls `onFallback(text)` so the value isn't lost.
async function copyToClipboard(text, button, onFallback) {
  const original = button.textContent;
  try {
    await navigator.clipboard.writeText(text);
    button.textContent = 'Copied!';
  } catch (e) {
    if (onFallback) onFallback(text);
  }
  setTimeout(() => { button.textContent = original; }, 1500);
}

// ---- SOJO roster lookups (email -> name/phone/position/attendance, looked
// up against the same Google Sheet sojo-app maintains) ----

const MEMBER_EMAIL_KEY = 'choirMemberEmail';
function getSavedEmail() { return localStorage.getItem(MEMBER_EMAIL_KEY) || ''; }
function saveEmail(email) { localStorage.setItem(MEMBER_EMAIL_KEY, email); }
function clearSavedEmail() { localStorage.removeItem(MEMBER_EMAIL_KEY); }

// Exact wording requested for an email that isn't on the roster.
function notFoundMessage(email) {
  return `${email} not found in the SoJo Roster.`;
}

// { ok: true, member: {name, email, cellPhone, homePhone, position, section} }
// or { ok: false, reason: 'not-found' | 'invalid' }
async function lookupMember(email) {
  if (!isConfigured()) throw new Error('not-configured');
  const url = `${CONFIG.API_URL}?action=lookupMember&email=${encodeURIComponent(email)}`;
  const res = await fetch(url, { headers: memberAuthHeaders() });
  if (res.status === 401 || res.status === 403) throw new Error('not-authorized');
  if (!res.ok) throw new Error(`Request failed: ${res.status}`);
  return res.json();
}

// { ok: true, member: {...}, attendance: [{date, code, label, color}, ...] }
// (most recent first) or { ok: false, reason: ... } — same as lookupMember.
async function lookupMyAttendance(email) {
  if (!isConfigured()) throw new Error('not-configured');
  const url = `${CONFIG.API_URL}?action=myAttendance&email=${encodeURIComponent(email)}`;
  const res = await fetch(url, { headers: memberAuthHeaders() });
  if (res.status === 401 || res.status === 403) throw new Error('not-authorized');
  if (!res.ok) throw new Error(`Request failed: ${res.status}`);
  return res.json();
}

// { ok: true, leader: {name, email, phone} } — any of the three may be null
// (no section leader on file for that position, and no DirectorEmail set
// either, or a phone number simply isn't on file for whoever's found).
async function lookupSectionLeader(position) {
  if (!isConfigured()) throw new Error('not-configured');
  const url = `${CONFIG.API_URL}?action=sectionLeader&position=${encodeURIComponent(position)}`;
  const res = await fetch(url, { headers: memberAuthHeaders() });
  if (res.status === 401 || res.status === 403) throw new Error('not-authorized');
  if (!res.ok) throw new Error(`Request failed: ${res.status}`);
  return res.json();
}

// If arriving from My Apps Hub with ?token=... (SSO handoff), this is the
// member's login for the whole app now, not just a roster-lookup
// convenience: it's saved directly as this app's own Bearer token
// (saveMemberToken) since it's already a real row in the same shared
// sessions table requireMember() in api.php checks. It's also resolved to
// the member's email (saveEmail) the same way a typed-and-looked-up email
// would be, so the existing "auto-run the lookup if a saved email exists"
// logic on volunteer.html/absent.html/myinfo.html picks it up with no extra
// code there. Always strips the token out of the URL (whether or not it
// resolved), so it doesn't linger in the address bar/history.
//
// Every member-facing page now calls this at least once (the shared
// auto-bootstrap below calls it before renderNav(), and volunteer.html/
// absent.html/myinfo.html also call it themselves before checking
// getSavedEmail()) -- run-once caching via ssoCapturePromise makes that
// safe: whichever call happens first does the real work and every other
// call on the same page load just awaits that same promise, so a second
// caller is guaranteed the resolution has actually finished (not "there was
// no token left to find because an earlier call already stripped it").
let ssoCapturePromise = null;
function captureSsoEmail() {
  if (ssoCapturePromise) return ssoCapturePromise;
  ssoCapturePromise = (async () => {
    const token = new URLSearchParams(window.location.search).get('token');
    if (!token) return;
    window.history.replaceState({}, document.title, window.location.pathname);
    if (!isConfigured()) return;
    saveMemberToken(token);
    try {
      const res = await fetch(`${CONFIG.API_URL}?action=whoAmI&token=${encodeURIComponent(token)}`);
      const data = await res.json();
      if (data.ok && data.email) {
        saveEmail(data.email);
      }
    } catch (e) {
      // Best-effort -- a failed resolve just means no pre-fill, same as if
      // there had never been a token at all.
    }
  })();
  return ssoCapturePromise;
}

// Renders Call/Text/Email tap buttons for a phone/email pair, matching
// sojo-app's contact-btn pattern. Omits whichever action has no target
// (e.g. no buttons at all if both phone and email are blank).
function contactButtonsHtml(phone, email) {
  const cleanPhone = String(phone ?? '').trim();
  const cleanEmail = String(email ?? '').trim();
  if (!cleanPhone && !cleanEmail) return '';
  const parts = [];
  if (cleanPhone) {
    const tel = encodeURIComponent(cleanPhone);
    parts.push(`<a class="contact-btn contact-btn-call" href="tel:${tel}">Call</a>`);
    parts.push(`<a class="contact-btn contact-btn-text" href="sms:${tel}">Text</a>`);
  }
  if (cleanEmail) {
    parts.push(`<a class="contact-btn contact-btn-email" href="mailto:${encodeURIComponent(cleanEmail)}">Email</a>`);
  }
  return `<div class="contact-actions">${parts.join('')}</div>`;
}

// ---- Configurable nav (admin-editable order/visibility, see NavItems) ----

// Seed data mirrors sheet-templates' original nav — used only if the
// navItems fetch itself fails, so a broken API never leaves the app
// unnavigable.
const DEFAULT_NAV_ITEMS = [
  { Label: 'Home', PageFile: 'index.html' },
  { Label: 'Countdown', PageFile: 'countdown.html' },
  { Label: 'Announcements', PageFile: 'announcements.html' },
  { Label: 'Schedule', PageFile: 'schedule.html' },
  { Label: 'Volunteer', PageFile: 'volunteer.html' },
  { Label: 'Songs', PageFile: 'songs.html' },
  { Label: 'Documents', PageFile: 'documents.html' },
  { Label: 'Report Absence', PageFile: 'absent.html' },
  { Label: 'My Info', PageFile: 'myinfo.html' },
  { Label: 'Recognition', PageFile: 'recognition.html' },
  { Label: 'Sponsors & Giving', PageFile: 'sponsors.html' },
  { Label: 'Join Us', PageFile: 'join.html' },
  { Label: 'Instructions', PageFile: 'instructions.html' },
];

async function renderNav() {
  const nav = document.getElementById('site-nav');
  if (!nav) return;
  const currentPage = window.location.pathname.split('/').pop() || 'index.html';
  let items;
  try {
    items = await fetchData('navItems');
    if (!Array.isArray(items)) throw new Error('bad-response');
  } catch (e) {
    items = DEFAULT_NAV_ITEMS;
  }
  nav.innerHTML = items.map(item => {
    const isCurrent = item.PageFile === currentPage;
    return `<a href="${escapeHtml(item.PageFile)}"${isCurrent ? ' aria-current="page"' : ''}>${escapeHtml(item.Label)}</a>`;
  }).join('');
}

// Auto-bootstrap: any page with a #site-nav element (every member-facing
// page) captures a My Apps Hub SSO token first, if this load has one, then
// renders the nav -- no per-page wiring needed. Capturing here (not just in
// the handful of pages that already call captureSsoEmail() themselves for
// the roster lookup) means the token is saved before *any* page's own fetch
// runs, since every fetch now requires it. admin.html has no #site-nav, so
// it's untouched (it has its own separate login system).
if (document.getElementById('site-nav')) {
  (async () => {
    await captureSsoEmail();
    await renderNav();
  })();
}
