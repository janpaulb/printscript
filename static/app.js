/* PrintScript – frontend logic */

const dropZone      = document.getElementById('drop-zone');
const fileInput     = document.getElementById('file-input');
const fileDisplay   = document.getElementById('file-name-display');
const convertBtn    = document.getElementById('convert-btn');
const uploadCard    = document.getElementById('upload-card');
const progressCard  = document.getElementById('progress-card');
const statusText    = document.getElementById('status-text');
const errorCard     = document.getElementById('error-card');
const errorText     = document.getElementById('error-text');
const retryBtn      = document.getElementById('retry-btn');

let selectedFile = null;

// ── File selection ─────────────────────────────────────────────────────────

function selectFile(file) {
  if (!file) return;
  if (!file.name.toLowerCase().endsWith('.docx')) {
    showError('Alleen .docx bestanden zijn toegestaan.');
    return;
  }
  selectedFile = file;
  fileDisplay.textContent = file.name;
  convertBtn.disabled = false;
}

fileInput.addEventListener('change', () => selectFile(fileInput.files[0]));

// Click on the drop-zone (but not on the label/button inside it)
dropZone.addEventListener('click', (e) => {
  if (e.target.closest('label, button')) return;
  fileInput.click();
});

dropZone.addEventListener('keydown', (e) => {
  if (e.key === 'Enter' || e.key === ' ') {
    e.preventDefault();
    fileInput.click();
  }
});

// ── Drag & drop ────────────────────────────────────────────────────────────

['dragenter', 'dragover'].forEach(evt =>
  dropZone.addEventListener(evt, (e) => {
    e.preventDefault();
    dropZone.classList.add('drag-over');
  })
);

['dragleave', 'drop'].forEach(evt =>
  dropZone.addEventListener(evt, (e) => {
    e.preventDefault();
    dropZone.classList.remove('drag-over');
  })
);

dropZone.addEventListener('drop', (e) => {
  const file = e.dataTransfer.files[0];
  selectFile(file);
});

// ── Conversion ─────────────────────────────────────────────────────────────

convertBtn.addEventListener('click', () => {
  if (!selectedFile) return;
  startConversion();
});

function startConversion() {
  showProgress('Document verwerken\u2026');

  const formData = new FormData();
  formData.append('file', selectedFile);

  fetch('/convert', {
    method: 'POST',
    body: formData,
  })
    .then(async (response) => {
      if (!response.ok) {
        // Try to parse JSON error message
        const data = await response.json().catch(() => ({}));
        throw new Error(data.error || `Server fout: ${response.status}`);
      }

      // Stream the PDF blob and trigger download
      const blob = await response.blob();
      const contentDisposition = response.headers.get('Content-Disposition') || '';
      const match = contentDisposition.match(/filename[^;=\n]*=["']?([^"';\n]+)/);
      const filename = match ? match[1] : 'printscript.pdf';

      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      a.remove();
      URL.revokeObjectURL(url);

      // Return to upload view after short delay
      setTimeout(resetToUpload, 800);
    })
    .catch((err) => {
      showError(err.message || 'Er is een onbekende fout opgetreden.');
    });
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
  convertBtn.disabled = true;
}

retryBtn.addEventListener('click', resetToUpload);
