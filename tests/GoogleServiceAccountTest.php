<?php

declare(strict_types=1);

namespace PrintScript\Tests;

use PHPUnit\Framework\TestCase;
use PrintScript\DocumentAccessException;
use PrintScript\Google\GoogleAuthException;
use PrintScript\Google\Keyfile;
use PrintScript\Google\Response;
use PrintScript\Google\Retry;
use PrintScript\Google\ServiceAccount;
use PrintScript\Google\Transport;
use PrintScript\GoogleDocs;
use PrintScript\GoogleDocsException;

/**
 * De koppeling met Google, zonder netwerk.
 *
 * Alles wat met Google praat gaat door één deurtje (Transport), en dat deurtje
 * vervangen we hier door een lijstje antwoorden. Zo is een verlopen token, een
 * 403 of drie keer achter elkaar een 503 gewoon een test.
 *
 * Het sleutelpaar wordt per test aangemaakt. Er staat dus nergens in deze repo
 * een echte privésleutel, ook niet als voorbeeld.
 */
final class GoogleServiceAccountTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/printscript-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/domains/site/public_html/print', 0o755, true);
        mkdir($this->directory . '/domains/site/printscript-keys', 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse((array) glob($this->directory . '/{,*/,*/*/,*/*/*/,*/*/*/*/}*',
            GLOB_BRACE)) as $path) {
            is_dir($path) ? @rmdir($path) : @unlink($path);
        }
        @rmdir($this->directory);
        unset($_SERVER['DOCUMENT_ROOT'], $_SERVER[Keyfile::VARIABLE]);
    }

    /** Een serviceaccount-bestand zoals Google het schrijft, met een eigen sleutelpaar. */
    private function writeKeyfile(string $path, string $email = 'titelaar@titelaar.iam.gserviceaccount.com'): string
    {
        $pair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($pair, $private);

        file_put_contents($path, (string) json_encode([
            'type' => 'service_account',
            'project_id' => 'titelaar',
            'private_key_id' => '0123456789abcdef',
            'private_key' => $private,
            'client_email' => $email,
            'client_id' => '112381265875277287736',
            'auth_uri' => 'https://accounts.google.com/o/oauth2/auth',
            'token_uri' => 'https://oauth2.googleapis.com/token',
            'universe_domain' => 'googleapis.com',
        ], JSON_PRETTY_PRINT));

        return $path;
    }

    // ── De sleutel vinden ────────────────────────────────────────────────────

    public function testTheKeyIsFoundNextToTheWebRoot(): void
    {
        $_SERVER['DOCUMENT_ROOT'] = $this->directory . '/domains/site/public_html';
        $expected = $this->writeKeyfile(
            $this->directory . '/domains/site/printscript-keys/google.json'
        );

        $this->assertSame(realpath($expected), realpath((string) Keyfile::locate()));
    }

    /**
     * De naam die Google zelf aan de sleutel geeft, moet gewoon werken.
     *
     * Dit ging mis in het echt: de map klopte, maar het bestand heette
     * titelaar-serviceaccount.json en printscript zocht google.json. Hij vond
     * niets, viel terug op de openbare route en meldde "geen toegang" — een
     * melding die naar de verkeerde oorzaak wijst. De mapnaam is nu leidend,
     * niet de bestandsnaam.
     */
    public function testAnyJsonInTheKeyFolderCounts(): void
    {
        $_SERVER['DOCUMENT_ROOT'] = $this->directory . '/domains/site/public_html';
        $expected = $this->writeKeyfile(
            $this->directory . '/domains/site/printscript-keys/titelaar-serviceaccount.json'
        );

        $this->assertSame(realpath($expected), realpath((string) Keyfile::locate()));
    }

    /** Staat google.json er óók, dan wint die — anders is het maar net hoe het sorteert. */
    public function testTheDocumentedNameWinsWhenThereAreSeveral(): void
    {
        $_SERVER['DOCUMENT_ROOT'] = $this->directory . '/domains/site/public_html';
        $folder = $this->directory . '/domains/site/printscript-keys';
        $this->writeKeyfile($folder . '/titelaar-serviceaccount.json');
        $documented = $this->writeKeyfile($folder . '/google.json');

        $this->assertSame(realpath($documented), realpath((string) Keyfile::locate()));
    }

    /** Zonder sleutel zegt de melding waar gekeken is, niet alleen dát het misging. */
    public function testWithoutAKeyTheErrorSaysWhereItLooked(): void
    {
        $_SERVER['DOCUMENT_ROOT'] = $this->directory . '/domains/site/public_html';
        $transport = new FakeTransport([new Response(403, '')]);
        $docs = new GoogleDocs(null, $transport, new Retry(1, static function (): void {}));

        try {
            $docs->download('1eN-CHE5oC_6CxPn7NXnRX25S7RYrn8dE');
            $this->fail('hier hoorde een fout te komen');
        } catch (DocumentAccessException $error) {
            $this->assertStringContainsString('printscript-keys', $error->getMessage());
            $this->assertStringContainsString('*.json', $error->getMessage(),
                'elk .json-bestand telt, en dat moet er ook staan');
        }
    }

    /**
     * "Geen toegang" heeft bij Drive heel verschillende oorzaken die er van
     * buiten identiek uitzien. Welke het is staat in Googles eigen antwoord,
     * dus dat moet eruit — anders zoek je naar een deelprobleem dat er niet is.
     *
     * @dataProvider refusals
     */
    public function testGoogleOwnReasonDecidesTheMessage(
        string $body,
        string $expected,
        string $mustContain
    ): void {
        $transport = new FakeTransport([
            new Response(200, (string) json_encode(['access_token' => 'ya29.t', 'expires_in' => 3600])),
            new Response(403, $body),
        ]);
        $docs = new GoogleDocs(
            ServiceAccount::fromKeyfile($this->writeKeyfile($this->directory . '/k.json'), $transport),
            $transport,
            new Retry(1, static function (): void {})
        );

        try {
            $docs->download('1eN-CHE5oC_6CxPn7NXnRX25S7RYrn8dE');
            $this->fail('hier hoorde een fout te komen');
        } catch (DocumentAccessException $error) {
            $this->assertStringContainsString($expected, $error->getMessage());
            $this->assertStringContainsString($mustContain, $error->getMessage(),
                'Googles eigen uitleg hoort er altijd onder te staan');
        }
    }

    /** @return array<string, array{string, string, string}> */
    public static function refusals(): array
    {
        $drive = static fn(string $reason, string $message): string => (string) json_encode([
            'error' => ['errors' => [['reason' => $reason]], 'code' => 403, 'message' => $message],
        ]);

        return [
            'Drive-API staat uit' => [
                $drive('accessNotConfigured',
                    'Google Drive API has not been used in project titelaar before or it is disabled.'),
                'Drive API staat uit',
                'has not been used in project',
            ],
            'document te groot om te exporteren' => [
                $drive('exportSizeLimitExceeded', 'This file is too large to be exported.'),
                'te groot is',
                'too large to be exported',
            ],
            'te veel verzoeken' => [
                $drive('rateLimitExceeded', 'Rate Limit Exceeded'),
                'te veel verzoeken',
                'Rate Limit Exceeded',
            ],
            'echt niet gedeeld' => [
                $drive('insufficientFilePermissions',
                    'The user does not have sufficient permissions for this file.'),
                'gedeeld met titelaar@titelaar.iam.gserviceaccount.com',
                'sufficient permissions',
            ],
        ];
    }

    /** Bij de Drive-API-fout staat de knop erbij waar je hem aanzet. */
    public function testTheDisabledApiMessageLinksToTheRightProject(): void
    {
        $transport = new FakeTransport([
            new Response(200, (string) json_encode(['access_token' => 'ya29.t', 'expires_in' => 3600])),
            new Response(403, (string) json_encode([
                'error' => ['errors' => [['reason' => 'accessNotConfigured']], 'message' => 'disabled'],
            ])),
        ]);
        $docs = new GoogleDocs(
            ServiceAccount::fromKeyfile($this->writeKeyfile($this->directory . '/k.json'), $transport),
            $transport,
            new Retry(1, static function (): void {})
        );

        $this->expectExceptionMessageMatches(
            '~console\.cloud\.google\.com/apis/library/drive\.googleapis\.com\?project=titelaar~'
        );
        $docs->download('1eN-CHE5oC_6CxPn7NXnRX25S7RYrn8dE');
    }

    public function testTheEnvironmentVariableWins(): void
    {
        $_SERVER['DOCUMENT_ROOT'] = $this->directory . '/domains/site/public_html';
        $this->writeKeyfile($this->directory . '/domains/site/printscript-keys/google.json');
        $elsewhere = $this->writeKeyfile($this->directory . '/elders.json');
        $_SERVER[Keyfile::VARIABLE] = $elsewhere;

        $this->assertSame(realpath($elsewhere), realpath((string) Keyfile::locate()));
    }

    /**
     * De bewaking waar het allemaal om begonnen is.
     *
     * Zet iemand de sleutel per ongeluk in de webmap, dan werkt alles gewoon —
     * en is de sleutel al die tijd te downloaden. Dus liever stoppen met een
     * uitleg dan stilletjes doorgaan.
     */
    public function testAKeyInsideTheWebRootIsRefused(): void
    {
        $root = $this->directory . '/domains/site/public_html';
        $_SERVER['DOCUMENT_ROOT'] = $root;
        $inside = $this->writeKeyfile($root . '/print/google.json');

        $this->expectException(GoogleAuthException::class);
        $this->expectExceptionMessageMatches('~webmap.*te downloaden~si');
        Keyfile::read($inside);
    }

    /**
     * private_html klinkt besloten maar is het niet: op DirectAdmin is dat
     * meestal een snelkoppeling naar public_html. Omdat we uitgezochte paden
     * vergelijken, wordt die snelkoppeling gevolgd en alsnog geweigerd.
     */
    public function testPrivateHtmlIsNotPrivateWhenItPointsAtTheWebRoot(): void
    {
        $root = $this->directory . '/domains/site/public_html';
        $_SERVER['DOCUMENT_ROOT'] = $root;
        symlink($root, $this->directory . '/domains/site/private_html');
        $looksSafe = $this->writeKeyfile(
            $this->directory . '/domains/site/private_html/google.json'
        );

        $this->expectException(GoogleAuthException::class);
        Keyfile::read($looksSafe);
    }

    public function testAnIncompleteKeyfileSaysWhatIsMissing(): void
    {
        $path = $this->directory . '/half.json';
        file_put_contents($path, '{"type":"service_account","client_email":"x@y.z"}');

        $this->expectException(GoogleAuthException::class);
        $this->expectExceptionMessageMatches('~private_key~');
        Keyfile::read($path);
    }

    // ── Inloggen ─────────────────────────────────────────────────────────────

    public function testTheTokenIsRequestedOnceAndThenReused(): void
    {
        $transport = new FakeTransport([
            new Response(200, (string) json_encode(['access_token' => 'ya29.test', 'expires_in' => 3600])),
        ]);
        $account = ServiceAccount::fromKeyfile(
            $this->writeKeyfile($this->directory . '/sleutel.json'),
            $transport
        );

        $this->assertSame('ya29.test', $account->accessToken());
        $this->assertSame('ya29.test', $account->accessToken(), 'tweede keer uit het geheugen');
        $this->assertCount(1, $transport->calls, 'één verzoek, niet twee');

        // En wat er verstuurd werd, is een echte ondertekende JWT.
        [$header, $claims, $signature] = explode('.', $transport->calls[0]['form']['assertion']);
        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'],
            array_intersect_key(self::decode($header), ['alg' => 1, 'typ' => 1]));
        $this->assertSame('https://www.googleapis.com/auth/drive.readonly',
            self::decode($claims)['scope'], 'alleen lezen, en via Drive want we exporteren');
        $this->assertNotSame('', $signature);
    }

    public function testARefusedKeySaysSoInPlainDutch(): void
    {
        $transport = new FakeTransport([
            new Response(400, (string) json_encode([
                'error' => 'invalid_grant',
                'error_description' => 'Invalid JWT Signature.',
            ])),
        ]);
        $account = ServiceAccount::fromKeyfile(
            $this->writeKeyfile($this->directory . '/sleutel.json'),
            $transport
        );

        $this->expectException(GoogleAuthException::class);
        $this->expectExceptionMessageMatches('~ingetrokken of vervangen~');
        $account->accessToken();
    }

    // ── Opnieuw proberen ─────────────────────────────────────────────────────

    public function testTemporaryFailuresAreRepeatedWithGrowingPauses(): void
    {
        $waits = [];
        $retry = new Retry(6, static function (int $microseconds) use (&$waits): void {
            $waits[] = intdiv($microseconds, 1000);
        });

        $responses = [
            new Response(503, 'nog even niet'),
            new Response(0, '', [], 'Connection reset by peer'),
            new Response(429, 'rustig aan'),
            new Response(200, 'PK gelukt'),
        ];
        $response = $retry->run(static function () use (&$responses): Response {
            return array_shift($responses) ?? new Response(200, 'PK');
        });

        $this->assertSame(200, $response->status);
        $this->assertSame([200, 400, 800], $waits, 'telkens twee keer zo lang');
    }

    public function testPermanentFailuresAreNotRepeated(): void
    {
        foreach ([400, 401, 403, 404] as $status) {
            $calls = 0;
            $retry = new Retry(6, static function (): void {});
            $retry->run(static function () use ($status, &$calls): Response {
                $calls++;
                return new Response($status, '');
            });
            $this->assertSame(1, $calls, "HTTP $status is geen tijdelijke fout");
        }
    }

    public function testTheWaitNeverGrowsBeyondFiveSeconds(): void
    {
        $this->assertSame(200, Retry::waitAfter(1));
        $this->assertSame(5000, Retry::waitAfter(20));
    }

    // ── Ophalen ──────────────────────────────────────────────────────────────

    public function testASignedInDownloadGoesThroughDriveAndKeepsTheTitle(): void
    {
        $transport = new FakeTransport([
            new Response(200, (string) json_encode(['access_token' => 'ya29.test', 'expires_in' => 3600])),
            new Response(200, (string) json_encode([
                'name' => 'REPETITIESCRIPT S04E04',
                'mimeType' => 'application/vnd.google-apps.document',
                'exportLinks' => [
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
                        => 'https://docs.google.com/feeds/download/documents/export/Export'
                            . '?id=1eN-CHE5oC_6CxPn7NXnRX25S7RYrn8dE&exportFormat=docx',
                ],
            ])),
            new Response(200, 'PK' . str_repeat('x', 40)),
        ]);
        $docs = new GoogleDocs(
            ServiceAccount::fromKeyfile($this->writeKeyfile($this->directory . '/k.json'), $transport),
            $transport,
            new Retry(6, static function (): void {})
        );

        $document = $docs->download(
            'https://docs.google.com/document/d/1eN-CHE5oC_6CxPn7NXnRX25S7RYrn8dE/edit?tab=t.0'
        );

        $this->assertSame('REPETITIESCRIPT S04E04', $document->title);
        $this->assertStringStartsWith('PK', $document->data);
        $this->assertStringContainsString('drive/v3/files/1eN-CHE5oC_6CxPn7NXnRX25S7RYrn8dE',
            $transport->calls[1]['url'], 'eerst de gegevens van het bestand ophalen');
        $this->assertSame('Bearer ya29.test', $transport->calls[2]['headers']['Authorization']);
    }

    /**
     * De grens die dit in het echt liet stuklopen.
     *
     * De gewone export van Drive stopt bij tien megabyte, en een
     * repetitiescript met veertig schermafdrukken zit daar zo overheen. Het
     * adres uit exportLinks kent die grens niet — dat is hetzelfde adres dat
     * Google Docs zelf gebruikt bij Downloaden. Dus pakken we dat, en komen
     * we aan die limiet niet eens toe.
     */
    public function testALargeDocumentGoesThroughTheExportLinkInsteadOfTheTenMegabyteExport(): void
    {
        $link = 'https://docs.google.com/feeds/download/documents/export/Export'
            . '?id=1eN-CHE5oC_6CxPn7NXnRX25S7RYrn8dE&exportFormat=docx';
        $transport = new FakeTransport([
            new Response(200, (string) json_encode(['access_token' => 'ya29.t', 'expires_in' => 3600])),
            new Response(200, (string) json_encode([
                'name' => 'Groot script',
                'mimeType' => 'application/vnd.google-apps.document',
                'exportLinks' => [
                    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => $link,
                ],
            ])),
            new Response(200, 'PK' . str_repeat('y', 5000)),
        ]);
        $docs = new GoogleDocs(
            ServiceAccount::fromKeyfile($this->writeKeyfile($this->directory . '/k.json'), $transport),
            $transport,
            new Retry(6, static function (): void {})
        );

        $document = $docs->download('1eN-CHE5oC_6CxPn7NXnRX25S7RYrn8dE');

        $this->assertSame($link, $transport->calls[2]['url'],
            'het exportadres van Google zelf, niet files/export met zijn 10 MB-grens');
        $this->assertStringStartsWith('PK', $document->data);
        $this->assertSame('Groot script', $document->title);
    }

    /** Een .docx dat al in Drive staat hoeft niet geëxporteerd te worden. */
    public function testAWordFileInDriveIsDownloadedAsIs(): void
    {
        $transport = new FakeTransport([
            new Response(200, (string) json_encode(['access_token' => 'ya29.t', 'expires_in' => 3600])),
            new Response(200, (string) json_encode([
                'name' => 'Aangeleverd script.docx',
                'mimeType' => 'application/vnd.openxmlformats-officedocument'
                    . '.wordprocessingml.document',
            ])),
            new Response(200, 'PK' . str_repeat('z', 40)),
        ]);
        $docs = new GoogleDocs(
            ServiceAccount::fromKeyfile($this->writeKeyfile($this->directory . '/k.json'), $transport),
            $transport,
            new Retry(6, static function (): void {})
        );

        $document = $docs->download('1eN-CHE5oC_6CxPn7NXnRX25S7RYrn8dE');

        $this->assertStringContainsString('alt=media', $transport->calls[2]['url'],
            'gewoon ophalen, er valt niets te exporteren');
        $this->assertSame('Aangeleverd script.docx', $document->title);
    }

    /** Zonder exportadressen blijft files/export over, als vangnet. */
    public function testWithoutExportLinksItFallsBackToTheRegularExport(): void
    {
        $transport = new FakeTransport([
            new Response(200, (string) json_encode(['access_token' => 'ya29.t', 'expires_in' => 3600])),
            new Response(200, (string) json_encode([
                'name' => 'Script',
                'mimeType' => 'application/vnd.google-apps.document',
            ])),
            new Response(200, 'PK' . str_repeat('x', 40)),
        ]);
        $docs = new GoogleDocs(
            ServiceAccount::fromKeyfile($this->writeKeyfile($this->directory . '/k.json'), $transport),
            $transport,
            new Retry(6, static function (): void {})
        );

        $docs->download('1eN-CHE5oC_6CxPn7NXnRX25S7RYrn8dE');

        $this->assertStringContainsString('/export?mimeType=', $transport->calls[2]['url']);
    }

    /** Zonder sleutel blijft het de openbare weg, precies zoals voorheen. */
    public function testWithoutAKeyTheOldPublicRouteIsUsed(): void
    {
        $transport = new FakeTransport([
            new Response(200, 'PK' . str_repeat('x', 40),
                ['content-disposition' => 'attachment; filename="Script.docx"']),
        ]);
        $docs = new GoogleDocs(null, $transport, new Retry(6, static function (): void {}));

        $document = $docs->download('1eN-CHE5oC_6CxPn7NXnRX25S7RYrn8dE');

        $this->assertSame('Script', $document->title);
        $this->assertStringContainsString('docs.google.com', $transport->calls[0]['url']);
        $this->assertArrayNotHasKey('Authorization', $transport->calls[0]['headers']);
    }

    /**
     * De melding die je verder helpt: welk adres moet je toevoegen onder Delen.
     */
    public function testNoAccessNamesTheServiceAccountAddress(): void
    {
        $transport = new FakeTransport([
            new Response(200, (string) json_encode(['access_token' => 'ya29.test', 'expires_in' => 3600])),
            new Response(404, '{"error":{"code":404}}'),
        ]);
        $docs = new GoogleDocs(
            ServiceAccount::fromKeyfile($this->writeKeyfile($this->directory . '/k.json'), $transport),
            $transport,
            new Retry(6, static function (): void {})
        );

        $this->expectException(DocumentAccessException::class);
        $this->expectExceptionMessageMatches('~titelaar@titelaar\.iam\.gserviceaccount\.com~');
        $docs->download('1eN-CHE5oC_6CxPn7NXnRX25S7RYrn8dE');
    }

    public function testAnHtmlLoginPageIsNotMistakenForADocument(): void
    {
        $transport = new FakeTransport([
            new Response(200, '<html>Sign in</html>', ['content-type' => 'text/html; charset=utf-8']),
        ]);
        $docs = new GoogleDocs(null, $transport, new Retry(6, static function (): void {}));

        $this->expectException(DocumentAccessException::class);
        $docs->download('1eN-CHE5oC_6CxPn7NXnRX25S7RYrn8dE');
    }

    public function testAnUnreachableGoogleIsReportedAsSuch(): void
    {
        // Twee keer, want een naamfout is tijdelijk genoeg om het nog eens te
        // proberen — en blijft het dan misgaan, dan is dát de melding.
        $transport = new FakeTransport([
            new Response(0, '', [], 'Could not resolve host'),
            new Response(0, '', [], 'Could not resolve host'),
        ]);
        $docs = new GoogleDocs(null, $transport, new Retry(2, static function (): void {}));

        $this->expectException(GoogleDocsException::class);
        $this->expectExceptionMessageMatches('~niet bereiken~');
        $docs->download('1eN-CHE5oC_6CxPn7NXnRX25S7RYrn8dE');
    }

    /** @return array<string, mixed> */
    private static function decode(string $segment): array
    {
        $json = base64_decode(strtr($segment, '-_', '+/'), true);
        return (array) json_decode((string) $json, true);
    }
}

/** Een Transport die antwoorden uit een lijstje geeft en onthoudt wat er gevraagd is. */
final class FakeTransport implements Transport
{
    /** @var array<int, array{url: string, headers: array<string,string>, form: ?array<string,string>}> */
    public array $calls = [];

    /** @param Response[] $responses */
    public function __construct(private array $responses)
    {
    }

    public function send(string $url, array $headers = [], ?array $form = null): Response
    {
        $this->calls[] = ['url' => $url, 'headers' => $headers, 'form' => $form];
        return array_shift($this->responses)
            ?? new Response(500, 'de test had hier geen antwoord voor klaarstaan');
    }
}
