<?php

declare(strict_types=1);

namespace PrintScript;

/** Wat er uit de pijplijn komt: de PDF plus wat er onderweg is gebeurd. */
final class ConversionResult
{
    /** @param string[] $warnings */
    public function __construct(
        public readonly string $pdf,
        public readonly string $filename,
        public readonly int $pageCount,
        public readonly int $imagesRemoved,
        public readonly CleanReport $report,
        public readonly array $warnings = [],
        public readonly string $engine = '',
    ) {
    }

    /** Beknopt verslag voor de webinterface. */
    public function summary(): array
    {
        return [
            'filename' => $this->filename,
            'pages' => $this->pageCount,
            'images_removed' => $this->imagesRemoved,
            'comment_markers_removed' => $this->report->commentMarkersRemoved,
            'highlighting_removed' => $this->report->totalHighlightingRemoved(),
            'engine' => $this->engine,
            'warnings' => array_values($this->warnings),
        ];
    }
}
