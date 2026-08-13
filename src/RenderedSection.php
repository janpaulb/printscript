<?php

declare(strict_types=1);

namespace PrintScript;

/**
 * Eén sectie: een aaneengesloten stuk document met een eigen paginaformaat,
 * eigen marges en eigen kop- en voetteksten.
 */
final class RenderedSection
{
    public function __construct(
        public string $html,
        public readonly float $width,
        public readonly float $height,
        public readonly float $marginTop,
        public readonly float $marginRight,
        public readonly float $marginBottom,
        public readonly float $marginLeft,
        public readonly float $marginHeader,
        public readonly float $marginFooter,
        public readonly ?string $header = null,
        public readonly ?string $footer = null,
        public readonly ?string $firstHeader = null,
        public readonly ?string $firstFooter = null,
        public readonly ?string $evenHeader = null,
        public readonly ?string $evenFooter = null,
        public readonly bool $titlePage = false,
        public readonly bool $evenAndOddHeaders = false,
        public readonly string $breakBefore = 'none',
        public readonly int $columns = 1,
    ) {
    }

    public function isLandscape(): bool
    {
        return $this->width > $this->height;
    }
}
