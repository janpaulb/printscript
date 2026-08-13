<?php

declare(strict_types=1);

namespace PrintScript\Tests;

use PHPUnit\Framework\TestCase;
use PrintScript\Clean;
use PrintScript\Options;
use PrintScript\Package;
use PrintScript\Pipeline;

/**
 * De vier drukregels, van begin tot eind gecontroleerd in de PDF.
 *
 * Dit zijn de tests die ertoe doen: ze kijken niet of een functie is
 * aangeroepen, maar wat er op papier verschijnt.
 */
final class SpecsTest extends TestCase
{
    private function convert(DocxBuilder $builder, string $body, ?Options $options = null): array
    {
        $pdf = (new Pipeline())->convertDocx(
            $builder->build($body),
            $options ?? new Options(),
            'Testscript'
        );
        return [$pdf, new PdfInspector($pdf->pdf)];
    }

    // ── 1. Opmerkingen ───────────────────────────────────────────────────────

    public function testCommentTextNeverReachesThePdf(): void
    {
        $builder = new DocxBuilder();
        $builder->addComments(DocxBuilder::commentsPart('GEHEIME REDACTIENOTITIE'));
        $body = DocxBuilder::paragraph('Gewone zin.')
            . DocxBuilder::commentedParagraph('Zin met opmerking.')
            . DocxBuilder::SECTION;

        [$result, $pdf] = $this->convert($builder, $body);

        $this->assertStringNotContainsString('GEHEIME REDACTIENOTITIE', $pdf->text());
        $this->assertStringContainsString('Zin met opmerking.', $pdf->text());
        $this->assertGreaterThanOrEqual(3, $result->report->commentMarkersRemoved);
        $this->assertSame(1, $result->report->commentPartsRemoved);
    }

    public function testCommentPartsAreDroppedFromThePackage(): void
    {
        $builder = new DocxBuilder();
        $builder->addComments(DocxBuilder::commentsPart('weg hiermee'));
        $package = new Package($builder->build(
            DocxBuilder::commentedParagraph('Tekst') . DocxBuilder::SECTION
        ));

        $this->assertGreaterThan(0, Clean::countCommentMarkers($package));
        Clean::clean($package);

        $this->assertSame(0, Clean::countCommentMarkers($package));
        $this->assertFalse($package->hasPart('word/comments.xml'));
    }

    // ── 2. Markeringen ───────────────────────────────────────────────────────

    public function testHighlightingIsRemovedAndTextColourSurvives(): void
    {
        $builder = new DocxBuilder();
        $body = DocxBuilder::paragraph('Gemarkeerd rood', null,
                '<w:highlight w:val="yellow"/><w:color w:val="FF0000"/>')
            . DocxBuilder::paragraph('Google-arcering', null,
                '<w:shd w:val="clear" w:fill="FFFF00"/>')
            . DocxBuilder::SECTION;

        [$result, $pdf] = $this->convert($builder, $body);

        $this->assertSame(1, $result->report->highlightsRemoved);
        $this->assertSame(1, $result->report->shadingsRemoved);
        $this->assertStringContainsString('Gemarkeerd rood', $pdf->text());

        // De tekstkleur blijft; de achtergrond verdwijnt.
        $package = new Package($builder->build($body));
        Clean::clean($package);
        $document = (string) $package->document('word/document.xml')?->saveXML();
        $this->assertStringContainsString('FF0000', $document);
        $this->assertStringNotContainsString('highlight', $document);
        $this->assertStringNotContainsString('FFFF00', $document);
    }

    public function testHighlightingInsideAStyleIsRemovedToo(): void
    {
        $builder = new DocxBuilder();
        $builder->setStyles('<w:styles ' . DocxBuilder::NS . '>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal">'
            . '<w:name w:val="Normal"/></w:style>'
            . '<w:style w:type="character" w:styleId="Marker"><w:name w:val="Marker"/>'
            . '<w:rPr><w:highlight w:val="green"/></w:rPr></w:style></w:styles>');
        $body = DocxBuilder::paragraph('Via stijl gemarkeerd', null,
            '<w:rStyle w:val="Marker"/>') . DocxBuilder::SECTION;

        [$result] = $this->convert($builder, $body);

        $this->assertSame(1, $result->report->highlightsRemoved);
    }

    // ── 3. Afbeeldingen na pagina 1 ──────────────────────────────────────────

    public function testImagesAfterAnExplicitPageBreakAreRemoved(): void
    {
        $builder = new DocxBuilder();
        $cover = $builder->addImage(DocxBuilder::png(60, 60, [30, 120, 220]));
        $later = $builder->addImage(DocxBuilder::png(60, 60, [240, 160, 20]));
        $body = DocxBuilder::paragraph('Omslag')
            . '<w:p>' . DocxBuilder::imageRun($cover) . '</w:p>'
            . DocxBuilder::pageBreak()
            . DocxBuilder::paragraph('Pagina twee')
            . '<w:p>' . DocxBuilder::imageRun($later) . '</w:p>'
            . DocxBuilder::SECTION;

        [$result, $pdf] = $this->convert($builder, $body);

        $this->assertSame(2, $result->pageCount);
        $this->assertSame(1, $result->imagesRemoved);
        $this->assertSame([1, 0], $pdf->imagesPerPage());
    }

