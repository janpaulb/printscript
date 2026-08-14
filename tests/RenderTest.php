<?php

declare(strict_types=1);

namespace PrintScript\Tests;

use PHPUnit\Framework\TestCase;
use PrintScript\Clean;
use PrintScript\GoogleDocs;
use PrintScript\HtmlRenderer;
use PrintScript\InvalidDocxException;
use PrintScript\Options;
use PrintScript\Package;
use PrintScript\Pipeline;

/** De opmaak die de reis naar de PDF moet overleven, en de randgevallen. */
final class RenderTest extends TestCase
{
    private function html(DocxBuilder $builder, string $body): string
    {
        $package = new Package($builder->build($body));
        Clean::clean($package);
        $document = HtmlRenderer::render($package);
        return $document->css . "\n" . implode("\n", array_map(
            static fn($section) => $section->html,
            $document->sections
        ));
    }

    private function pdf(DocxBuilder $builder, string $body, ?Options $options = null): PdfInspector
    {
        return new PdfInspector(
            (new Pipeline())->convertDocx($builder->build($body), $options ?? new Options())->pdf
        );
    }

    // ── Tekenopmaak ──────────────────────────────────────────────────────────

    public function testDirectCharacterFormattingBecomesCss(): void
    {
        $html = $this->html(new DocxBuilder(), DocxBuilder::paragraph(
            'Opvallend', null,
            '<w:b/><w:i/><w:u w:val="single"/><w:color w:val="1155CC"/><w:sz w:val="28"/>'
        ) . DocxBuilder::SECTION);

        $this->assertStringContainsString('font-weight: bold', $html);
        $this->assertStringContainsString('font-style: italic', $html);
        $this->assertStringContainsString('text-decoration: underline', $html);
        $this->assertStringContainsString('color: #1155CC', $html);
        $this->assertStringContainsString('font-size: 14pt', $html);
    }

    public function testExplicitOffSwitchesOverrideAnInheritedStyle(): void
    {
        $builder = new DocxBuilder();
        $builder->setStyles('<w:styles ' . DocxBuilder::NS . '>'
            . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal">'
            . '<w:name w:val="Normal"/></w:style>'
            . '<w:style w:type="character" w:styleId="Nadruk"><w:name w:val="Nadruk"/>'
            . '<w:rPr><w:b/><w:u w:val="single"/></w:rPr></w:style></w:styles>');

        $html = $this->html($builder, DocxBuilder::paragraph(
            'Toch niet vet', null,
            '<w:rStyle w:val="Nadruk"/><w:b w:val="0"/><w:u w:val="none"/>'
        ) . DocxBuilder::SECTION);

        $this->assertStringContainsString('font-weight: normal', $html);
        $this->assertStringContainsString('text-decoration: none', $html);
    }

    public function testHeadingStylesBecomeHeadingElements(): void
    {
        $html = $this->html(new DocxBuilder(),
            DocxBuilder::paragraph('Bedrijf', 'Heading1') . DocxBuilder::SECTION);

        $this->assertStringContainsString('<h1 class="ps-p ps-s-Heading1"', $html);
    }

    public function testAlignmentIndentationAndSpacing(): void
    {
        $html = $this->html(new DocxBuilder(), DocxBuilder::paragraph(
            'Uitgevuld', null, '',
            '<w:jc w:val="both"/><w:ind w:left="720" w:right="360" w:firstLine="240"/>'
            . '<w:spacing w:before="120" w:after="240" w:line="360" w:lineRule="auto"/>'
        ) . DocxBuilder::SECTION);

        $this->assertStringContainsString('text-align: justify', $html);
        $this->assertStringContainsString('margin-left: 36pt', $html);
        $this->assertStringContainsString('text-indent: 12pt', $html);
        $this->assertStringContainsString('margin-top: 6pt', $html);
        // w:line="360" is anderhalve regelafstand. Dat betekent anderhalf maal
        // de natuurlijke regelhoogte van het lettertype (~1,15), niet anderhalf
        // maal het korps — vandaar 1,5 x 1,15.
        $this->assertStringContainsString('line-height: 1.725', $html);
    }

    // ── Pagina-instellingen ──────────────────────────────────────────────────

    public function testPageSizeComesFromTheDocument(): void
    {
        $body = DocxBuilder::paragraph('A5 liggend')
            . '<w:sectPr><w:pgSz w:w="11906" w:h="8391" w:orient="landscape"/>'
            . '<w:pgMar w:top="567" w:right="567" w:bottom="567" w:left="567"/></w:sectPr>';

        [$width, $height] = $this->pdf(new DocxBuilder(), $body)->pageSizes()[0];

        $this->assertEqualsWithDelta(595, $width, 2);
        $this->assertEqualsWithDelta(420, $height, 2);
    }

