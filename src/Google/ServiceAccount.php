<?php

declare(strict_types=1);

namespace PrintScript\Google;

/**
 * Inloggen als serviceaccount.
 *
 * Google wil een JWT die je met de privésleutel ondertekent, en geeft daar
 * een toegangstoken voor terug dat een uur meegaat. Dat is alles. PHP kan het
 * ondertekenen zelf met openssl_sign, dus dat doen we hier met de hand.
 *
 * Dat is geen koppigheid. De Google-clientbibliotheek is tientallen
 * megabytes, en vendor/ gaat bij dit project mee de hosting op — we hebben
 * die map met moeite op dertig megabyte gekregen juist omdat een gedeelde
 * hosting geen Composer heeft. Veertig regels hieronder wegen lichter dan dat.
 */
final class ServiceAccount
{
    /**
     * Een .docx exporteren gaat via Drive, en Drive wil zijn eigen recht.
     *
     * Niet documents.readonly: dat is voor het lezen van de inhoud als JSON.
     * Printscript wil het document als bestand, met opmaak en al, en dat is
     * wat Drive's export levert.
     */
    public const SCOPE = 'https://www.googleapis.com/auth/drive.readonly';

    /** Een minuut speling, zodat een klok die iets voorloopt niets breekt. */
    private const EARLY = 60;

    private ?string $token = null;
    private int $expiresAt = 0;

    /** @param array<string, string> $keys */
    public function __construct(
        private readonly array $keys,
        private readonly Transport $transport = new CurlTransport(),
        private readonly string $scope = self::SCOPE,
    ) {
    }

    public static function fromKeyfile(
        string $path,
        Transport $transport = new CurlTransport(),
    ): self {
        return new self(Keyfile::read($path), $transport);
    }

    /** Het adres waarmee een document gedeeld moet zijn. */
    public function clientEmail(): string
    {
        return $this->keys['client_email'] ?? '';
    }

    /**
     * Een geldig toegangstoken, uit het geheugen als dat nog kan.
     *
     * Bewust alleen in het geheugen: een token is net zo goed een sleutel, en
     * die hoort niet in een tijdelijk bestand op een gedeelde server te
     * belanden. Eén conversie haalt één document op, dus het kost één extra
     * verzoek.
     */
    public function accessToken(): string
    {
        if ($this->token !== null && time() < $this->expiresAt - self::EARLY) {
            return $this->token;
        }

        $response = $this->transport->send(
            $this->keys['token_uri'],
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->assertion(),
            ],
        );

        if ($response->networkError !== null) {
            throw new GoogleAuthException(
                'Kan Google niet bereiken om in te loggen: ' . $response->networkError
            );
        }

        $payload = json_decode($response->body, true);
        $payload = is_array($payload) ? $payload : [];

        if ($response->status !== 200 || !isset($payload['access_token'])) {
            throw new GoogleAuthException($this->explain($response->status, $payload));
        }

        $this->token = (string) $payload['access_token'];
        $this->expiresAt = time() + (int) ($payload['expires_in'] ?? 3600);

        return $this->token;
    }

    /** De ondertekende JWT waarmee we ons bij Google melden. */
    private function assertion(): string
    {
        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        if (isset($this->keys['private_key_id'])) {
            $header['kid'] = $this->keys['private_key_id'];
        }

        $claims = [
            'iss' => $this->keys['client_email'],
            'scope' => $this->scope,
            'aud' => $this->keys['token_uri'],
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $unsigned = self::base64url(self::json($header))
            . '.' . self::base64url(self::json($claims));

        $key = @openssl_pkey_get_private($this->keys['private_key']);
        if ($key === false) {
            throw new GoogleAuthException(
                'De privésleutel in het sleutelbestand is onbruikbaar. Is het bestand '
                . 'compleet overgenomen, inclusief de regels BEGIN en END PRIVATE KEY?'
            );
        }

        $signature = '';
        $signed = openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256);
        if (!$signed) {
            throw new GoogleAuthException('De aanvraag kon niet ondertekend worden.');
        }

        return $unsigned . '.' . self::base64url($signature);
    }

    /** @param array<string, mixed> $payload */
    private function explain(int $status, array $payload): string
    {
        $reason = (string) ($payload['error_description'] ?? $payload['error'] ?? '');

        // Dit is de fout die iedereen een keer maakt, dus die krijgt een uitleg
        // in plaats van Googles eigen formulering.
        if (str_contains($reason, 'Invalid JWT Signature') || str_contains($reason, 'invalid_grant')) {
            return sprintf(
                'Google weigert de sleutel van %s (%s). Is de sleutel ingetrokken of '
                . 'vervangen? Maak in dat geval een nieuwe aan en zet die op de plek '
                . 'van het sleutelbestand.',
                $this->clientEmail(),
                $reason !== '' ? $reason : "HTTP $status"
            );
        }

        return sprintf(
            'Inloggen bij Google lukte niet (HTTP %d)%s.',
            $status,
            $reason !== '' ? ": $reason" : ''
        );
    }

    /** @param array<string, mixed> $value */
    private static function json(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