    /**
     * Het lastige geval: nergens een pagina-einde. Of een afbeelding op
     * pagina 2 staat, is een feit over de opmaak en niet over de opmaakcode.
     */
    public function testImagesThatOnlyFlowOntoPageTwoAreRemoved(): void
    {
        $builder = new DocxBuilder();
        $cover = $builder->addImage(DocxBuilder::png(60, 60, [30, 120, 220]));
        $later = $builder->addImage(DocxBuilder::png(60, 60, [240, 160, 20]));
        $filler = '';
        for ($line = 0; $line < 60; $line++) {
            $filler .= DocxBuilder::paragraph("Vulregel $line met voldoende tekst erin.");
        }
        $body = '<w:p>' . DocxBuilder::imageRun($cover) . '</w:p>' . $filler
            . '<w:p>' . DocxBuilder::imageRun($later) . '</w:p>' . DocxBuilder::SECTION;

        [$result, $pdf] = $this->convert($builder, $body);

        $this->assertGreaterThanOrEqual(2, $result->pageCount);
        $this->assertSame(1, $result->imagesRemoved);

        $perPage = $pdf->imagesPerPage();
        $this->assertSame(1, $perPage[0]);
        $this->assertSame(0, array_sum(array_slice($perPage, 1)));
    }

    public function testPageOneImagesAreKeptWhenEverythingFitsOnOnePage(): void
    {
        $builder = new DocxBuilder();
        $cover = $builder->addImage(DocxBuilder::png(60, 60, [30, 120, 220]));
        $body = '<w:p>' . DocxBuilder::imageRun($cover) . '</w:p>'
            . DocxBuilder::paragraph('Kort') . DocxBuilder::SECTION;

        [$result, $pdf] = $this->convert($builder, $body);

        $this->assertSame(0, $result->imagesRemoved);
        $this->assertSame([1], $pdf->imagesPerPage());
    }

    public function testTheImageRuleCanBeSwitchedOff(): void
    {
        $builder = new DocxBuilder();
        $cover = $builder->addImage(DocxBuilder::png(60, 60, [30, 120, 220]));
        $later = $builder->addImage(DocxBuilder::png(60, 60, [240, 160, 20]));
        $body = '<w:p>' . DocxBuilder::imageRun($cover) . '</w:p>'
            . DocxBuilder::pageBreak()
            . '<w:p>' . DocxBuilder::imageRun($later) . '</w:p>' . DocxBuilder::SECTION;

        [$result, $pdf] = $this->convert($builder, $body, new Options(imagesFirstPageOnly: false));

        $this->assertSame(0, $result->imagesRemoved);
        $this->assertSame([1, 1], $pdf->imagesPerPage());
    }

    public function testHeaderAndFooterImagesAreNeverRemoved(): void
    {
        $builder = new DocxBuilder();
        // Het logo hoort bij de voettekst, dus het is een relatie van dát onderdeel.
        $logo = $builder->addImage(DocxBuilder::png(20, 20, [10, 10, 10]), 'png', 'word/footer1.xml');
        $footer = $builder->addFooter('<w:p>' . DocxBuilder::imageRun($logo, 190500, 190500) . '</w:p>');
        $body = DocxBuilder::paragraph('Een') . DocxBuilder::pageBreak()
            . DocxBuilder::paragraph('Twee') . DocxBuilder::sectionWithFooter($footer);

        [$result, $pdf] = $this->convert($builder, $body);

        $this->assertSame(0, $result->imagesRemoved);
        $this->assertSame([1, 1], $pdf->imagesPerPage());
    }

    // ── 4. Paginanummering ───────────────────────────────────────────────────

    public function testPageFieldBecomesARealCounter(): void
    {
        $builder = new DocxBuilder();
        $footer = $builder->addFooter(DocxBuilder::pageFieldFooter('Pagina '));
        $body = DocxBuilder::paragraph('Een') . DocxBuilder::pageBreak()
            . DocxBuilder::paragraph('Twee') . DocxBuilder::pageBreak()
            . DocxBuilder::paragraph('Drie') . DocxBuilder::sectionWithFooter($footer);

        [, $pdf] = $this->convert($builder, $body);
        $pages = $pdf->pageTexts();

        $this->assertStringContainsString('Pagina 1', $pages[0]);
        $this->assertStringContainsString('Pagina 2', $pages[1]);
        $this->assertStringContainsString('Pagina 3', $pages[2]);
        // Het getal dat Word ooit onthield mag er niet doorheen lekken.
        $this->assertStringNotContainsString('Pagina 7', $pdf->text());
    }