    public function testASecondSectionCanChangeTheOrientation(): void
    {
        $portrait = '<w:p><w:pPr><w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
            . '<w:pgMar w:top="1417" w:right="1417" w:bottom="1417" w:left="1417"/>'
            . '</w:sectPr></w:pPr></w:p>';
        $landscape = '<w:sectPr><w:pgSz w:w="16838" w:h="11906" w:orient="landscape"/>'
            . '<w:pgMar w:top="1000" w:right="1000" w:bottom="1000" w:left="1000"/></w:sectPr>';
        $body = DocxBuilder::paragraph('Staand') . $portrait
            . DocxBuilder::paragraph('Liggend') . $landscape;

        $sizes = $this->pdf(new DocxBuilder(), $body)->pageSizes();

        $this->assertCount(2, $sizes);
        $this->assertLessThan($sizes[0][1], $sizes[0][0], 'eerste pagina staat rechtop');
        $this->assertGreaterThan($sizes[1][1], $sizes[1][0], 'tweede pagina ligt');
    }

    public function testPageBreakBeforeStartsANewPage(): void
    {
        $body = DocxBuilder::paragraph('Een')
            . DocxBuilder::paragraph('Twee', null, '', '<w:pageBreakBefore/>')
            . DocxBuilder::SECTION;

        $pages = $this->pdf(new DocxBuilder(), $body)->pageTexts();

        $this->assertCount(2, $pages);
        $this->assertStringContainsString('Een', $pages[0]);
        $this->assertStringContainsString('Twee', $pages[1]);
    }

    // ── Kop- en voetteksten ──────────────────────────────────────────────────

    public function testAHeaderRepeatsOnEveryPage(): void
    {
        $builder = new DocxBuilder();
        $header = $builder->addHeader('<w:p><w:r><w:t>Werktitel</w:t></w:r></w:p>');
        $footer = $builder->addFooter(DocxBuilder::pageFieldFooter('Blz. '));
        $body = DocxBuilder::paragraph('Een') . DocxBuilder::pageBreak()
            . DocxBuilder::paragraph('Twee')
            . DocxBuilder::sectionWithFooter($footer, $header);

        $pages = $this->pdf($builder, $body)->pageTexts();

        foreach ($pages as $page) {
            $this->assertStringContainsString('Werktitel', $page);
        }
        $this->assertStringContainsString('Blz. 1', $pages[0]);
        $this->assertStringContainsString('Blz. 2', $pages[1]);
    }

    public function testTabbedFooterKeepsItsThreeColumns(): void
    {
        $builder = new DocxBuilder();
        $footer = $builder->addFooter(
            '<w:p><w:r><w:t>Scenario</w:t></w:r><w:r><w:tab/></w:r>'
            . '<w:r><w:t>versie 3</w:t></w:r><w:r><w:tab/></w:r>'
            . '<w:fldSimple w:instr=" PAGE "><w:r><w:t>1</w:t></w:r></w:fldSimple></w:p>'
        );
        $body = DocxBuilder::paragraph('Inhoud') . DocxBuilder::sectionWithFooter($footer);

        $text = $this->pdf($builder, $body)->pageTexts()[0];

        $this->assertStringContainsString('Scenario', $text);
        $this->assertStringContainsString('versie 3', $text);
    }

    public function testTitlePageSuppressesTheRunningHeader(): void
    {
        $builder = new DocxBuilder();
        $header = $builder->addHeader('<w:p><w:r><w:t>KOPTEKST</w:t></w:r></w:p>');
        $footer = $builder->addFooter(DocxBuilder::pageFieldFooter());
        $body = DocxBuilder::paragraph('Omslag') . DocxBuilder::pageBreak()
            . DocxBuilder::paragraph('Body')
            . DocxBuilder::sectionWithFooter($footer, $header, true);

        $pages = $this->pdf($builder, $body)->pageTexts();

        $this->assertStringNotContainsString('KOPTEKST', $pages[0]);
        $this->assertStringContainsString('KOPTEKST', $pages[1]);
    }

    // ── Lijsten en tabellen ──────────────────────────────────────────────────

    private const NUMBERING = '<w:abstractNum w:abstractNumId="0">'
        . '<w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="decimal"/>'
        . '<w:lvlText w:val="%1."/><w:pPr><w:ind w:left="720" w:hanging="360"/></w:pPr></w:lvl>'
        . '<w:lvl w:ilvl="1"><w:start w:val="1"/><w:numFmt w:val="lowerLetter"/>'
        . '<w:lvlText w:val="%1.%2."/><w:pPr><w:ind w:left="1440" w:hanging="360"/></w:pPr></w:lvl>'
        . '</w:abstractNum><w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num>';

