// Fill this in after deploying api/api.php (see SETUP.md).
const CONFIG = {
  API_URL: "https://seniorfamily.org/choir-api/api.php",
};

function isConfigured() {
  return CONFIG.API_URL && !CONFIG.API_URL.includes("YOUR_");
}

// Reads one data domain, e.g. fetchData("schedule").
// Returns parsed JSON, or throws if the request fails.
async function fetchData(action) {
  if (!isConfigured()) {
    throw new Error("not-configured");
  }
  const url = `${CONFIG.API_URL}?action=${encodeURIComponent(action)}`;
  const res = await fetch(url);
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
    headers: { "Content-Type": "text/plain" },
    body: JSON.stringify({ action, ...payload }),
  });
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
  const res = await fetch(url);
  if (!res.ok) throw new Error(`Request failed: ${res.status}`);
  return res.json();
}

// { ok: true, member: {...}, attendance: [{date, code, label, color}, ...] }
// (most recent first) or { ok: false, reason: ... } — same as lookupMember.
async function lookupMyAttendance(email) {
  if (!isConfigured()) throw new Error('not-configured');
  const url = `${CONFIG.API_URL}?action=myAttendance&email=${encodeURIComponent(email)}`;
  const res = await fetch(url);
  if (!res.ok) throw new Error(`Request failed: ${res.status}`);
  return res.json();
}

// { ok: true, leader: {name, email, phone} } — any of the three may be null
// (no section leader on file for that position, and no DirectorEmail set
// either, or a phone number simply isn't on file for whoever's found).
async function lookupSectionLeader(position) {
  if (!isConfigured()) throw new Error('not-configured');
  const url = `${CONFIG.API_URL}?action=sectionLeader&position=${encodeURIComponent(position)}`;
  const res = await fetch(url);
  if (!res.ok) throw new Error(`Request failed: ${res.status}`);
  return res.json();
}

// If arriving from My Apps Hub with ?token=... (SSO handoff), resolves it to
// this member's MyDataWorld login email and remembers it the same way a
// typed-and-looked-up email would be (saveEmail) -- so the existing "auto-run
// the lookup if a saved email exists" logic on volunteer.html/absent.html/
// myinfo.html picks it up with no extra code there. Always strips the token
// out of the URL (whether or not it resolved), so it doesn't linger in the
// address bar/history. Call and `await` this BEFORE checking getSavedEmail()
// so a resolved token wins over whatever was previously saved.
async function captureSsoEmail() {
  const token = new URLSearchParams(window.location.search).get('token');
  if (!token) return;
  window.history.replaceState({}, document.title, window.location.pathname);
  if (!isConfigured()) return;
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
