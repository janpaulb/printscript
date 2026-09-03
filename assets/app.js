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
  var printedSource = null;

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

  /* Elke vorm die Google gebruikt, plus een kaal document-id. Dezelfde
     vormen als src/GoogleDocs.php aanneemt — hier alleen om te zien of er
     iets bruikbaars staat, het echte uitpakken gebeurt op de server. */
  var DOCUMENT_ID = '[a-zA-Z0-9_-]{12,}';
  var LOOKS_LIKE_A_DOCUMENT = new RegExp(
    '^' + DOCUMENT_ID + '$'
    + '|docs\\.google\\.com/document/(?:u/\\d+/)?d/' + DOCUMENT_ID
    + '|drive\\.google\\.com/file/d/' + DOCUMENT_ID
    + '|drive\\.google\\.com/open\\?id=' + DOCUMENT_ID
  );

  /* Wat er al onderweg is of net gedaan is, gaat niet nog een keer: anders
     start een tweede plakbeurt of een cursor in het veld dezelfde conversie
     opnieuw. */
  var converted = '';
  var pasteTimer = null;

  function convertIfItIsALink() {
    var value = urlInput.value.trim();
    if (mode !== 'url' || value === converted || !LOOKS_LIKE_A_DOCUMENT.test(value)) {
      return;
    }
    convert();
  }

  urlInput.addEventListener('input', function () {
    refresh();
    // Een link plakken is genoeg. Het veld verandert dan in één klap van
    // leeg naar een compleet adres, en daar hoeft niemand nog een knop bij
    // te zoeken. Typen doet niets tot er echt een link staat.
    window.clearTimeout(pasteTimer);
    pasteTimer = window.setTimeout(convertIfItIsALink, 250);
  });
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
    remind();
  }

  /* Om hier te komen moet het document op "iedereen met de link" staan. Na
     het printen is dat niet meer nodig, en het is precies het soort ding dat
     je vergeet. Alleen bij een link: een geupload bestand staat nergens open. */
  function remind() {
    el('reminder').classList.toggle('is-hidden', printedSource !== 'url');
  }

  function fail(message) {
    el('error-text').textContent = message;
    show('error');
  }

  /* Wat de server terugstuurde als het geen JSON was: meestal een PHP-fout in
     platte tekst of in HTML. De tags eraf, en dan het begin ervan — daar staat
     het echte probleem in. */
  function serverMessage(body, status) {
    var text = String(body || '')
      .replace(/<br\s*\/?>/gi, ' ')
      .replace(/<[^>]*>/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
    if (text.length > 300) { text = text.slice(0, 300) + '…'; }
    return text
      ? 'De server gaf een fout (HTTP ' + status + '): ' + text
      : 'Onverwachte fout (HTTP ' + status + '). De server stuurde geen uitleg mee — ' +
        'meestal betekent dat te weinig geheugen of te weinig tijd voor een document ' +
        'van deze omvang.';
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
    converted = urlInput.value.trim();
    printedSource = mode;
    el('reminder').classList.add('is-hidden');
    el('busy-text').textContent = mode === 'url'
      ? 'Document ophalen bij Google…'
      : 'Document inlezen…';

    var request;
    if (mode === 'url') {
      request = fetch('index.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ url: urlInput.value.trim(), options: options() })
      });
    } else {
      var form = new FormData();
      form.append('file', chosenFile);
      form.append('options', JSON.stringify(options()));
      request = fetch('index.php', { method: 'POST', body: form });
    }

    window.setTimeout(function () {
      if (!panels.busy.classList.contains('is-hidden')) {
        el('busy-text').textContent = 'Opmaken en pagina’s tellen…';
      }
    }, 1200);

    request.then(function (response) {
      var type = response.headers.get('Content-Type') || '';
      if (!response.ok || type.indexOf('application/pdf') === -1) {
        // Niet meteen naar JSON grijpen: gaat de server halverwege onderuit,
        // dan komt er een brok tekst of HTML terug, en daar staat vaak precies
        // in wat er aan de hand is. Dat is meer waard dan "onverwachte fout".
        return response.text().then(function (body) {
          var payload = null;
          try { payload = JSON.parse(body); } catch (error) { payload = null; }
          if (payload && payload.error) { throw new Error(payload.error); }
          throw new Error(serverMessage(body, response.status));
        });
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
  // Na een mislukking mag dezelfde link het wél opnieuw proberen.
  el('retry').addEventListener('click', function () { converted = ''; show('input'); });
  el('again').addEventListener('click', function () { converted = ''; show('input'); });
  el('print').addEventListener('click', printNow);

  refresh();
}());
