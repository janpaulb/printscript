<?php

declare(strict_types=1);

namespace PrintScript;

use PrintScript\Google\CurlTransport;
use PrintScript\Google\GoogleAuthException;
use PrintScript\Google\Keyfile;
use PrintScript\Google\Response;
use PrintScript\Google\Retry;
use PrintScript\Google\ServiceAccount;
use PrintScript\Google\Transport;

/**
 * De downloader voor Google Docs.
 *
 * Uit de link wordt alleen het document-id gehaald; het export-adres wordt
 * daarna zelf opgebouwd. Een geplakte link kan de server dus nooit iets anders
 * laten ophalen.
 *
 * Er zijn twee wegen naar binnen. Zonder sleutel pakken we het openbare
 * export-adres: dat werkt voor documenten die op "iedereen met de link" staan.
 * Is er een serviceaccount ingesteld, dan gaat het via Drive, en dan hoeft een
 * document helemaal niet openbaar te zijn — delen met het adres van het
 * serviceaccount is genoeg.
 *
 * Let op het verschil in adres: docs.google.com/.../export is niet bedoeld
 * voor API-tokens en stuurt een serviceaccount een inlogpagina. De officiële
 * weg voor een ingelogde export is Drive's files/export.
 */
class GoogleDocs
{
    public const MAX_DOWNLOAD_BYTES = 50 * 1024 * 1024;
    public const CONNECT_TIMEOUT = 10;
    public const READ_TIMEOUT = 120;

    private const EXPORT_URL = 'https://docs.google.com/document/d/%s/export?format=docx';

    private const DRIVE_EXPORT_URL = 'https://www.googleapis.com/drive/v3/files/%s/export'
        . '?mimeType=application%%2Fvnd.openxmlformats-officedocument.wordprocessingml.document'
        . '&supportsAllDrives=true';

    private const DRIVE_MEDIA_URL = 'https://www.googleapis.com/drive/v3/files/%s'
        . '?alt=media&supportsAllDrives=true';

    private const DRIVE_FILE_URL = 'https://www.googleapis.com/drive/v3/files/%s'
        . '?fields=name,mimeType,exportLinks&supportsAllDrives=true';

    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private const USER_AGENT = 'PrintScript/3.0 (+https://github.com/janpaulb/printscript)';

    private const ID = '[a-zA-Z0-9_-]{12,}';

    private const HELP = "Plak de deel-link van je document, bijvoorbeeld:\n"
        . 'https://docs.google.com/document/d/<document-id>/edit';

    private readonly Transport $transport;
    private readonly Retry $retry;

    public function __construct(
        private readonly ?ServiceAccount $account = null,
        ?Transport $transport = null,
        ?Retry $retry = null,
    ) {
        $this->transport = $transport
            ?? new CurlTransport(self::MAX_DOWNLOAD_BYTES, self::READ_TIMEOUT);
        $this->retry = $retry ?? new Retry();
    }

    /**
     * Zoekt zelf een sleutel op; vindt hij er geen, dan werkt alles als voorheen.
     *
     * Een kapotte of verkeerd geplaatste sleutel mag de openbare route niet
     * meeslepen: die melding komt als waarschuwing terug, niet als fout.
     */
    public static function configured(?string &$warning = null): self
    {
        $path = Keyfile::locate();
        if ($path === null) {
            return new self();
        }
        try {
            $account = ServiceAccount::fromKeyfile($path);
        } catch (GoogleAuthException $error) {
            $warning = $error->getMessage();
            return new self();
        }
        if (Keyfile::looseReadRights($path)) {
            $warning = "Het sleutelbestand $path is voor iedereen leesbaar. "
                . 'Zet het op chmod 600.';
        }
        return new self($account);
    }

    public function signedInAs(): ?string
    {
        return $this->account?->clientEmail();
    }

