<?php

declare(strict_types=1);

namespace PrintScript;

/** Wat er tijdens het schoonmaken is weggehaald. */
final class CleanReport
{
    public function __construct(
        public int $commentMarkersRemoved = 0,
        public int $commentPartsRemoved = 0,
        public int $highlightsRemoved = 0,
        public int $shadingsRemoved = 0,
    ) {
    }

    public function totalHighlightingRemoved(): int
    {
        return $this->highlightsRemoved + $this->shadingsRemoved;
    }
}
