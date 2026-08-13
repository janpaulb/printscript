<?php

declare(strict_types=1);

namespace PrintScript\Engine;

use PrintScript\Options;
use PrintScript\RenderedDocument;

/**
 * Een PDF-motor: van het gelezen document naar bytes.
 *
 * Er zijn er twee. mPDF is pure PHP en draait overal; WeasyPrint geeft een
 * getrouwere opmaak maar moet op de server geïnstalleerd zijn. Ze delen alles
 * behalve de laatste stap.
 */
interface EngineInterface
{
    /** Draait deze motor op deze server? */
    public function isAvailable(): bool;

    /** Naam voor in de logregels en de foutmeldingen. */
    public function name(): string;

    public function render(RenderedDocument $document, Options $options): EngineResult;
}
