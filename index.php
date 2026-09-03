<?php

declare(strict_types=1);

/**
 * PrintScript — één ingang.
 *
 * GET  toont de webinterface.
 * POST zet een Google Docs-link of een geüpload .docx om in een PDF, en geeft
 *      die meteen terug. Het verslag reist mee in een header, zodat de pagina
 *      geen tweede verzoek hoeft te doen.
 *
 * Deze map mag zo op een webserver: er is verder niets te configureren.
 */

use PrintScript\DocumentAccessException;
use PrintScript\GoogleDocsException;
use PrintScript\InvalidDocxException;
use PrintScript\Options;
use PrintScript\Pipeline;

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit(
        "PrintScript is niet compleet: de map vendor/ ontbreekt.\n"
        . "Daar zit de PDF-motor in, dus zonder die map werkt er niets.\n\n"
        . "Dit gebeurt als je de broncode van GitHub hebt gedownload. In de\n"
        . "broncode zit vendor/ met opzet niet; die hoort bij het uitrolpakket.\n\n"
        . "Wat je nodig hebt:\n\n"
        . "  https://github.com/janpaulb/printscript/releases/latest\n\n"
        . "Download daar printscript-php.zip (niet \"Source code\"), pak hem uit,\n"
        . "en zet de inhoud in deze map. Daar zit vendor/ al in.\n\n"
        . "Heb je wel shell-toegang, dan kan het ook zo:  composer install --no-dev\n"
    );
}
require $autoload;

const PRINTSCRIPT_VERSION = '3.0.0';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    giveTheConversionRoom();
    reportFatalErrorsAsJson();
    handleConversion();
    exit;
}

showPage();


// ── Ruimte en vangnet ────────────────────────────────────────────────────────

/**
 * Een script van veertig pagina's met veertig schermafdrukken is gewoon werk.
 *
 * De standaardinstellingen van een gedeelde hosting zijn op webpagina's
 * gemaakt, niet op het opmaken van een boekwerk: dertig seconden en honderd-
 * achtentwintig megabyte. Beide mogen vaak wél omhoog vanuit het script zelf.
 * Lukt het niet, dan stond het al vast en verandert deze functie niets — ze
 * probeert het alleen, en nooit naar beneden.
 */
function giveTheConversionRoom(): void
{
    // Op een gedeelde hosting staan deze twee geregeld in disable_functions.
    // Ze zomaar aanroepen is dan een fatale fout — en juist deze functie mag
    // niet degene zijn die de boel opblaast.
    if (function_exists('set_time_limit')) {
        @set_time_limit(300);
    }
    if (!function_exists('ini_set') || !function_exists('ini_get')) {
        return;
    }

    $limit = trim((string) ini_get('memory_limit'));
    if ($limit !== '' && $limit !== '-1' && bytesOf($limit) < 512 * 1024 * 1024) {
        @ini_set('memory_limit', '512M');
    }
}

/** "128M" en "1G" naar bytes; een kale waarde is al bytes. */
function bytesOf(string $value): int
{
    $number = (int) $value;
    return match (strtolower(substr($value, -1))) {
        'g' => $number * 1024 * 1024 * 1024,
        'm' => $number * 1024 * 1024,
        'k' => $number * 1024,
        default => $number,
    };
}

/**
 * Een fatale fout mag geen lege 500 opleveren.
 *
 * Raakt PHP door zijn geheugen of zijn tijd heen, dan is dat geen exceptie
 * die je kunt opvangen: het script houdt ter plekke op. De browser krijgt dan
 * een antwoord zonder inhoud en de pagina kan niet beter dan "Onverwachte
 * fout (HTTP 500)" melden — precies de foutmelding waar niemand iets aan
 * heeft. Deze afsluiter maakt er alsnog een leesbaar bericht van, mét wat de
 * hostingpartij dan moet verhogen.
 */