    /** Haalt het document-id uit elke vorm die Google gebruikt. */
    public static function extractId(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            throw new \InvalidArgumentException("Geen link opgegeven.\n" . self::HELP);
        }
        if (preg_match('~^' . self::ID . '$~', $url)) {
            return $url;
        }
        if (str_contains($url, '/document/d/e/')) {
            throw new \InvalidArgumentException(
                "Dit is een \"gepubliceerd op internet\"-link. Gebruik de gewone "
                . "deel-link van het document (Delen > Link kopieren).\n" . self::HELP
            );
        }

        $patterns = [
            '~docs\.google\.com/document/(?:u/\d+/)?d/(' . self::ID . ')~',
            '~drive\.google\.com/file/d/(' . self::ID . ')~',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $match)) {
                return $match[1];
            }
        }

        $host = parse_url(str_contains($url, '//') ? $url : "https://$url", PHP_URL_HOST) ?? '';
        if (str_contains((string) $host, 'google.com')) {
            parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
            foreach (['id', 'docid', 'srcid'] as $key) {
                $value = $query[$key] ?? null;
                if (is_string($value) && preg_match('~^' . self::ID . '$~', $value)) {
                    return $value;
                }
            }
        }

        throw new \InvalidArgumentException(
            "Dit lijkt geen Google Docs-link te zijn.\n" . self::HELP
        );
    }

    /** Haalt het document op als .docx. */
    public function download(string $url, ?string $accessToken = null): DownloadedDocument
    {
        $id = self::extractId($url);
        $token = $accessToken ?? (getenv('GOOGLE_ACCESS_TOKEN') ?: null);

        // Drie manieren, op volgorde van hoe specifiek ze zijn: een token dat
        // is meegegeven, ons eigen serviceaccount, of gewoon openbaar.
        if ($token === null && $this->account !== null) {
            $token = $this->account->accessToken();
        }

        return $token === null
            ? $this->downloadPublicly($id)
            : $this->downloadSignedIn($id, $token);
    }

    /** De weg zonder inloggen: alleen voor documenten die openbaar staan. */
    private function downloadPublicly(string $id): DownloadedDocument
    {
        $response = $this->get(sprintf(self::EXPORT_URL, $id), null);
        $this->guard($response, false);
        $this->expectDocx($response, false);

        return new DownloadedDocument($response->body, $id, self::titleFrom($response->headers));
    }

    /**
     * De weg mét serviceaccount.
     *
     * Eerst de gegevens van het bestand, want daar staat alles in wat we
     * nodig hebben: de naam, het soort bestand, en de exportadressen.
     *
     * Dat laatste is belangrijker dan het lijkt. De gewone export van Drive
     * stopt bij tien megabyte, en een repetitiescript met veertig
     * schermafdrukken zit daar zo overheen. Het adres uit exportLinks kent
     * die grens niet — het is hetzelfde adres dat Google Docs zelf gebruikt
     * als je in het menu op Downloaden klikt.
     */
    private function downloadSignedIn(string $id, string $token): DownloadedDocument
    {
        $file = $this->metadata($id, $token);
        $name = is_string($file['name'] ?? null) && $file['name'] !== '' ? $file['name'] : null;
        $kind = is_string($file['mimeType'] ?? null) ? $file['mimeType'] : '';
        $links = is_array($file['exportLinks'] ?? null) ? $file['exportLinks'] : [];

        // Een .docx dat iemand in Drive heeft gezet is al wat we willen; daar
        // valt niets aan te exporteren.
        if ($kind === self::DOCX_MIME) {
            $response = $this->get(sprintf(self::DRIVE_MEDIA_URL, $id), $token);
        } elseif (is_string($links[self::DOCX_MIME] ?? null)) {
            $response = $this->get($links[self::DOCX_MIME], $token);
        } else {
            $response = $this->get(sprintf(self::DRIVE_EXPORT_URL, $id), $token);
        }

        $this->guard($response, true);
        $this->expectDocx($response, true);

        return new DownloadedDocument($response->body, $id, $name);
    }

    /**
     * Naam, soort en exportadressen van het bestand.
     *
     * @return array<string, mixed>
     */
    private function metadata(string $id, string $token): array
    {
        $response = $this->get(sprintf(self::DRIVE_FILE_URL, $id), $token);
        $this->guard($response, true);

        $payload = json_decode($response->body, true);

        return is_array($payload) ? $payload : [];
    }

    private function get(string $url, ?string $token): Response
    {
        $response = $this->retry->run(fn(): Response => $this->transport->send(
            $url,
            array_filter([
                'User-Agent' => self::USER_AGENT,
                'Authorization' => $token === null ? null : "Bearer $token",
            ]),
        ));

        if ($response->networkError !== null) {
            throw new GoogleDocsException(
                'Kan Google Docs niet bereiken: ' . $response->networkError
            );
        }

        return $response;
    }

    private function expectDocx(Response $response, bool $signedIn): void
    {
        if ($response->body === '') {
            throw new GoogleDocsException('Google stuurde een leeg document terug.');
        }
        if (!str_starts_with($response->body, 'PK')) {
            throw new DocumentAccessException($signedIn
                ? 'Google gaf geen document terug. Is dit wel een Google Document '
                    . '(en geen PDF of afbeelding in Drive)?'
                : 'Google gaf geen document terug. Zet het document op "Iedereen met de '
                    . 'link kan bekijken", of deel het met het serviceaccount.');
        }
    }


    private function guard(Response $response, bool $signedIn): void
    {
        $status = $response->status;

        if ($status === 401 || $status === 403 || $status === 404) {
            throw new DocumentAccessException($this->noAccess($status, $signedIn, $response));
        }
        if ($status === 429) {
            throw new GoogleDocsException(
                'Google heeft de aanvraag tijdelijk geblokkeerd (te veel verzoeken). '
                . 'Probeer het over een minuut opnieuw.'
            );
        }
        if ($status >= 500) {
            throw new GoogleDocsException(
                "Google gaf een serverfout (HTTP $status). Probeer het later opnieuw."
            );
        }
        if ($status >= 400) {
            throw new GoogleDocsException("Google antwoordde met HTTP $status.");
        }

        if (str_contains(strtolower($response->header('content-type')), 'text/html')) {
            throw new DocumentAccessException($signedIn
                ? 'Google stuurde een webpagina in plaats van het document. '
                    . 'Controleer of het document met het serviceaccount gedeeld is.'
                : 'Het document is niet openbaar. Google stuurde een inlogpagina in '
                    . 'plaats van het document. Zet het op "Iedereen met de link kan '
                    . 'bekijken".');
        }
    }

    /**
     * De melding die je wél verder helpt.
     *
     * "Geen toegang" heeft bij Drive een handvol heel verschillende oorzaken
     * die er van buiten identiek uitzien: het document is niet gedeeld, de
     * Drive-API staat uit, of de export is te groot. Welke het is, staat in
     * Googles eigen antwoord — dus lezen we dat uit, en zetten we het er
     * altijd onder. Zonder die regel sta je te zoeken naar een deelprobleem
     * dat er niet is.
     */
    private function noAccess(int $status, bool $signedIn, Response $response): string
    {
        [$reason, $detail] = self::reasonFrom($response);
        $email = $this->account?->clientEmail();
        $footer = $detail === '' ? '' : "\n\nGoogle zegt erbij:\n$detail";

        if (self::looksLike($reason, $detail, ['accessNotConfigured', 'SERVICE_DISABLED',
            'has not been used in project', 'is disabled'])) {
            $project = $this->account?->projectId() ?? '';
            return sprintf(
                "De Google Drive API staat uit%s.\n\nZet hem aan in de Google Cloud "
                . "Console: APIs & Services > Library > Google Drive API > Enable%s. "
                . "Na het aanzetten kan het een minuut duren voor het werkt.%s",
                $project === '' ? '' : " voor project \"$project\"",
                $project === '' ? '' : "\n  https://console.cloud.google.com/apis/library/"
                    . "drive.googleapis.com?project=" . rawurlencode($project),
                $footer
            );
        }

        if (self::looksLike($reason, $detail, ['exportSizeLimitExceeded', 'too large to be exported'])) {
            return 'Google wil dit document niet exporteren omdat het te groot is. '
                . "Dat zou niet mogen gebeuren — printscript gebruikt juist het "
                . "exportadres zónder die grens. Lukt het hierna nog niet, exporteer "
                . "het dan in Google Docs zelf naar .docx en upload dat bestand hier.$footer";
        }

        if (self::looksLike($reason, $detail, ['rateLimitExceeded', 'userRateLimitExceeded',
            'dailyLimitExceeded', 'quota'])) {
            return "Google heeft de aanvraag tijdelijk geweigerd wegens te veel "
                . "verzoeken. Probeer het over een minuut opnieuw.$footer";
        }

        if ($email !== null && $signedIn) {
            return sprintf(
                "Geen toegang (HTTP %d). Bestaat het document, en is het gedeeld met "
                . "%s?\n\nDeel het in Google Docs via Delen > voeg %s toe als Kijker.%s",
                $status,
                $email,
                $email,
                $footer
            );
        }

        if ($status === 404) {
            return 'Document niet gevonden. Controleer of de link klopt en of het '
                . "document niet verwijderd is.$footer";
        }

        // Geen serviceaccount én geen toegang: dan is de kans groot dat de
        // sleutel er wél is maar niet gevonden wordt. Zeg dus waar is gekeken,
        // anders staat iemand te zoeken naar een fout die er niet is.
        return "Geen toegang tot dit document.\n\n"
            . "Er is geen serviceaccount ingesteld. Gezocht op:\n  "
            . Keyfile::searchedIn()
            . "\n\nZet het sleutelbestand in een van die mappen, of deel het "
            . "document via \"Iedereen met de link kan bekijken\".$footer";
    }

    /**
     * Reden en uitleg uit Googles antwoord.
     *
     * @return array{0: string, 1: string}
     */
    private static function reasonFrom(Response $response): array
    {
        $payload = json_decode($response->body, true);
        if (!is_array($payload) || !isset($payload['error'])) {
            return ['', ''];
        }

        $error = $payload['error'];
        if (is_string($error)) {
            return [$error, (string) ($payload['error_description'] ?? $error)];
        }
        if (!is_array($error)) {
            return ['', ''];
        }

        $reason = '';
        if (isset($error['errors'][0]['reason']) && is_string($error['errors'][0]['reason'])) {
            $reason = $error['errors'][0]['reason'];
        } elseif (isset($error['status']) && is_string($error['status'])) {
            $reason = $error['status'];
        }

        $detail = isset($error['message']) && is_string($error['message']) ? $error['message'] : '';

        return [$reason, trim($detail)];
    }

    /** @param string[] $needles */
    private static function looksLike(string $reason, string $detail, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (stripos($reason, $needle) !== false || stripos($detail, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /** Google zet de titel van het document in de Content-Disposition. */
    private static function titleFrom(array $headers): ?string
    {
        $disposition = $headers['content-disposition'] ?? '';
        if ($disposition === '') {
            return null;
        }

        if (preg_match("~filename\*\s*=\s*[^']*'[^']*'([^;]+)~i", $disposition, $match)) {
            $name = rawurldecode(trim($match[1]));
        } elseif (preg_match('~filename\s*=\s*"([^"]+)"~i', $disposition, $match)) {
            $name = trim($match[1]);
        } elseif (preg_match('~filename\s*=\s*([^;]+)~i', $disposition, $match)) {
            $name = trim($match[1]);
        } else {
            return null;
        }

        if (str_ends_with(strtolower($name), '.docx')) {
            $name = substr($name, 0, -5);
        }
        return $name === '' ? null : $name;
    }
}
