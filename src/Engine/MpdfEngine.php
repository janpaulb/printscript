<?php

declare(strict_types=1);

namespace PrintScript\Engine;

use Mpdf\Mpdf;
use PrintScript\HtmlRenderer;
use PrintScript\Options;
use PrintScript\RenderedDocument;
use PrintScript\RenderedSection;

/**
 * De motor die overal draait: mPDF, pure PHP.
 *
 * De regel "geen afbeeldingen na pagina 1" gaat over het *geprinte* document,
 * niet over de opmaakcode. Op welke pagina een afbeelding belandt, weet je pas
 * als het document is opgemaakt. Daarom maakt deze motor het document twee
 * keer op: de eerste keer met een bladwijzer bij elke afbeelding, want mPDF
 * onthoudt van bladwijzers op welke pagina ze staan. Alles vanaf pagina 2 gaat
 * eruit, en dan nog een keer.
 *
 * Die tweede ronde is veilig: weghalen kan later materiaal alleen naar vóren
 * trekken, dus wat op pagina 1 stond blijft daar en er kan niets bijkomen.
 */
final class MpdfEngine implements EngineInterface
{
    public function __construct(private ?string $temporaryDirectory = null)
    {
    }

    public function isAvailable(): bool
    {
        return class_exists(Mpdf::class);
    }

    public function name(): string
    {
        return 'mpdf';
    }

    public function render(RenderedDocument $document, Options $options): EngineResult
    {
        $withBookmarks = $options->imagesFirstPageOnly && $document->imageIds !== [];
        $html = $this->buildHtml($document, $options, $withBookmarks);

        $first = $this->run($html, $document);
        $imagesRemoved = 0;

        if ($withBookmarks) {
            $doomed = $this->imagesBeyondFirstPage($first['bookmarks'], $document->imageIds);
            if ($doomed !== []) {
                $html = $this->buildHtml($document, $options, false, $doomed);
                $imagesRemoved = count($doomed);
                $first = $this->run($html, $document);
            } else {
                // Niets te verwijderen, maar de bladwijzers moeten wel weg.
                $html = $this->buildHtml($document, $options, false);
                $first = $this->run($html, $document);
            }
        }

        return new EngineResult(
            pdf: $first['pdf'],
            pageCount: $first['pages'],
            imagesRemoved: $imagesRemoved,
            warnings: $document->warnings,
            engine: $this->name(),
        );
    }

    /** @return array{pdf: string, pages: int, bookmarks: array<string, int>} */
    private function run(string $html, RenderedDocument $document): array
    {
        $first = $document->sections[0] ?? null;

        $configuration = [
            'mode' => 'utf-8',
            'format' => [
                $this->mm($first?->width ?? 595.3),
                $this->mm($first?->height ?? 841.9),
            ],
            'margin_left'   => $this->mm($first?->marginLeft ?? 72.0),
            'margin_right'  => $this->mm($first?->marginRight ?? 72.0),
            'margin_top'    => $this->mm($first?->marginTop ?? 72.0),
            'margin_bottom' => $this->mm($first?->marginBottom ?? 72.0),
            'margin_header' => $this->mm($first?->marginHeader ?? 35.0),
            'margin_footer' => $this->mm($first?->marginFooter ?? 35.0),
        ];
        if ($this->temporaryDirectory !== null) {
            $configuration['tempDir'] = $this->temporaryDirectory;
        }

        $mpdf = new Mpdf($configuration);
        $mpdf->useSubstitutions = true;
        $mpdf->showImageErrors = false;
        $mpdf->WriteHTML($html);

        $bookmarks = [];
        foreach ($mpdf->BMoutlines as $entry) {
            if (isset($entry['t'], $entry['p'])) {
                $bookmarks[(string) $entry['t']] = (int) $entry['p'];
            }
        }

        return [
            'pdf' => (string) $mpdf->Output('', 'S'),
            'pages' => (int) $mpdf->page,
            'bookmarks' => $bookmarks,
        ];
    }

