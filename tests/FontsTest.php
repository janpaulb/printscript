<?php

declare(strict_types=1);

namespace PrintScript\Tests;

use PHPUnit\Framework\TestCase;
use PrintScript\Engine\Fonts;
use PrintScript\Pipeline;

/**
 * Lettertypen bepalen de opmaak.
 *
 * De breedte van elke letter bepaalt waar een regel afbreekt en dus waar een
 * pagina eindigt. Zet je Arial-tekst in een ander lettertype, dan loopt het
 * document uit de pas met wat je in Google Docs ziet. Liberation is metrisch
 * identiek aan Arial, Times New Roman en Courier New; deze tests bewaken dat
 * die vervanging ook echt gebeurt.
 */
final class FontsTest extends TestCase
{
    public function testEveryFontFileIsPresent(): void
    {
        $this->assertSame([], Fonts::missing(),
            'zonder deze bestanden valt mPDF stil terug op een ander lettertype');
    }

    /**
     * mPDF kent ruim honderd lettertypen en heeft de bestanden van een
     * handvol; wij dunnen dat nog verder uit om het uitrolpakket klein te
     * houden. Wijst de instelling naar een bestand dat er niet is, dan gaat
     * het pas stuk als iemand een teken gebruikt dat nergens in staat — en
     * dan valt de hele conversie om.
     */
    public function testNoConfiguredFontPointsAtAMissingFile(): void
    {
        $configuration = Fonts::configuration();
        $directories = $configuration['fontDir'];

        $found = static function (string $file) use ($directories): bool {
            foreach ($directories as $directory) {
                if (is_file(rtrim($directory, '/\\') . '/' . $file)) {
                    return true;
                }
            }
            return false;
        };

        foreach ($configuration['fontdata'] as $family => $styles) {
            foreach (['R', 'B', 'I', 'BI'] as $style) {
                if (isset($styles[$style]) && is_string($styles[$style])) {
                    $this->assertTrue($found($styles[$style]),
                        "$family verwijst naar $styles[$style], en dat bestand ontbreekt");
                }
            }
        }

        foreach ($configuration['backupSubsFont'] as $family) {
            $this->assertArrayHasKey($family, $configuration['fontdata'],
                'een terugvallettertype dat we niet meeleveren laat de conversie omvallen');
        }
        $this->assertNotSame('', $configuration['default_font']);
    }

    /**
     * Een enkel Chinees teken of een emoji in een regieaanwijzing liet de hele
     * conversie omvallen: mPDF zocht dan het lettertype Sun-ExtA, en dat
     * leveren we niet mee.
     */
    public function testACharacterNoFontCoversDoesNotBreakTheConversion(): void
    {
        $builder = new DocxBuilder();
        $body = DocxBuilder::paragraph('Regie: 会议记录 en 🎬 en dan verder')
            . DocxBuilder::SECTION;

        $result = (new Pipeline())->convertDocx($builder->build($body));

        $this->assertSame(1, $result->pageCount);
        $this->assertStringContainsString('Regie:', (new PdfInspector($result->pdf))->text());
        // En het blijft niet stil: er staat bij dat die tekens leeg blijven.
        $this->assertNotEmpty(array_filter(
            $result->warnings,
            static fn(string $warning): bool => str_contains($warning, 'leeg vakje')
        ), 'de gebruiker hoort te weten waarom er een leeg vakje op papier staat');
    }

    /** Grieks, Cyrillisch en gewone symbolen kunnen wél — daar geen ruis over. */
    public function testCharactersThatDoWorkAreNotWarnedAbout(): void
    {
        $builder = new DocxBuilder();
        $body = DocxBuilder::paragraph('αβγ Привет ∑ — “aanhaling”') . DocxBuilder::SECTION;

        $result = (new Pipeline())->convertDocx($builder->build($body));

        $this->assertSame([], $result->warnings);
    }

    /** @dataProvider families */
    public function testWordFontsAreReplacedByTheirMetricEqual(string $family, string $expected): void
    {
        $builder = new DocxBuilder();
        $body = DocxBuilder::paragraph('De snelle bruine vos', null,
            '<w:rFonts w:ascii="' . $family . '" w:hAnsi="' . $family . '"/>')
            . DocxBuilder::SECTION;

        $pdf = (new Pipeline())->convertDocx($builder->build($body))->pdf;

        preg_match_all('~/BaseFont\s*/[A-Z]+\+([A-Za-z]+)~', $pdf, $matches);
        $this->assertContains($expected, array_unique($matches[1]));
    }

    public static function families(): array
    {
        return [
            'Arial (de standaard van Google Docs)' => ['Arial', 'LiberationSans'],
            'Times New Roman' => ['Times New Roman', 'LiberationSerif'],
            'Courier New' => ['Courier New', 'LiberationMono'],
            'Calibri (de standaard van Word)' => ['Calibri', 'LiberationSans'],
            'Georgia' => ['Georgia', 'LiberationSerif'],
        ];
    }

    /**
     * Het lettertype dat mPDF uit zichzelf koos was 4,7% breder dan Arial.
     * Over een heel script levert dat andere regelovergangen op.
     */
    public function testTheReplacementHasArialWidthsNotTheFallbackWidths(): void
    {
        $mpdf = new \Mpdf\Mpdf(Fonts::configuration() + ['mode' => 'utf-8']);
        $sentence = 'De snelle bruine vos springt over de luie hond en kijkt niet om.';

        $mpdf->SetFont('liberationsans', '', 11);
        $correct = $mpdf->GetStringWidth($sentence);

        $mpdf->SetFont('dejavuserifcondensed', '', 11);
        $fallback = $mpdf->GetStringWidth($sentence);

        $this->assertGreaterThan(0.02, abs($fallback - $correct) / $correct,
            'als deze twee even breed zijn, meet de test niets zinnigs');
        $this->assertEqualsWithDelta(106.76, $correct, 0.5,
            'de breedte van Arial 11pt voor deze zin; wijkt dit af, dan is het '
            . 'vervangende lettertype veranderd');
    }

    /**
     * Lettertypenamen staan in een style="..."-attribuut. Een dubbel
     * aanhalingsteken daarbinnen sluit het attribuut voortijdig af, waardoor
     * het lettertype stilzwijgend verdwijnt — precies wat er misging.
     */
    public function testFontNamesDoNotBreakOutOfTheStyleAttribute(): void
    {
        $builder = new DocxBuilder();
        $body = DocxBuilder::paragraph('vos', null,
            '<w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman"/>')
            . DocxBuilder::SECTION;

        $document = \PrintScript\HtmlRenderer::render(
            new \PrintScript\Package($builder->build($body))
        );
        $html = implode('', array_map(static fn($s) => $s->html, $document->sections));

        preg_match_all('~style="([^"]*)"~', $html, $matches);
        foreach ($matches[1] as $style) {
            $this->assertStringNotContainsString('font-family: ,', $style);
        }
        $this->assertStringContainsString("'Times New Roman'", $html);
        $this->assertStringNotContainsString('"Times New Roman"', $html);
    }

    public function testTheDefaultFontIsTheArialEqual(): void
    {
        $builder = new DocxBuilder();
        // Geen lettertype opgegeven: dan hoort de standaard Arial-breedte te gelden.
        $pdf = (new Pipeline())->convertDocx(
            $builder->build(DocxBuilder::paragraph('Zonder lettertype') . DocxBuilder::SECTION)
        )->pdf;

        preg_match_all('~/BaseFont\s*/[A-Z]+\+([A-Za-z]+)~', $pdf, $matches);
        $this->assertContains('LiberationSans', array_unique($matches[1]));
    }
}
