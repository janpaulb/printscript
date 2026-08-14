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
     * De hoogte van een lege regel: de run wint, maar alleen waar hij iets
     * zegt.
     *
     * Google Docs zet in een lege alinea een run zonder tekst én zonder
     * korps, en legt het korps in het alineamerk. Wie dan de run volgt, krijgt
     * elf punt waar zestien hoorde te staan. Op de omslag van een script,
     * waar zulke regels met tientallen tegelijk staan, verschuift dat de
     * logo's een halve bladzijde.
     *
     * Noemt de run wél een korps, dan is dat het korps van de regel: dan
     * heeft iemand het met opzet anders gezet dan het merk.
     */
    public function testAnEmptyParagraphFallsBackToTheParagraphMark(): void
    {
        $silentRun = $this->paragraphStyle(
            '<w:p><w:pPr><w:rPr><w:sz w:val="52"/></w:rPr></w:pPr>'
            . '<w:r><w:rPr><w:rtl w:val="0"/></w:rPr></w:r></w:p>'
        );
        $ownSize = $this->paragraphStyle(
            '<w:p><w:pPr><w:rPr><w:sz w:val="52"/></w:rPr></w:pPr>'
            . '<w:r><w:rPr><w:sz w:val="20"/></w:rPr></w:r></w:p>'
        );
        $withoutRun = $this->paragraphStyle(
            '<w:p><w:pPr><w:rPr><w:sz w:val="52"/></w:rPr></w:pPr></w:p>'
        );

        $this->assertStringContainsString('font-size: 26pt', $silentRun,
            'de run noemt geen korps, dus telt dat van het merk');
        $this->assertStringContainsString('font-size: 10pt', $ownSize,
            'de run noemt een eigen korps en dat wint van het merk');
        $this->assertStringContainsString('font-size: 26pt', $withoutRun,
            'zonder run is het alineamerk het enige dat de hoogte bepaalt');
    }

    /**
     * Een alinea die op een regelovergang eindigt, eindigt op een lege regel.
     *
     * Een opmaakmotor gooit die weg — er staat immers niets meer achter. In
     * een script staan zulke alinea's bij honderden, en dan loopt het hele
     * document pagina's uit de pas met wat de schrijver ziet.
     */
    public function testATrailingLineBreakKeepsItsEmptyLine(): void
    {
        $html = $this->paragraphStyle(
            '<w:p><w:r><w:rPr><w:sz w:val="24"/></w:rPr>'
            . '<w:t>Tekst</w:t><w:br w:type="textWrapping"/></w:r></w:p>'
        );

        $this->assertStringContainsString('<br>&nbsp;</span>', $html,
            'de laatste regelovergang krijgt iets om overeind te blijven');
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

        $this->assertStringContainsString('line-height: 1.3225', $html,
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
     * Een afbeelding in de tekstregel krijgt de regelafstand van zijn alinea,
     * net als een letter.
     *
     * Een opmaakmotor geeft zo'n regel precies de hoogte van de afbeelding en
     * geen punt meer. Bij een script met tientallen stills scheelt dat een
     * hele pagina — en op de omslag het verschil tussen een logo dat er nog
     * op past en een dat naar pagina 2 verdwijnt (en dus wordt weggehaald).
     */
    public function testInlineImagesGetTheLineSpacingAsLeading(): void
    {
        $builder = new DocxBuilder();
        $image = $builder->addImage(DocxBuilder::png(40, 40));
        $inline = '<w:p><w:pPr><w:spacing w:line="276" w:lineRule="auto"/></w:pPr>'
            . '<w:r><w:drawing><wp:inline distB="0" distT="0" distL="0" distR="0">'
            . '<wp:extent cx="1270000" cy="1270000"/>'
            . '<wp:docPr id="1" name="still"/>'
            . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="still"/><pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip r:embed="' . $image . '"/><a:stretch><a:fillRect/></a:stretch>'
            . '</pic:blipFill><pic:spPr/></pic:pic></a:graphicData></a:graphic>'
            . '</wp:inline></w:drawing></w:r></w:p>';

        $html = $this->paragraphStyle($inline, $builder);

        // 1270000 EMU = 100pt hoog; regelafstand 1,15 geeft 15pt lucht, half
        // boven en half onder.
        $this->assertStringContainsString('margin-top: 7.5pt', $html);
        $this->assertStringContainsString('margin-bottom: 7.5pt', $html);
    }

    /**
     * Een zwevende afbeelding staat buiten de tekststroom: hij duwt niets
     * omlaag. Een omslaglogo is al gauw negentig punt hoog — in de stroom duwt
     * dat de titel anderhalve regel naar beneden en alles daaronder mee.
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

        // 541500 EMU = 42,64pt vanaf de kolom, dus vanaf de linkermarge;
        // 381000 EMU = 30pt onder de bovenkant van de alinea. Dat laatste
        // blijft een marge: waar de alinea uitkomt weet alleen de motor.
        $this->assertStringContainsString('position: absolute', $html);
        $this->assertStringContainsString('left: 113.49pt', $html,
            '71pt linkermarge plus 42,64pt verschuiving');
        $this->assertStringContainsString('margin-top: 30pt', $html);
        // En de alinea houdt zijn eigen lege regel: het logo vult haar niet.
        $this->assertStringContainsString('&nbsp;</p>', $html);
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