    /**
     * @param array<string, int> $bookmarks
     * @param string[] $imageIds
     * @return string[] de merktekens van de afbeeldingen die weg moeten
     */
    private function imagesBeyondFirstPage(array $bookmarks, array $imageIds): array
    {
        $doomed = [];
        foreach ($imageIds as $id) {
            // Onbekend betekent: niet opgemaakt, dus laten staan. Alleen wat
            // aantoonbaar voorbij pagina 1 ligt, gaat eruit.
            if (($bookmarks[$id] ?? 1) > 1) {
                $doomed[] = $id;
            }
        }
        return $doomed;
    }

    /** @param string[] $withoutImages */
    private function buildHtml(
        RenderedDocument $document,
        Options $options,
        bool $withBookmarks,
        array $withoutImages = []
    ): string {
        $parts = ['<style>', $document->css, $this->pageRules($document, $options), '</style>'];

        foreach ($document->sections as $index => $section) {
            $parts[] = $this->headerFooterDefinitions($index, $section, $options);
        }

        foreach ($document->sections as $index => $section) {
            if ($index > 0) {
                $parts[] = $this->sectionBreak($index, $section);
            }
            $body = $section->html;
            $body = $this->applyImageMarkers($body, $withBookmarks, $withoutImages);
            $body = str_replace(HtmlRenderer::PAGE_BREAK_MARK, '<pagebreak />', $body);
            $body = $this->substitutePageNumbers($body);
            $parts[] = $body;
        }

        return implode("\n", $parts);
    }

    /**
     * De eerste sectie krijgt zijn kop- en voetteksten via @page; de eerste
     * pagina kan daarvan afwijken als het document een titelblad heeft of als
     * de omslag geen paginanummer mag krijgen.
     */
    private function pageRules(RenderedDocument $document, Options $options): string
    {
        $section = $document->sections[0] ?? null;
        if ($section === null) {
            return '';
        }

        $rules = [];
        $default = [];
        if ($this->headerHtml($section, 'default') !== null) {
            $default[] = 'header: html_hdr0;';
        }
        if ($this->footerHtml($section, 'default', $options) !== null) {
            $default[] = 'footer: html_ftr0;';
        }
        if ($default !== []) {
            $rules[] = '@page { ' . implode(' ', $default) . ' }';
        }

        $first = [];
        if ($section->titlePage) {
            $first[] = 'header: ' . ($section->firstHeader !== null ? 'html_hdr0first;' : '_blank;');
        }
        if ($section->titlePage && $section->firstFooter !== null) {
            $first[] = 'footer: html_ftr0first;';
        } elseif (!$options->pageNumbersOnFirstPage) {
            $first[] = 'footer: html_ftr0nonum;';
        } elseif ($section->titlePage) {
            $first[] = 'footer: _blank;';
        }
        if ($first !== []) {
            $rules[] = '@page :first { ' . implode(' ', $first) . ' }';
        }

        return implode("\n", $rules);
    }

    private function headerFooterDefinitions(
        int $index,
        RenderedSection $section,
        Options $options
    ): string {
        $blocks = [];

        foreach ([
            ['hdr' . $index, 'htmlpageheader', $this->headerHtml($section, 'default')],
            ['hdr' . $index . 'first', 'htmlpageheader', $section->firstHeader],
            ['hdr' . $index . 'even', 'htmlpageheader', $section->evenHeader],
            ['ftr' . $index, 'htmlpagefooter', $this->footerHtml($section, 'default', $options)],
            ['ftr' . $index . 'first', 'htmlpagefooter', $this->footerHtml($section, 'first', $options)],
            ['ftr' . $index . 'even', 'htmlpagefooter', $this->footerHtml($section, 'even', $options)],
            ['ftr' . $index . 'nonum', 'htmlpagefooter', $this->footerWithoutPageNumbers($section, $options)],
        ] as [$name, $tag, $html]) {
            if ($html === null) {
                continue;
            }
            $blocks[] = "<$tag name=\"$name\">"
                . $this->substitutePageNumbers($html) . "</$tag>";
        }

        return implode("\n", $blocks);
    }

    private function headerHtml(RenderedSection $section, string $which): ?string
    {
        return match ($which) {
            'first' => $section->firstHeader,
            'even'  => $section->evenHeader,
            default => $section->header,
        };
    }

