// Fill this in after deploying the Apps Script Web App (see SETUP.md).
const CONFIG = {
  APPS_SCRIPT_URL: "https://script.google.com/macros/s/AKfycbxtElUsUs-a1p0QofWdq-cguOVqjypY8IwiB6UUBqmvFl49lM7G9dL0ugiiinEaHA/exec",
};

function isConfigured() {
  return (
    CONFIG.APPS_SCRIPT_URL &&
    CONFIG.APPS_SCRIPT_URL.startsWith("https://script.google.com/")
  );
}

// Reads one data domain, e.g. fetchData("schedule").
// Returns parsed JSON, or throws if the request fails.
async function fetchData(action) {
  if (!isConfigured()) {
    throw new Error("not-configured");
  }
  const url = `${CONFIG.APPS_SCRIPT_URL}?action=${encodeURIComponent(action)}`;
  const res = await fetch(url);
  if (!res.ok) {
    throw new Error(`Request failed: ${res.status}`);
  }
  return res.json();
}

// Sends a write action, e.g. postAction("claimSlot", {date, taskName, volunteerName}).
// Body is sent as text/plain to avoid a CORS preflight, which Apps Script Web Apps don't handle.
async function postAction(action, payload) {
  if (!isConfigured()) {
    throw new Error("not-configured");
  }
  const res = await fetch(CONFIG.APPS_SCRIPT_URL, {
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
