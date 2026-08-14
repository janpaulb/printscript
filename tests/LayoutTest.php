<?php

declare(strict_types=1);

namespace PrintScript\Tests;

use PHPUnit\Framework\TestCase;
use PrintScript\Clean;
use PrintScript\HtmlRenderer;
use PrintScript\Ns;
use PrintScript\Package;

/**
 * Witruimte is inhoud.
 *
 * Deze tests bewaken drie dingen die de opmaak van een heel document konden
 * laten verschuiven zonder dat er ook maar één letter veranderde. Ze zijn
 * gevonden door een echt script naast de PDF van Google Docs te leggen en de
 * afstanden op te meten.
 */
final class LayoutTest extends TestCase
{
    /**
     * Google Docs zet in vrijwel elke alinea een run zonder tekst. Die run
     * bepaalt hoe hoog een lege regel wordt. Het opruimen van opmerkingen
     * mocht ze niet meenemen — dat liet alle witruimte inklappen.
     */
    public function testEmptyRunsSurviveCommentCleaning(): void
    {
        $builder = new DocxBuilder();
        $builder->addComments(DocxBuilder::commentsPart('weg'));
        // Een lege alinea zoals Google Docs die schrijft, plus een alinea met
        // een opmerking erin.
        $body = '<w:p><w:pPr><w:rPr><w:sz w:val="52"/></w:rPr></w:pPr>'
            . '<w:r><w:rPr><w:rtl w:val="0"/></w:rPr></w:r></w:p>'
            . DocxBuilder::commentedParagraph('Met opmerking')
            . DocxBuilder::SECTION;

        $package = new Package($builder->build($body));
        Clean::clean($package);

        $document = $package->document('word/document.xml');
        $runs = $document?->getElementsByTagNameNS(Ns::W, 'r');

        // De lege run blijft; de run die alleen naar de opmerking wees, gaat weg.
        $this->assertSame(2, $runs?->length,
            'de lege run van de eerste alinea en de tekstrun van de tweede');
        $this->assertSame(0, Clean::countCommentMarkers($package));
    }

    /**
     * De hoogte van een lege regel komt van de run, niet van het alineamerk.
     *
     * Het merk staat in scripts vaak op een heel ander korps dan de tekst; wie
     * dat volgt, blaast de witruimte op. Alleen als er helemaal geen run is,
     * telt het merk.
     */
    public function testAnEmptyParagraphTakesItsHeightFromTheRun(): void
    {
        $withRun = $this->paragraphStyle(
            '<w:p><w:pPr><w:rPr><w:sz w:val="52"/></w:rPr></w:pPr>'
            . '<w:r><w:rPr><w:rtl w:val="0"/></w:rPr></w:r></w:p>'
        );
        $withoutRun = $this->paragraphStyle(
            '<w:p><w:pPr><w:rPr><w:sz w:val="52"/></w:rPr></w:pPr></w:p>'
        );

        $this->assertStringNotContainsString('font-size: 26pt', $withRun,
            'met een run erin geldt het korps van die run, niet dat van het merk');
        $this->assertStringContainsString('font-size: 26pt', $withoutRun,
            'zonder run is het alineamerk het enige dat de hoogte bepaalt');
    }

    /**
     * "Regelafstand 1,15" is 1,15 maal de natuurlijke regelhoogte van het
     * lettertype, niet 1,15 maal het korps. Het verschil is klein per regel en
     * groot over een script van veertig pagina's.
     */
    public function testLineSpacingMultipliesTheNaturalLineHeight(): void
    {
        $html = $this->paragraphStyle(DocxBuilder::paragraph(
            'Tekst', null, '', '<w:spacing w:line="276" w:lineRule="auto"/>'
        ));

        $this->assertStringContainsString('line-height: 1.322', $html,
            '276/240 = 1,15 regelafstand, maal 1,15 natuurlijke regelhoogte');
    }

    /** Een exacte regelafstand blijft exact: die is in punten opgegeven. */
    public function testExactLineSpacingIsNotMultiplied(): void
    {
        $html = $this->paragraphStyle(DocxBuilder::paragraph(
            'Tekst', null, '', '<w:spacing w:line="360" w:lineRule="exact"/>'
        ));

        $this->assertStringContainsString('line-height: 18pt', $html);
    }

    /**
     * Een zwevende afbeelding staat op een eigen plek ten opzichte van de
     * kolom. Zonder die verschuiving plakt een omslaglogo tegen de linkermarge.
     */
    public function testFloatingImagesKeepTheirOffset(): void
    {
        $builder = new DocxBuilder();
        $image = $builder->addImage(DocxBuilder::png(40, 40));
        $anchored = '<w:p><w:r><w:drawing>'
            . '<wp:anchor behindDoc="1" distB="0" distT="0" distL="0" distR="0">'
            . '<wp:positionH relativeFrom="column"><wp:posOffset>541500</wp:posOffset></wp:positionH>'
            . '<wp:positionV relativeFrom="paragraph"><wp:posOffset>381000</wp:posOffset></wp:positionV>'
            . '<wp:extent cx="1905000" cy="1905000"/><wp:wrapNone/>'
            . '<wp:docPr id="1" name="logo"/>'
            . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="logo"/><pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip r:embed="' . $image . '"/><a:stretch><a:fillRect/></a:stretch>'
            . '</pic:blipFill><pic:spPr/></pic:pic></a:graphicData></a:graphic>'
            . '</wp:anchor></w:drawing></w:r></w:p>';

        $html = $this->paragraphStyle($anchored . DocxBuilder::SECTION, $builder);

        // 541500 EMU = 42.64pt naar rechts, 381000 EMU = 30pt omlaag.
        $this->assertStringContainsString('margin-left: 42.64pt', $html);
        $this->assertStringContainsString('margin-top: 30pt', $html);
    }

    private function paragraphStyle(string $body, ?DocxBuilder $builder = null): string
    {
        if (!str_contains($body, '<w:sectPr')) {
            $body .= DocxBuilder::SECTION;
        }
        // Dezelfde bouwer als waarin de afbeelding is aangemeld, anders bestaat
        // de relatie waar de afbeelding aan hangt niet.
        $package = new Package(($builder ?? new DocxBuilder())->build($body));
        $document = HtmlRenderer::render($package);

        return $document->css . "\n" . implode("\n", array_map(
            static fn($section) => $section->html,
            $document->sections
        ));
    }
}
