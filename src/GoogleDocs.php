<?php

declare(strict_types=1);

namespace PrintScript;

/**
 * De downloader voor Google Docs.
 *
 * Uit de link wordt alleen het document-id gehaald; het export-adres wordt
 * daarna zelf opgebouwd. Een geplakte link kan de server dus nooit iets anders
 * laten ophalen.
 *
 * Documenten die gedeeld zijn als "iedereen met de link kan bekijken" werken
 * zonder inloggegevens. Voor een privédocument is een OAuth-token nodig.
 */
class GoogleDocs
{
    public const MAX_DOWNLOAD_BYTES = 50 * 1024 * 1024;
    public const CONNECT_TIMEOUT = 10;
    public const READ_TIMEOUT = 120;

    private const EXPORT_URL = 'https://docs.google.com/document/d/%s/export?format=docx';
    private const USER_AGENT = 'PrintScript/3.0 (+https://github.com/janpaulb/printscript)';

    private const ID = '[a-zA-Z0-9_-]{12,}';

    private const HELP = "Plak de deel-link van je document, bijvoorbeeld:\n"
        . 'https://docs.google.com/document/d/<document-id>/edit';

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

        [$body, $status, $headers] = $this->fetch(sprintf(self::EXPORT_URL, $id), $token);

        $this->guard($status, $headers, $token !== null);

        if (strlen($body) === 0) {
            throw new GoogleDocsException('Google stuurde een leeg document terug.');
        }
        if (!str_starts_with($body, 'PK')) {
            throw new DocumentAccessException(
                'Google gaf geen document terug. Zet het document op "Iedereen met de '
                . 'link kan bekijken", of gebruik een account met toegang.'
            );
        }

        return new DownloadedDocument($body, $id, self::titleFrom($headers));
    }

    /** @return array{0: string, 1: int, 2: array<string, string>} */
    protected function fetch(string $url, ?string $token): array
    {
        if (!function_exists('curl_init')) {
            throw new GoogleDocsException(
                'De curl-uitbreiding van PHP ontbreekt, dus documenten kunnen niet bij '
                . 'Google worden opgehaald. Upload het .docx-bestand in plaats daarvan.'
            );
        }

        $headers = [];
        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::READ_TIMEOUT,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => $token === null ? [] : ["Authorization: Bearer $token"],
            CURLOPT_HEADERFUNCTION => function ($handle, string $line) use (&$headers): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
            // Meer dan de limiet hoeven we niet binnen te halen.
            CURLOPT_BUFFERSIZE => 65536,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => static function ($handle, $expected, $received): int {
                return $received > self::MAX_DOWNLOAD_BYTES ? 1 : 0;
            },
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($handle);
        $message = curl_error($handle);
        curl_close($handle);

        if ($error === CURLE_ABORTED_BY_CALLBACK) {
            throw new GoogleDocsException(sprintf(
                'Het document is groter dan de limiet van %d MB.',
                intdiv(self::MAX_DOWNLOAD_BYTES, 1024 * 1024)
            ));
        }
        if ($error === CURLE_OPERATION_TIMEDOUT) {
            throw new GoogleDocsException('Google reageerde niet op tijd. Probeer het opnieuw.');
        }
        if ($body === false) {
            throw new GoogleDocsException("Kan Google Docs niet bereiken: $message");
        }

        return [$body, $status, $headers];
    }

    /** @param array<string, string> $headers */
    private function guard(int $status, array $headers, bool $authenticated): void
    {
        if ($status === 401 || $status === 403) {
            throw new DocumentAccessException(
                'Geen toegang tot dit document. Deel het via "Iedereen met de link kan '
                . 'bekijken"' . ($authenticated ? '' : ', of log in met een account dat '
                . 'toegang heeft') . '.'
            );
        }
        if ($status === 404) {
            throw new DocumentAccessException(
                'Document niet gevonden. Controleer of de link klopt en of het document '
                . 'niet verwijderd is.'
            );
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

        $type = strtolower($headers['content-type'] ?? '');
        if (str_contains($type, 'text/html')) {
            throw new DocumentAccessException(
                'Het document is niet openbaar. Google stuurde een inlogpagina in plaats '
                . 'van het document. Zet het op "Iedereen met de link kan bekijken".'
            );
        }
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
