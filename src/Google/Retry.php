<?php

declare(strict_types=1);

namespace PrintScript\Google;

/**
 * Opnieuw proberen, maar alleen waar dat zin heeft.
 *
 * Een 503 van Google is bijna altijd van voorbijgaande aard; een 403 niet.
 * Daar nog vijf keer achteraan gaan maakt het alleen trager en zet de teller
 * bij Google verder op. Dezelfde verdeling die numberto hanteert, zodat beide
 * gereedschappen zich hetzelfde gedragen als Google hapert.
 */
final class Retry
{
    public const STATUSES = [408, 429, 500, 502, 503, 504];

    /** De netwerkfouten die een tweede poging verdienen. */
    public const NETWORK = [
        'ECONNRESET', 'ETIMEDOUT', 'ECONNREFUSED', 'EAI_AGAIN',
        'ENETUNREACH', 'ENOTFOUND', 'EPIPE',
        // Wat curl ervan maakt, in zijn eigen woorden.
        'Connection reset', 'Operation timed out', 'Connection refused',
        'Could not resolve host', 'Resolving timed out', 'Network is unreachable',
        'Broken pipe', 'Empty reply',
    ];

    public const ATTEMPTS = 6;
    private const FIRST_WAIT_MS = 200;
    private const LONGEST_WAIT_MS = 5000;

    /** @var callable(int): void */
    private $sleeper;

    /** @param ?callable(int): void $sleeper wachten in microseconden; de tests slaan dat over */
    public function __construct(
        private readonly int $attempts = self::ATTEMPTS,
        ?callable $sleeper = null,
    ) {
        $this->sleeper = $sleeper ?? static function (int $microseconds): void {
            usleep($microseconds);
        };
    }

    /** @param callable(): Response $call */
    public function run(callable $call): Response
    {
        $response = $call();

        for ($attempt = 1; $attempt < $this->attempts; $attempt++) {
            if (!self::worthRepeating($response)) {
                return $response;
            }
            ($this->sleeper)(self::waitAfter($attempt) * 1000);
            $response = $call();
        }

        return $response;
    }

    public static function worthRepeating(Response $response): bool
    {
        if ($response->networkError !== null) {
            foreach (self::NETWORK as $fault) {
                if (stripos($response->networkError, $fault) !== false) {
                    return true;
                }
            }
            return false;
        }
        return in_array($response->status, self::STATUSES, true);
    }

    /** 200, 400, 800 … tot vijf seconden. */
    public static function waitAfter(int $attempt): int
    {
        return (int) min(self::FIRST_WAIT_MS * 2 ** ($attempt - 1), self::LONGEST_WAIT_MS);
    }
}
