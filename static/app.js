/* PrintScript – frontend logic */

// ── Element refs ───────────────────────────────────────────────────────────
const uploadCard   = document.getElementById('upload-card');
const progressCard = document.getElementById('progress-card');
const errorCard    = document.getElementById('error-card');
const statusText   = document.getElementById('status-text');
const errorText    = document.getElementById('error-text');
const convertBtn   = document.getElementById('convert-btn');
const retryBtn     = document.getElementById('retry-btn');

// File tab
const dropZone    = document.getElementById('drop-zone');
const fileInput   = document.getElementById('file-input');
const fileDisplay = document.getElementById('file-name-display');

// URL tab
const gdocsInput  = document.getElementById('gdocs-url');

// Tabs
const tabs   = document.querySelectorAll('.tab');
const panels = document.querySelectorAll('.tab-panel');

// ── State ──────────────────────────────────────────────────────────────────
let activeTab    = 'file';  // 'file' | 'url'
let selectedFile = null;

// ── Tab switching ──────────────────────────────────────────────────────────
tabs.forEach(tab => {
  tab.addEventListener('click', () => {
    const panelId = tab.dataset.panel;
    activeTab = panelId === 'panel-file' ? 'file' : 'url';

    tabs.forEach(t => {
      t.classList.toggle('active', t === tab);
      t.setAttribute('aria-selected', t === tab ? 'true' : 'false');
    });
    panels.forEach(p => p.classList.toggle('hidden', p.id !== panelId));

    updateConvertBtn();
  });
});

// ── Convert button enable/disable ──────────────────────────────────────────
function updateConvertBtn() {
  if (activeTab === 'file') {
    convertBtn.disabled = !selectedFile;
  } else {
    convertBtn.disabled = gdocsInput.value.trim().length === 0;
  }
}

gdocsInput.addEventListener('input', updateConvertBtn);

// ── File selection ─────────────────────────────────────────────────────────
function selectFile(file) {
  if (!file) return;
  if (!file.name.toLowerCase().endsWith('.docx')) {
    showError('Alleen .docx bestanden zijn toegestaan.');
    return;
  }
  selectedFile = file;
  fileDisplay.textContent = file.name;
  updateConvertBtn();
}

fileInput.addEventListener('change', () => selectFile(fileInput.files[0]));

dropZone.addEventListener('click', (e) => {
  if (e.target.closest('label, button')) return;
  fileInput.click();
});

dropZone.addEventListener('keydown', (e) => {
  if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fileInput.click(); }
});

['dragenter', 'dragover'].forEach(evt =>
  dropZone.addEventListener(evt, (e) => { e.preventDefault(); dropZone.classList.add('drag-over'); })
);
['dragleave', 'drop'].forEach(evt =>
  dropZone.addEventListener(evt, (e) => { e.preventDefault(); dropZone.classList.remove('drag-over'); })
);
dropZone.addEventListener('drop', (e) => selectFile(e.dataTransfer.files[0]));

// ── Conversion ─────────────────────────────────────────────────────────────
convertBtn.addEventListener('click', () => {
  if (activeTab === 'file' && selectedFile) startFileConversion();
  else if (activeTab === 'url') startUrlConversion();
});

function startFileConversion() {
  showProgress('Document verwerken\u2026');
  const formData = new FormData();
  formData.append('file', selectedFile);

  fetch('/convert', { method: 'POST', body: formData })
    .then(handleConvertResponse)
    .catch(err => showError(err.message || 'Onbekende fout.'));
}

function startUrlConversion() {
  const docUrl = gdocsInput.value.trim();
  if (!docUrl) return;
  showProgress('Google Docs ophalen en verwerken\u2026');

  fetch('/convert-url', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ url: docUrl }),
  })
    .then(handleConvertResponse)
    .catch(err => showError(err.message || 'Onbekende fout.'));
}

async function handleConvertResponse(response) {
  if (!response.ok) {
    const data = await response.json().catch(() => ({}));
    throw new Error(data.error || `Server fout: ${response.status}`);
  }

  const blob = await response.blob();
  const cd = response.headers.get('Content-Disposition') || '';
  const match = cd.match(/filename[^;=\n]*=["']?([^"';\n]+)/);
  const filename = match ? match[1] : 'printscript.pdf';

  // Use a distinct name to avoid shadowing outer variables
  const blobUrl = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = blobUrl;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(blobUrl);

  setTimeout(resetToUpload, 800);
}

// ── State helpers ──────────────────────────────────────────────────────────
function showProgress(msg) {
  uploadCard.classList.add('hidden');
  errorCard.classList.add('hidden');
  progressCard.classList.remove('hidden');
  statusText.textContent = msg;
}

function showError(msg) {
  uploadCard.classList.add('hidden');
  progressCard.classList.add('hidden');
  errorCard.classList.remove('hidden');
  errorText.textContent = msg;
}

function resetToUpload() {
  progressCard.classList.add('hidden');
  errorCard.classList.add('hidden');
  uploadCard.classList.remove('hidden');
  selectedFile = null;
  fileInput.value = '';
  fileDisplay.textContent = 'Geen bestand geselecteerd';
  updateConvertBtn();
}

retryBtn.addEventListener('click', resetToUpload);

// ── LibreOffice update notifications ───────────────────────────────────────
const updateBanner = document.getElementById('update-banner');
const updateIcon   = document.getElementById('update-icon');
const updateMsg    = document.getElementById('update-msg');

let updatePoller = null;

function pollUpdateStatus() {
  fetch('/update-status')
    .then(r => r.json())
    .then(data => {
      switch (data.status) {
        case 'downloading': {
          const pct = data.percent != null ? ` (${data.percent}%)` : '';
          showUpdateBanner('downloading', '↻', `LibreOffice ${data.version} downloaden${pct}`);
          break;
        }
        case 'extracting': {
          showUpdateBanner('downloading', '↻', `LibreOffice ${data.version} installeren\u2026`);
          break;
        }
        case 'signing': {
          showUpdateBanner('downloading', '↻', `LibreOffice ${data.version} ondertekenen\u2026`);
          break;
        }
        case 'ready': {
          showUpdateBanner('ready', '✓',
            `LibreOffice ${data.version} klaar \u2014 herstart om bij te werken`);
          // Stop polling — status won't change until a restart
          stopUpdatePoller();
          break;
        }
        case 'up_to_date': {
          // Nothing to show; stop polling until next launch
          hideUpdateBanner();
          stopUpdatePoller();
          break;
        }
        default:
          // 'idle', 'checking', unknown — keep banner hidden, keep polling
          hideUpdateBanner();
      }
    })
    .catch(() => hideUpdateBanner());
}

function showUpdateBanner(cls, icon, msg) {
  updateBanner.className = `update-banner ${cls} visible`;
  updateIcon.textContent = icon;
  updateMsg.textContent  = msg;
}

function hideUpdateBanner() {
  updateBanner.classList.remove('visible');
}

function stopUpdatePoller() {
  if (updatePoller) {
    clearInterval(updatePoller);
    updatePoller = null;
  }
}

// Start polling — first call after 3 s to let the server settle, then every 5 s
setTimeout(() => {
  pollUpdateStatus();
  updatePoller = setInterval(pollUpdateStatus, 5000);
}, 3000);
