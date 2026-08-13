<?php

declare(strict_types=1);

namespace PrintScript;

/**
 * Het document nadat het uit Word is gelezen, maar voordat er een PDF van is
 * gemaakt: gedeelde opmaak, en per sectie de inhoud met zijn eigen
 * paginaformaat en kop- en voetteksten.
 *
 * De motoren (mPDF, WeasyPrint) zetten dit ieder op hun eigen manier om. Wat
 * ze gemeen hebben staat hier; waar ze uiteenlopen, staat bij hen.
 */
final class RenderedDocument
{
    /**
     * @param RenderedSection[] $sections
     * @param string[] $warnings
     * @param string[] $imageIds merktekens van afbeeldingen in de lopende tekst
     * @param array<string, array{data: string, mime: string}> $images de
     *        afbeeldingen zelf, gesleuteld op het merkteken dat in de src
     *        staat. Ze staan met opzet niet in de HTML: als base64 zou één
     *        foto de HTML al over de PCRE-limiet van mPDF duwen.
     */
    public function __construct(
        public readonly string $css,
        public readonly array $sections,
        public readonly array $warnings = [],
        public readonly array $imageIds = [],
        public readonly array $images = [],
    ) {
    }
}
