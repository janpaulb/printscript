<?php

declare(strict_types=1);

namespace PrintScript\Google;

/** Wat er van Google terugkwam. */
final class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers = [],
        /** Gezet als de verbinding zelf misging; dan is status 0. */
        public readonly ?string $networkError = null,
    ) {
    }

    public function header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }
}
