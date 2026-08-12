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

// Renders lightweight, Markdown-style plain text as safe HTML: **bold**, *italic*, blank-line
// paragraphs, "- " bullet lists, and "1. " numbered lists. The Sheet cell itself just holds
// plain text with these symbols typed in — no real formatting is stored, only interpreted here.
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