    public function testNumpagesFieldCountsTheRealPages(): void
    {
        $builder = new DocxBuilder();
        $footer = $builder->addFooter(
            '<w:p><w:r><w:t xml:space="preserve">van </w:t></w:r>'
            . '<w:fldSimple w:instr=" NUMPAGES "><w:r><w:t>99</w:t></w:r></w:fldSimple></w:p>'
        );
        $body = DocxBuilder::paragraph('Een') . DocxBuilder::pageBreak()
            . DocxBuilder::paragraph('Twee') . DocxBuilder::sectionWithFooter($footer);

        [, $pdf] = $this->convert($builder, $body);

        $this->assertStringContainsString('van 2', $pdf->pageTexts()[0]);
        $this->assertStringNotContainsString('99', $pdf->text());
    }

    public function testADocumentWithoutAFooterGetsPageNumbers(): void
    {
        $builder = new DocxBuilder();
        $body = DocxBuilder::paragraph('Een') . DocxBuilder::pageBreak()
            . DocxBuilder::paragraph('Twee') . DocxBuilder::SECTION;

        [, $pdf] = $this->convert($builder, $body);
        $pages = $pdf->pageTexts();

        $this->assertStringContainsString('1', $pages[0]);
        $this->assertStringContainsString('2', $pages[1]);
    }

    public function testPageNumbersCanBeLeftOffTheCover(): void
    {
        $builder = new DocxBuilder();
        $body = DocxBuilder::paragraph('Omslag') . DocxBuilder::pageBreak()
            . DocxBuilder::paragraph('Inhoud') . DocxBuilder::SECTION;

        [, $pdf] = $this->convert($builder, $body,
            new Options(pageNumbersOnFirstPage: false));
        $pages = $pdf->pageTexts();

        $this->assertSame('Omslag', trim($pages[0]));
        $this->assertStringContainsString('2', $pages[1]);
    }

    // ── Grote documenten ─────────────────────────────────────────────────────

    /**
     * Een document met foto's moet gewoon door de molen komen.
     *
     * mPDF weigert HTML die groter is dan pcre.backtrack_limit, standaard 1 MB.
     * Twee dingen houden dat tegen: de afbeeldingen zitten niet in de HTML (zie
     * de test hieronder), en de motor verhoogt die grens als het toch nodig is.
     * Deze test bewaakt de uitkomst; die hieronder bewaakt de aanpak, want het
     * verhogen van de grens mag niet op elke server.
     */
    public function testADocumentWithLargePhotosStillConverts(): void
    {
        $builder = new DocxBuilder();
        $body = DocxBuilder::paragraph('Omslag met fotos');
        $base64Size = 0;

        for ($index = 0; $index < 4; $index++) {
            $photo = self::photoLikePng(500, 500, $index * 37);
            $base64Size += strlen(base64_encode($photo));
            $body .= '<w:p>' . DocxBuilder::imageRun($builder->addImage($photo)) . '</w:p>';
        }
        $body .= DocxBuilder::pageBreak() . DocxBuilder::paragraph('Twee') . DocxBuilder::SECTION;

        // De opzet deugt alleen als dit werkelijk over de grens gaat.
        $this->assertGreaterThan(
            (int) ini_get('pcre.backtrack_limit'),
            $base64Size,
            'de proef moet groter zijn dan de PCRE-grens, anders bewijst hij niets'
        );

        $result = (new Pipeline())->convertDocx($builder->build($body));

        $this->assertSame(2, $result->pageCount);
        $this->assertStringStartsWith('%PDF', $result->pdf);
    }

    public function testImagesAreNotInlinedIntoTheHtml(): void
    {
        $builder = new DocxBuilder();
        $image = $builder->addImage(DocxBuilder::png(40, 40));
        $body = '<w:p>' . DocxBuilder::imageRun($image) . '</w:p>' . DocxBuilder::SECTION;

        $package = new \PrintScript\Package($builder->build($body));
        $document = \PrintScript\HtmlRenderer::render($package);
        $html = implode('', array_map(static fn($s) => $s->html, $document->sections));

        $this->assertStringNotContainsString('base64', $html);
        $images = $document->images;
        $this->assertCount(1, $images);
        $this->assertSame('image/png', reset($images)['mime']);
    }

    /** Ruis comprimeert nauwelijks, net als een echte foto. */
    private static function photoLikePng(int $width, int $height, int $seed): string
    {
        $raw = '';
        for ($y = 0; $y < $height; $y++) {
            $row = chr(0);
            for ($x = 0; $x < $width; $x++) {
                $row .= chr(($x * 7 + $y * 13 + $seed) % 256)
                    . chr(($x * 31 + $y * 3 + $seed) % 256)
                    . chr(($x * 17 + $y * 29 + $seed) % 256);
            }
            $raw .= $row;
        }
        $chunk = static fn(string $tag, string $payload): string =>
            pack('N', strlen($payload)) . $tag . $payload . pack('N', crc32($tag . $payload));

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            . $chunk('IDAT', (string) gzcompress($raw, 1))
            . $chunk('IEND', '');
    }

    public function testAddingPageNumbersCanBeSwitchedOff(): void
    {
        $builder = new DocxBuilder();
        $body = DocxBuilder::paragraph('Alleen tekst') . DocxBuilder::SECTION;

        [, $pdf] = $this->convert($builder, $body, new Options(addPageNumbers: false));

        $this->assertSame('Alleen tekst', trim($pdf->pageTexts()[0]));
    }
}