    private static function listItem(string $text, int $level = 0): string
    {
        return DocxBuilder::paragraph($text, null, '',
            '<w:numPr><w:ilvl w:val="' . $level . '"/><w:numId w:val="1"/></w:numPr>');
    }

    public function testMultilevelNumberingCountsLikeWord(): void
    {
        $builder = new DocxBuilder();
        $builder->addNumbering(self::NUMBERING);
        $body = self::listItem('Een') . self::listItem('Twee') . self::listItem('Twee-a', 1)
            . self::listItem('Twee-b', 1) . self::listItem('Drie') . DocxBuilder::SECTION;

        $text = $this->pdf($builder, $body)->text();

        foreach (['1. Een', '2. Twee', '2.a. Twee-a', '2.b. Twee-b', '3. Drie'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
    }

    public function testTableCellsMergesAndBorders(): void
    {
        $table = '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/><w:tblBorders>'
            . '<w:top w:val="single" w:sz="8" w:color="000000"/>'
            . '<w:left w:val="single" w:sz="8" w:color="000000"/>'
            . '<w:bottom w:val="single" w:sz="8" w:color="000000"/>'
            . '<w:right w:val="single" w:sz="8" w:color="000000"/>'
            . '<w:insideH w:val="single" w:sz="4" w:color="808080"/>'
            . '<w:insideV w:val="single" w:sz="4" w:color="808080"/>'
            . '</w:tblBorders></w:tblPr>'
            . '<w:tblGrid><w:gridCol w:w="4000"/><w:gridCol w:w="2000"/><w:gridCol w:w="2000"/></w:tblGrid>'
            . '<w:tr><w:tc><w:tcPr><w:vMerge w:val="restart"/></w:tcPr>'
            . DocxBuilder::paragraph('Scene 1') . '</w:tc>'
            . '<w:tc><w:tcPr><w:gridSpan w:val="2"/></w:tcPr>'
            . DocxBuilder::paragraph('Twee kolommen breed') . '</w:tc></w:tr>'
            . '<w:tr><w:tc><w:tcPr><w:vMerge/></w:tcPr>' . DocxBuilder::paragraph('') . '</w:tc>'
            . '<w:tc>' . DocxBuilder::paragraph('Links') . '</w:tc>'
            . '<w:tc>' . DocxBuilder::paragraph('Rechts') . '</w:tc></w:tr></w:tbl>';

        $html = $this->html(new DocxBuilder(), $table . DocxBuilder::SECTION);
        $text = $this->pdf(new DocxBuilder(), $table . DocxBuilder::SECTION)->text();

        $this->assertStringContainsString('colspan="2"', $html);
        $this->assertStringContainsString('border-top: 1pt solid #000000', $html);
        foreach (['Scene 1', 'Twee kolommen breed', 'Links', 'Rechts'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
    }

    // ── Wijzigingen, velden, verborgen tekst ─────────────────────────────────

    public function testTrackedChangesAreResolved(): void
    {
        $body = '<w:p>'
            . '<w:ins w:id="1" w:author="A"><w:r><w:t xml:space="preserve">toegevoegd </w:t></w:r></w:ins>'
            . '<w:del w:id="2" w:author="A"><w:r><w:delText>geschrapt</w:delText></w:r></w:del>'
            . '<w:r><w:t>slot</w:t></w:r></w:p>' . DocxBuilder::SECTION;

        $text = $this->pdf(new DocxBuilder(), $body)->text();

        $this->assertStringContainsString('toegevoegd', $text);
        $this->assertStringContainsString('slot', $text);
        $this->assertStringNotContainsString('geschrapt', $text);
    }

    public function testAnUnknownFieldKeepsItsCachedResult(): void
    {
        $body = '<w:p><w:r><w:fldChar w:fldCharType="begin"/></w:r>'
            . '<w:r><w:instrText> DATE </w:instrText></w:r>'
            . '<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
            . '<w:r><w:t>13-8-2026</w:t></w:r>'
            . '<w:r><w:fldChar w:fldCharType="end"/></w:r></w:p>' . DocxBuilder::SECTION;

        $this->assertStringContainsString('13-8-2026', $this->pdf(new DocxBuilder(), $body)->text());
    }

    public function testHiddenTextIsNotPrinted(): void
    {
        $body = DocxBuilder::paragraph('zichtbaar')
            . DocxBuilder::paragraph('onzichtbaar', null, '<w:vanish/>')
            . DocxBuilder::SECTION;

        $text = $this->pdf(new DocxBuilder(), $body)->text();

        $this->assertStringContainsString('zichtbaar', $text);
        $this->assertStringNotContainsString('onzichtbaar', $text);
    }

    // ── Bestand en pakket ────────────────────────────────────────────────────

    public function testUnsupportedImageFormatIsReportedNotFatal(): void
    {
        $builder = new DocxBuilder();
        $emf = $builder->addImage("\x01\x00\x00\x00 geen echte EMF", 'emf');
        $body = DocxBuilder::paragraph('Tekst') . '<w:p>' . DocxBuilder::imageRun($emf) . '</w:p>'
            . DocxBuilder::SECTION;

        $result = (new Pipeline())->convertDocx($builder->build($body));

        $this->assertSame(1, $result->pageCount);
        $this->assertNotEmpty(array_filter(
            $result->warnings,
            static fn(string $warning): bool => str_contains($warning, 'EMF')
        ));
    }

    public function testAnEmptyDocumentStillProducesAPdf(): void
    {
        $result = (new Pipeline())->convertDocx((new DocxBuilder())->build(DocxBuilder::SECTION));

        $this->assertSame(1, $result->pageCount);
        $this->assertStringStartsWith('%PDF', $result->pdf);
    }

    /** @dataProvider brokenFiles */
    public function testNonDocxInputIsRefused(string $data): void
    {
        $this->expectException(InvalidDocxException::class);
        (new Pipeline())->convertDocx($data);
    }

    public static function brokenFiles(): array
    {
        return [
            'leeg' => [''],
            'platte tekst' => ['dit is gewoon tekst'],
            'een pdf' => ["%PDF-1.7\n%..."],
        ];
    }

    public function testAZipWithoutAMainDocumentIsRefused(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'nodoc');
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::OVERWRITE | \ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml',
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        $zip->addFromString('xl/workbook.xml', '<workbook/>');
        $zip->close();
        $data = (string) file_get_contents($file);
        @unlink($file);

        $this->expectExceptionMessageMatches('~hoofddocument~');
        new Package($data);
    }

    public function testThePackageRoundTripsThroughBytes(): void
    {
        $builder = new DocxBuilder();
        $builder->addComments(DocxBuilder::commentsPart('weg'));
        $package = new Package($builder->build(
            DocxBuilder::commentedParagraph('Tekst') . DocxBuilder::SECTION
        ));
        Clean::clean($package);

        $again = new Package($package->toBytes());

        $this->assertSame('word/document.xml', $again->mainPartName());
        $this->assertFalse($again->hasPart('word/comments.xml'));
    }

    // ── Downloadnamen en links ───────────────────────────────────────────────

    /** @dataProvider filenames */
    public function testDownloadNamesAreSafeAndReadable(string $raw, string $expected): void
    {
        $this->assertSame($expected, Pipeline::safeFilename($raw));
    }

    public static function filenames(): array
    {
        return [
            ['Aflevering 3', 'Aflevering 3'],
            ['Aflevering 3.docx', 'Aflevering 3'],
            ['../../etc/passwd', 'passwd'],
            ['C:\\Users\\jan\\script.docx', 'script'],
            ['scène été (v2)', 'scène été (v2)'],
            ['', 'document'],
            ['   ', 'document'],
            ['...', 'document'],
        ];
    }

    /** @dataProvider googleLinks */
    public function testEveryGoogleLinkShapeYieldsTheId(string $url): void
    {
        $this->assertSame('1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789_-x', GoogleDocs::extractId($url));
    }

    public static function googleLinks(): array
    {
        $id = '1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789_-x';
        return [
            ["https://docs.google.com/document/d/$id/edit"],
            ["https://docs.google.com/document/d/$id/edit?usp=sharing"],
            ["https://docs.google.com/document/u/1/d/$id/edit"],
            ["https://drive.google.com/file/d/$id/view?usp=drive_link"],
            ["https://drive.google.com/open?id=$id"],
            ["  https://docs.google.com/document/d/$id/edit  "],
            [$id],
        ];
    }

    /** @dataProvider badLinks */
    public function testBadLinksExplainThemselves(string $url, string $fragment): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('~' . preg_quote($fragment, '~') . '~');
        GoogleDocs::extractId($url);
    }

    public static function badLinks(): array
    {
        return [
            ['', 'Geen link'],
            ['https://example.com/document/d/abc/edit', 'geen Google Docs-link'],
            ['https://docs.google.com/document/d/e/2PACX-1vABCdefGH/pub', 'gepubliceerd'],
        ];
    }
}
