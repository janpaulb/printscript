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
    /** @var string[] waarschuwingen die pas bij het opmaken blijken */
    private array $warnings = [];

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
        // De afbeeldingen reizen los van de HTML mee en worden hier pas op
        // schijf gezet. Als base64 in de HTML zou één foto het document al
        // over de PCRE-limiet van mPDF duwen.
        $paths = $this->writeImages($document->images);
        $this->warnings = [];

        try {
            $withBookmarks = $options->imagesFirstPageOnly && $document->imageIds !== [];
            $html = $this->buildHtml($document, $options, $withBookmarks, [], $paths);

            $result = $this->run($html, $document);
            $imagesRemoved = 0;

            if ($withBookmarks) {
                $doomed = $this->imagesBeyondFirstPage($result['bookmarks'], $document->imageIds);
                $imagesRemoved = count($doomed);
                // Tweede ronde: zonder bladwijzers, en zonder wat weg moest.
                $html = $this->buildHtml($document, $options, false, $doomed, $paths);
                $result = $this->run($html, $document);
            }

            return new EngineResult(
                pdf: $result['pdf'],
                pageCount: $result['pages'],
                imagesRemoved: $imagesRemoved,
                warnings: array_merge($document->warnings, $this->warnings),
                engine: $this->name(),
            );
        } finally {
            foreach ($paths as $path) {
                @unlink($path);
            }
        }
    }

    /**
     * Zet de afbeeldingen als tijdelijke bestanden neer.
     *
     * @param array<string, array{data: string, mime: string}> $images
     * @return array<string, string> merkteken => bestandspad
     */
    private function writeImages(array $images): array
    {
        $paths = [];
        $directory = $this->temporaryDirectory ?? sys_get_temp_dir();

        foreach ($images as $token => $image) {
            $extension = match ($image['mime']) {
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
                'image/gif' => 'gif',
                'image/bmp' => 'bmp',
                'image/webp' => 'webp',
                'image/svg+xml' => 'svg',
                default => 'img',
            };
            $path = tempnam($directory, 'psimg');
            if ($path === false) {
                throw new \RuntimeException(
                    'De server kan geen tijdelijke bestanden schrijven, dus '
                    . 'afbeeldingen kunnen niet worden verwerkt.'
                );
            }
            $named = $path . '.' . $extension;
            if (@rename($path, $named)) {
                $path = $named;
            }
            file_put_contents($path, $image['data']);
            $paths[$token] = $path;
        }
        return $paths;
    }

    /** @return array{pdf: string, pages: int, bookmarks: array<string, int>} */
    private function run(string $html, RenderedDocument $document): array
    {
        $first = $document->sections[0] ?? null;

        $configuration = Fonts::configuration() + [
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

        $this->raiseBacktrackLimit($html);

        $mpdf = new Mpdf($configuration);
        // Namen uit Word vertalen naar de lettertypen die we werkelijk hebben.
        $mpdf->fonttrans = array_merge($mpdf->fonttrans, Fonts::translations());
        $mpdf->useSubstitutions = true;
        $mpdf->showImageErrors = false;
        // Onderkasting, zoals een tekstverwerker het doet: de "P," in "SGP,"
        // schuift een fractie naar elkaar toe. Over een regel loopt dat op
        // tot een woord, en dan breekt de regel een woord eerder af dan in
        // het origineel.
        $mpdf->useKerning = true;
        $this->startPageNumbering($mpdf, $first?->pageNumberStart);
        $mpdf->WriteHTML($html);
        $this->reportUnprintableCharacters($mpdf, $html);

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

    /**
     * @param string[] $withoutImages
     * @param array<string, string> $imagePaths merkteken => bestandspad
     */
    private function buildHtml(
        RenderedDocument $document,
        Options $options,
        bool $withBookmarks,
        array $withoutImages = [],
        array $imagePaths = []
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

        $html = implode("\n", $parts);

        // De merktekens in de src worden nu pas echte paden.
        if ($imagePaths !== []) {
            $html = strtr($html, $imagePaths);
        }
        return $html;
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

        if (!$options->addPageNumbers || $this->numbersItself($section)) {
            // Het document nummert zichzelf. Dan is een lege voettekst op de
            // omslag geen omissie maar een keuze, en die respecteren we.
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

    /**
     * Zeggen welke tekens er niet op papier komen.
     *
     * De meegeleverde lettertypen dekken het Latijnse, Griekse en Cyrillische
     * schrift plus de gangbare leestekens en symbolen. Een Chinees teken, een
     * emoji in een regieaanwijzing: die staan in geen van de lettertypen, en
     * dan drukt een PDF een leeg vakje af. Zonder dit bericht zou de gebruiker
     * dat vakje pas op papier zien en niet weten waar het vandaan komt.
     *
     * De vraag "kan dit teken?" wordt aan mPDF zelf gesteld, met de tabel
     * waarmee hij hem ook zou zetten — geen lijstje met schriften dat na de
     * eerste de beste wijziging niet meer klopt.
     */
    private function reportUnprintableCharacters(Mpdf $mpdf, string $html): void
    {
        $text = preg_replace('~<style\b[^>]*>.*?</style>~is', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $candidates = [];
        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
            $code = mb_ord($character, 'UTF-8');
            // Onder U+0300 zit alleen Latijn en leestekens; dat kan alles.
            if ($code !== false && $code >= 0x0300) {
                $candidates[$code] = $character;
            }
        }
        if ($candidates === []) {
            return;
        }

        foreach ($this->substitutionFonts($mpdf) as $widths) {
            foreach (array_keys($candidates) as $code) {
                // Zo leest mPDF zijn eigen breedtetabel: een breedte van nul
                // betekent dat het teken er niet in zit.
                if (isset($widths[$code * 2 + 1])
                    && ((ord($widths[$code * 2]) << 8) + ord($widths[$code * 2 + 1])) > 0) {
                    unset($candidates[$code]);
                }
            }
            if ($candidates === []) {
                return;
            }
        }

        $shown = array_slice($candidates, 0, 6);
        $this->warn(sprintf(
            'Deze tekens staan in geen van de meegeleverde lettertypen en komen '
            . 'als leeg vakje op papier: %s%s. Chinees, Japans, Koreaans en '
            . 'emoji vallen buiten wat PrintScript kan zetten.',
            implode(' ', $shown),
            count($candidates) > count($shown)
                ? sprintf(' (en nog %d andere)', count($candidates) - count($shown))
                : ''
        ));
    }

    /**
     * De breedtetabellen van de lettertypen waar mPDF een teken in kan zoeken:
     * wat hij al geladen heeft, plus zijn terugvallijst.
     *
     * @return iterable<string>
     */
    private function substitutionFonts(Mpdf $mpdf): iterable
    {
        $families = array_unique(array_merge(
            array_keys($mpdf->fonts),
            (array) $mpdf->backupSubsFont
        ));

        foreach ($families as $family) {
            if (!isset($mpdf->fonts[$family])) {
                try {
                    $mpdf->AddFont($family);
                } catch (\Throwable) {
                    continue;   // ontbreekt: dan telt hij gewoon niet mee
                }
            }
            $widths = $mpdf->fonts[$family]['cw'] ?? null;
            if (is_string($widths) && $widths !== '') {
                yield $widths;
            }
        }
    }

    private function warn(string $message): void
    {
        if (!in_array($message, $this->warnings, true)) {
            $this->warnings[] = $message;
        }
    }

    /**
     * De nummering laten beginnen waar het document zegt dat ze begint.
     *
     * Een script met een omslag zet de teller vaak op nul, zodat het
     * titelblad niet meetelt en de eerste bladzijde tekst pagina 1 is. mPDF
     * kent daar één regel voor: vanaf pagina X telt de nummering opnieuw
     * vanaf N. Voor "begin bij nul" is dat: vanaf pagina twee is het pagina
     * één — precies hetzelfde, en de omslag houdt zijn eigen voettekst.
     */
    private function startPageNumbering(Mpdf $mpdf, ?int $start): void
    {
        if ($start === null || $start === 1) {
            return;
        }
        $mpdf->PageNumSubstitutions[] = $start < 1
            ? ['from' => 2 - $start, 'reset' => 1, 'type' => '1', 'suppress' => 'off']
            : ['from' => 1, 'reset' => $start, 'type' => '1', 'suppress' => 'off'];
    }

    private function hasPageNumber(string $html): bool
    {
        return str_contains($html, HtmlRenderer::PAGE_NUMBER_MARK)
            || str_contains($html, HtmlRenderer::PAGE_COUNT_MARK);
    }

    /** Staat er ergens in de kop- of voetteksten van deze sectie al een teller? */
    private function numbersItself(RenderedSection $section): bool
    {
        foreach ([
            $section->footer, $section->firstFooter, $section->evenFooter,
            $section->header, $section->firstHeader, $section->evenHeader,
        ] as $html) {
            if ($html !== null && $this->hasPageNumber($html)) {
                return true;
            }
        }
        return false;
    }

    /**
     * mPDF weigert HTML die groter is dan pcre.backtrack_limit — een grens die
     * standaard op 1 MB staat. De afbeeldingen zitten er niet meer in, maar een
     * lang script haalt die grens met tekst alleen ook.
     */
    private function raiseBacktrackLimit(string $html): void
    {
        // Op een gedeelde hosting staan ini_get en ini_set geregeld in
        // disable_functions. Ze zomaar aanroepen is dan een fatale fout, en
        // dan valt de conversie om over iets heel anders dan waar het om ging.
        if (!function_exists('ini_get') || !function_exists('ini_set')) {
            return;
        }

        $needed = strlen($html) + 1024 * 1024;
        $current = (int) ini_get('pcre.backtrack_limit');
        if ($current >= $needed) {
            return;
        }
        if (@ini_set('pcre.backtrack_limit', (string) $needed) === false) {
            throw new \RuntimeException(sprintf(
                'Dit document levert %d MB aan opmaak op, meer dan de grens van '
                . '%d MB die deze server toestaat (pcre.backtrack_limit), en die '
                . 'grens mag hier niet verhoogd worden. Vraag je hostingpartij om '
                . 'pcre.backtrack_limit te verhogen.',
                intdiv(strlen($html), 1024 * 1024) + 1,
                intdiv($current, 1024 * 1024)
            ));
        }
    }

    /** mPDF rekent in millimeters, Word in punten. */
    private function mm(float $points): float
    {
        return round($points * 25.4 / 72.0, 2);
    }
}
