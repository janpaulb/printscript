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
        /** Opgehaald met een serviceaccount? Dan hoefde het document niet openbaar. */
        public readonly bool $signedIn = false,
    ) {
    }

    /** Dezelfde uitkomst, met een waarschuwing erbij. */
    public function withWarning(string $warning): self
    {
        return $this->with(warnings: array_values(array_unique([...$this->warnings, $warning])));
    }

    /** Dezelfde uitkomst, met de aantekening dat we ingelogd waren. */
    public function withSignedIn(): self
    {
        return $this->with(signedIn: true);
    }

    /** @param ?string[] $warnings */
    private function with(?array $warnings = null, ?bool $signedIn = null): self
    {
        return new self(
            pdf: $this->pdf,
            filename: $this->filename,
            pageCount: $this->pageCount,
            imagesRemoved: $this->imagesRemoved,
            report: $this->report,
            warnings: $warnings ?? $this->warnings,
            engine: $this->engine,
            signedIn: $signedIn ?? $this->signedIn,
        );
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
            'signed_in' => $this->signedIn,
            'warnings' => array_values($this->warnings),
        ];
    }
}
