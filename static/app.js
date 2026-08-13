/* PrintScript front-end: pick a source, post it, show the PDF. */
(function () {
  'use strict';

  var el = function (id) { return document.getElementById(id); };

  var panels = {
    input: el('input-panel'),
    busy: el('busy-panel'),
    error: el('error-panel'),
    result: el('result-panel')
  };

  var urlInput = el('doc-url');
  var fileInput = el('file-input');
  var dropzone = el('dropzone');
  var convertButton = el('convert');
  var preview = el('preview');

  var mode = 'url';
  var chosenFile = null;
  var objectUrl = null;
  var previewReady = false;
  var printWhenReady = false;

  preview.addEventListener('load', function () {
    if (!objectUrl) { return; }
    previewReady = true;
    if (printWhenReady) {
      printWhenReady = false;
      printNow();
    }
  });

  /* ── Panel switching ─────────────────────────────────────────────── */

  function show(name) {
    Object.keys(panels).forEach(function (key) {
      panels[key].classList.toggle('is-hidden', key !== name);
    });
  }

  Array.prototype.forEach.call(document.querySelectorAll('.tab'), function (tab) {
    tab.addEventListener('click', function () {
      Array.prototype.forEach.call(document.querySelectorAll('.tab'), function (other) {
        var active = other === tab;
        other.classList.toggle('is-active', active);
        other.setAttribute('aria-selected', String(active));
      });
      mode = tab.dataset.target === 'pane-file' ? 'file' : 'url';
      el('pane-url').classList.toggle('is-hidden', mode !== 'url');
      el('pane-file').classList.toggle('is-hidden', mode !== 'file');
      refresh();
    });
  });

  /* ── Enabling the button ─────────────────────────────────────────── */

  function refresh() {
    convertButton.disabled = mode === 'url'
      ? urlInput.value.trim().length === 0
      : chosenFile === null;
  }

  urlInput.addEventListener('input', refresh);
  urlInput.addEventListener('keydown', function (event) {
    if (event.key === 'Enter' && !convertButton.disabled) { convert(); }
  });

  /* ── File picking ────────────────────────────────────────────────── */

  function pick(file) {
    if (!file) { return; }
    if (!/\.docx$/i.test(file.name)) {
      fail('Alleen .docx-bestanden kunnen worden omgezet.');
      return;
    }
    chosenFile = file;
    el('file-name').textContent = file.name;
    refresh();
  }

  dropzone.addEventListener('click', function () { fileInput.click(); });
  dropzone.addEventListener('keydown', function (event) {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      fileInput.click();
    }
  });
  fileInput.addEventListener('change', function () { pick(fileInput.files[0]); });

  ['dragenter', 'dragover'].forEach(function (name) {
    dropzone.addEventListener(name, function (event) {
      event.preventDefault();
      dropzone.classList.add('is-over');
    });
  });
  ['dragleave', 'drop'].forEach(function (name) {
    dropzone.addEventListener(name, function (event) {
      event.preventDefault();
      dropzone.classList.remove('is-over');
    });
  });
  dropzone.addEventListener('drop', function (event) {
    pick(event.dataTransfer.files[0]);
  });

  /* ── Conversion ──────────────────────────────────────────────────── */

  function options() {
    return {
      images_first_page_only: el('opt-images').checked,
      add_page_numbers: el('opt-numbers').checked,
      page_numbers_on_first_page: !el('opt-first').checked
    };
  }

  /* Print straight from the preview frame: the PDF is already loaded there,
     so the print dialog opens without downloading or opening a tab first. */
  function printNow() {
    if (!objectUrl) { return; }
    if (!previewReady) {
      printWhenReady = true;
      return;
    }
    try {
      preview.contentWindow.focus();
      preview.contentWindow.print();
    } catch (error) {
      // A browser that refuses to script its built-in PDF viewer still gets
      // there via a normal tab.
      window.open(objectUrl, '_blank', 'noopener');
    }
  }

  function fail(message) {
    el('error-text').textContent = message;
    show('error');
  }

  function decodeSummary(response) {
    var raw = response.headers.get('X-PrintScript-Summary');
    if (!raw) { return null; }
    try {
      var json = decodeURIComponent(escape(window.atob(raw)));
      return JSON.parse(json);
    } catch (error) {
      return null;
    }
  }

  function filenameOf(response, summary) {
    if (summary && summary.filename) { return summary.filename; }
    var disposition = response.headers.get('Content-Disposition') || '';
    var match = /filename\*=UTF-8''([^;]+)/.exec(disposition);
    if (match) { return decodeURIComponent(match[1]); }
    return 'printscript.pdf';
  }

  function convert() {
    show('busy');
    el('busy-text').textContent = mode === 'url'
      ? 'Document ophalen bij Google…'
      : 'Document inlezen…';

    var request;
    if (mode === 'url') {
      request = fetch('/api/convert', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ url: urlInput.value.trim(), options: options() })
      });
    } else {
      var form = new FormData();
      form.append('file', chosenFile);
      form.append('options', JSON.stringify(options()));
      request = fetch('/api/convert', { method: 'POST', body: form });
    }

    window.setTimeout(function () {
      if (!panels.busy.classList.contains('is-hidden')) {
        el('busy-text').textContent = 'Opmaken en pagina’s tellen…';
      }
    }, 1200);

    request.then(function (response) {
      var type = response.headers.get('Content-Type') || '';
      if (!response.ok || type.indexOf('application/pdf') === -1) {
        return response.json()
          .catch(function () { return { error: 'Onverwachte fout (HTTP ' + response.status + ').' }; })
          .then(function (payload) { throw new Error(payload.error || 'Conversie mislukt.'); });
      }
      var summary = decodeSummary(response);
      return response.blob().then(function (blob) {
        succeed(blob, filenameOf(response, summary), summary);
      });
    }).catch(function (error) {
      fail(error.message || 'De server is niet bereikbaar.');
    });
  }

  function succeed(blob, filename, summary) {
    if (objectUrl) { URL.revokeObjectURL(objectUrl); }
    objectUrl = URL.createObjectURL(blob);
    previewReady = false;
    printWhenReady = el('opt-autoprint').checked;

    el('result-name').textContent = filename;
    el('download').href = objectUrl;
    el('download').setAttribute('download', filename);
    preview.src = objectUrl;

    var parts = [];
    if (summary) {
      parts.push(summary.pages + (summary.pages === 1 ? ' pagina' : ' pagina’s'));
      if (summary.images_removed) {
        parts.push(summary.images_removed + ' afbeelding' +
                   (summary.images_removed === 1 ? '' : 'en') + ' na pagina 1 verwijderd');
      }
      if (summary.comment_markers_removed) {
        parts.push('opmerkingen verwijderd');
      }
      if (summary.highlighting_removed) {
        parts.push('markeringen verwijderd');
      }
    }
    parts.push(Math.max(1, Math.round(blob.size / 1024)) + ' kB');
    el('result-meta').textContent = parts.join(' · ');

    var warnings = el('warnings');
    warnings.innerHTML = '';
    if (summary && summary.warnings && summary.warnings.length) {
      summary.warnings.forEach(function (text) {
        var item = document.createElement('li');
        item.textContent = text;
        warnings.appendChild(item);
      });
      warnings.classList.remove('is-hidden');
    } else {
      warnings.classList.add('is-hidden');
    }

    show('result');
  }

  convertButton.addEventListener('click', convert);
  el('retry').addEventListener('click', function () { show('input'); });
  el('again').addEventListener('click', function () { show('input'); });
  el('print').addEventListener('click', printNow);

  refresh();
}());