    /** De voettekst, met een paginanummer erbij als het document er geen heeft. */
    private function footerHtml(RenderedSection $section, string $which, Options $options): ?string
    {
        $html = match ($which) {
            'first' => $section->firstFooter,
            'even'  => $section->evenFooter,
            default => $section->footer,
        };

        if (!$options->addPageNumbers) {
            return $html;
        }
        if ($html !== null && $this->hasPageNumber($html)) {
            return $html;
        }
        if ($html === null && $which !== 'default') {
            return null;   // geen aparte voettekst voor deze variant
        }

        return ($html ?? '')
            . '<div style="text-align: center">' . HtmlRenderer::PAGE_NUMBER_MARK . '</div>';
    }

    private function footerWithoutPageNumbers(RenderedSection $section, Options $options): ?string
    {
        if ($options->pageNumbersOnFirstPage) {
            return null;
        }
        $source = $section->firstFooter ?? $section->footer;
        if ($source === null) {
            return '<div></div>';
        }
        return $this->stripPageNumbers($source);
    }

    private function sectionBreak(int $index, RenderedSection $section): string
    {
        $attributes = [
            'sheet-size' => $this->mm($section->width) . 'mm ' . $this->mm($section->height) . 'mm',
            'margin-left' => (string) $this->mm($section->marginLeft),
            'margin-right' => (string) $this->mm($section->marginRight),
            'margin-top' => (string) $this->mm($section->marginTop),
            'margin-bottom' => (string) $this->mm($section->marginBottom),
            'odd-header-name' => $section->header !== null ? "hdr$index" : '_blank',
            'odd-footer-name' => "ftr$index",
        ];
        if ($section->evenAndOddHeaders) {
            $attributes['even-header-name'] = $section->evenHeader !== null ? "hdr{$index}even" : '_blank';
            $attributes['even-footer-name'] = $section->evenFooter !== null ? "ftr{$index}even" : "ftr$index";
        }
        if ($section->breakBefore === 'left' || $section->breakBefore === 'right') {
            $attributes['type'] = $section->breakBefore === 'left' ? 'E' : 'O';
        }

        $rendered = '';
        foreach ($attributes as $name => $value) {
            $rendered .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES) . '"';
        }
        return "<pagebreak$rendered />";
    }

    /**
     * De merktekens rond afbeeldingen worden een bladwijzer (eerste ronde) of
     * verdwijnen samen met hun afbeelding (tweede ronde).
     *
     * @param string[] $withoutImages
     */
    private function applyImageMarkers(string $html, bool $withBookmarks, array $withoutImages): string
    {
        foreach ($withoutImages as $id) {
            $html = preg_replace(
                '~<!--' . preg_quote($id, '~') . '-->.*?<!--/' . preg_quote($id, '~') . '-->~s',
                '',
                $html
            ) ?? $html;
        }

        if ($withBookmarks) {
            $html = preg_replace_callback(
                '~<!--(psimg\d+)-->~',
                static fn(array $m): string => '<bookmark content="' . $m[1] . '" level="9" />',
                $html
            ) ?? $html;
        }

        // Overgebleven merktekens zijn gewoon commentaar; die mogen weg.
        $html = preg_replace('~<!--/?psimg\d+-->~', '', $html) ?? $html;

        // Een alinea die alleen een afbeelding bevatte, laat een leeg omhulsel
        // achter; dat zou een lege regel opleveren.
        return preg_replace('~<(p|h[1-6])\b[^>]*>\s*</\1>~', '', $html) ?? $html;
    }

    private function substitutePageNumbers(string $html): string
    {
        return str_replace(
            [HtmlRenderer::PAGE_NUMBER_MARK, HtmlRenderer::PAGE_COUNT_MARK],
            ['{PAGENO}', '{nbpg}'],
            $html
        );
    }

    private function stripPageNumbers(string $html): string
    {
        return str_replace(
            [HtmlRenderer::PAGE_NUMBER_MARK, HtmlRenderer::PAGE_COUNT_MARK],
            '',
            $html
        );
    }

    private function hasPageNumber(string $html): bool
    {
        return str_contains($html, HtmlRenderer::PAGE_NUMBER_MARK)
            || str_contains($html, HtmlRenderer::PAGE_COUNT_MARK);
    }

    /** mPDF rekent in millimeters, Word in punten. */
    private function mm(float $points): float
    {
        return round($points * 25.4 / 72.0, 2);
    }
}