function reportFatalErrorsAsJson(): void
{
    // Raakt PHP door zijn geheugen heen, dan is er ook geen geheugen meer om
    // dat te vertellen: de afsluiter komt zelf niet verder dan een leeg
    // antwoord. Dus leggen we nu alvast een stukje opzij en geven dat als
    // eerste weer vrij.
    $reserve = str_repeat(' ', 256 * 1024);

    register_shutdown_function(static function () use (&$reserve): void {
        $reserve = null;
        $error = error_get_last();
        if ($error === null
            || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            return;
        }
        if (headers_sent()) {
            return;   // de PDF is al onderweg; er valt niets meer te melden
        }

        $message = $error['message'];
        if (str_contains($message, 'memory size')) {
            $message = sprintf(
                'De server had te weinig geheugen voor dit document (limiet: %s). '
                . 'Vraag je hostingpartij om memory_limit op 512M te zetten.',
                function_exists('ini_get') ? (ini_get('memory_limit') ?: 'onbekend') : 'onbekend'
            );
        } elseif (str_contains($message, 'Maximum execution time')) {
            $message = sprintf(
                'De server stopte na %s seconden, voordat het document af was. '
                . 'Vraag je hostingpartij om max_execution_time te verhogen.',
                function_exists('ini_get')
                    ? (ini_get('max_execution_time') ?: 'onbekend') : 'onbekend'
            );
        } else {
            $message = 'Conversie mislukt: ' . $message;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo (string) json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    });
}


// ── Omzetten ─────────────────────────────────────────────────────────────────

function handleConversion(): void
{
    try {
        [$options, $source] = readRequest();
    } catch (InvalidArgumentException $error) {
        fail(400, $error->getMessage());
    }

    try {
        $pipeline = new Pipeline();
        $result = $source['kind'] === 'url'
            ? $pipeline->convertGoogleDoc($source['url'], $options, $source['token'])
            : $pipeline->convertDocx($source['data'], $options, $source['filename']);
    } catch (DocumentAccessException $error) {
        fail(403, $error->getMessage());
    } catch (GoogleDocsException $error) {
        fail(502, $error->getMessage());
    } catch (InvalidDocxException | InvalidArgumentException $error) {
        fail(400, $error->getMessage());
    } catch (Throwable $error) {
        error_log('PrintScript: ' . $error->getMessage());
        fail(500, 'Conversie mislukt: ' . $error->getMessage());
    }

    $summary = base64_encode((string) json_encode(
        $result->summary(),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ));

    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($result->pdf));
    header(sprintf(
        'Content-Disposition: inline; filename="%s"; filename*=UTF-8\'\'%s',
        asciiFilename($result->filename),
        rawurlencode($result->filename)
    ));
    header('X-PrintScript-Summary: ' . $summary);
    header('Cache-Control: no-store');
    echo $result->pdf;
}

/** @return array{0: Options, 1: array<string, mixed>} */
function readRequest(): array
{
    if (!empty($_FILES['file']['name'])) {
        $upload = $_FILES['file'];
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException(uploadError((int) $upload['error']));
        }
        if (!str_ends_with(strtolower((string) $upload['name']), '.docx')) {
            throw new InvalidArgumentException(
                'Alleen .docx-bestanden kunnen worden omgezet. Exporteer je document '
                . 'eerst naar .docx.'
            );
        }
        $data = file_get_contents((string) $upload['tmp_name']);
        if ($data === false) {
            throw new InvalidArgumentException('Het bestand kon niet gelezen worden.');
        }
        $options = Options::fromArray(json_decode((string) ($_POST['options'] ?? '{}'), true));
        return [$options, [
            'kind' => 'file',
            'data' => $data,
            'filename' => (string) $upload['name'],
        ]];
    }

    $payload = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = $_POST;
    }
    $url = trim((string) ($payload['url'] ?? ''));
    if ($url === '') {
        throw new InvalidArgumentException('Geen Google Docs-link opgegeven.');
    }
    $token = trim((string) ($payload['access_token'] ?? ''));

    return [Options::fromArray($payload['options'] ?? $payload), [
        'kind' => 'url',
        'url' => $url,
        'token' => $token === '' ? null : $token,
    ]];
}

function uploadError(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
            'Het bestand is groter dan deze server toestaat. Vraag je host om '
            . 'upload_max_filesize en post_max_size te verhogen.',
        UPLOAD_ERR_PARTIAL => 'Het bestand is maar half aangekomen. Probeer het opnieuw.',
        UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE =>
            'De server kan geen tijdelijke bestanden schrijven.',
        default => 'Het bestand kon niet worden ontvangen.',
    };
}

function fail(int $status, string $message): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo (string) json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function asciiFilename(string $name): string
{
    $ascii = preg_replace('~[^\x20-\x7E]~', '?', $name) ?? 'document.pdf';
    return str_replace('"', '', $ascii);
}


// ── De pagina ────────────────────────────────────────────────────────────────

