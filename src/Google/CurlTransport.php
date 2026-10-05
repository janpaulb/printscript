<?php

declare(strict_types=1);

namespace PrintScript\Google;

/** Het echte netwerk. Alles wat hier gebeurt, doen de tests na met een eigen Transport. */
final class CurlTransport implements Transport
{
    public const CONNECT_TIMEOUT = 10;
    public const TIMEOUT = 30;

    private const USER_AGENT = 'PrintScript/3.0 (+https://github.com/janpaulb/printscript)';

    public function __construct(
        private readonly int $maximumBytes = 50 * 1024 * 1024,
        private readonly int $timeout = self::TIMEOUT,
    ) {
    }

    public function send(string $url, array $headers = [], ?array $form = null): Response
    {
        if (!function_exists('curl_init')) {
            return new Response(0, '', [], 'de curl-uitbreiding van PHP ontbreekt');
        }

        $received = [];
        $handle = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_USERAGENT => self::USER_AGENT,
            CURLOPT_HTTPHEADER => array_map(
                static fn(string $name, string $value): string => "$name: $value",
                array_keys($headers),
                array_values($headers)
            ),
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$received): int {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $received[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION =>
                fn($handle, $expected, $downloaded): int => $downloaded > $this->maximumBytes ? 1 : 0,
        ];
        if ($form !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = http_build_query($form);
        }
        curl_setopt_array($handle, $options);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $number = curl_errno($handle);
        $message = curl_error($handle);
        curl_close($handle);

        if ($number === CURLE_ABORTED_BY_CALLBACK) {
            return new Response(0, '', $received, sprintf(
                'het document is groter dan de limiet van %d MB',
                intdiv($this->maximumBytes, 1024 * 1024)
            ));
        }
        if ($body === false) {
            return new Response(0, '', $received, $message !== '' ? $message : 'onbekende fout');
        }

        return new Response($status, (string) $body, $received);
    }
}
