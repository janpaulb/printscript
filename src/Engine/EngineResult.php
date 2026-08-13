<?php

declare(strict_types=1);

namespace PrintScript\Engine;

/** De uitkomst van een opmaakronde. */
final class EngineResult
{
    /** @param string[] $warnings */
    public function __construct(
        public readonly string $pdf,
        public readonly int $pageCount,
        public readonly int $imagesRemoved = 0,
        public readonly array $warnings = [],
        public readonly string $engine = '',
    ) {
    }
}