function showPage(): void
{
    header('Content-Type: text/html; charset=utf-8');
    $version = PRINTSCRIPT_VERSION;
    $warning = environmentWarning();

    echo <<<HTML
    <!DOCTYPE html>
    <html lang="nl">
    <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1">
      <title>PrintScript — drukklare PDF uit Google Docs</title>
      <link rel="stylesheet" href="assets/style.css">
      <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🖨️</text></svg>">
    </head>
    <body>

    <header class="masthead">
      <div class="wordmark"><span class="mark">▶</span><span>PrintScript</span></div>
      <p class="strapline">Plak een Google Docs-link, krijg een drukklare PDF terug.</p>
    </header>

    <main>
      $warning
      <section class="panel" id="input-panel">

        <div class="tabs" role="tablist">
          <button class="tab is-active" role="tab" data-target="pane-url" aria-selected="true">
            Google Docs-link
          </button>
          <button class="tab" role="tab" data-target="pane-file" aria-selected="false">
            Word-bestand
          </button>
        </div>

        <div class="pane" id="pane-url">
          <label class="field-label" for="doc-url">Deel-link van het document</label>
          <input type="url" id="doc-url" class="field"
                 placeholder="https://docs.google.com/document/d/…/edit"
                 autocomplete="off" spellcheck="false">
          <p class="field-hint">
            Het document moet gedeeld zijn via <strong>Iedereen met de link&nbsp;→&nbsp;Kijker</strong>.
            Plakken is genoeg — dan begint het meteen.
          </p>
        </div>

        <div class="pane is-hidden" id="pane-file">
          <div class="dropzone" id="dropzone" tabindex="0" role="button"
               aria-label="Kies of sleep een .docx-bestand">
            <span class="dropzone-icon">📄</span>
            <p>Sleep een <strong>.docx</strong> hierheen</p>
            <label class="button button-quiet" for="file-input">Bladeren</label>
            <input type="file" id="file-input" accept=".docx" hidden>
            <p class="field-hint" id="file-name">Nog geen bestand gekozen</p>
          </div>
        </div>

        <ul class="spec">
          <li>Alle <strong>opmerkingen</strong> worden verwijderd</li>
          <li>Alle <strong>markeringen</strong> worden verwijderd — tekstkleur blijft staan</li>
          <li>Alle <strong>afbeeldingen na pagina 1</strong> worden verwijderd</li>
          <li><strong>Paginanummering</strong> in de voettekst blijft behouden</li>
        </ul>

        <details class="options">
          <summary>Opties</summary>
          <label class="switch"><input type="checkbox" id="opt-images" checked>
            <span>Afbeeldingen alleen op pagina 1 toestaan</span></label>
          <label class="switch"><input type="checkbox" id="opt-numbers" checked>
            <span>Paginanummer toevoegen als het document er geen heeft</span></label>
          <label class="switch"><input type="checkbox" id="opt-first">
            <span>Geen paginanummer op pagina 1 (omslag)</span></label>
          <label class="switch"><input type="checkbox" id="opt-autoprint" checked>
            <span>Printvenster meteen openen als de PDF klaar is</span></label>
        </details>

        <button class="button button-primary" id="convert" disabled>Maak drukklare PDF</button>
      </section>

      <section class="panel is-hidden" id="busy-panel">
        <div class="spinner" aria-hidden="true"></div>
        <p class="status" id="busy-text">Document ophalen…</p>
      </section>

      <section class="panel panel-error is-hidden" id="error-panel">
        <h2>Dat lukte niet</h2>
        <p class="error-text" id="error-text"></p>
        <button class="button button-quiet" id="retry">Opnieuw proberen</button>
      </section>

      <section class="panel is-hidden" id="result-panel">
        <div class="result-head">
          <div>
            <h2 id="result-name">Klaar</h2>
            <p class="result-meta" id="result-meta"></p>
          </div>
          <div class="result-actions">
            <button class="button button-primary" id="print">🖨 Printen</button>
            <a class="button" id="download" download>Downloaden</a>
            <button class="button button-quiet" id="again">Nieuw document</button>
          </div>
        </div>
        <p class="reminder is-hidden" id="reminder" role="alert">
          Google Docs weer op privé zetten, ouwe gek!
        </p>
        <ul class="warnings is-hidden" id="warnings"></ul>
        <iframe class="preview" id="preview" title="Voorbeeld van de PDF"></iframe>
      </section>
    </main>

    <footer class="colophon">
      <p>Verwerking gebeurt op de server en niets wordt bewaard — het document
         bestaat alleen tijdens de conversie in het geheugen.</p>
      <p class="version">PrintScript $version</p>
    </footer>

    <script src="assets/app.js"></script>
    </body>
    </html>
    HTML;
}

/** Ontbreekt er iets op deze server, dan zegt de pagina dat meteen. */
function environmentWarning(): string
{
    $missing = [];
    foreach (['zip' => 'ZipArchive', 'dom' => 'DOMDocument', 'mbstring' => 'mb_substr'] as $extension => $probe) {
        if (!extension_loaded($extension)) {
            $missing[] = $extension;
        }
    }
    if (!extension_loaded('gd')) {
        $missing[] = 'gd (nodig voor afbeeldingen)';
    }
    if (!function_exists('curl_init')) {
        $missing[] = 'curl (nodig voor Google Docs-links)';
    }

    if ($missing === []) {
        return '';
    }
    return '<section class="panel panel-error"><h2>Deze server mist iets</h2>'
        . '<p class="error-text">Ontbrekende PHP-uitbreidingen: '
        . htmlspecialchars(implode(', ', $missing), ENT_QUOTES)
        . '. Vraag je hostingpartij die in te schakelen.</p></section>';
}
