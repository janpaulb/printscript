<?php

declare(strict_types=1);

namespace PrintScript\Tests;

use PHPUnit\Framework\TestCase;
use PrintScript\ImageCrop;
use PrintScript\Options;
use PrintScript\Pipeline;

/**
 * Bijsnijden gooit niets weg.
 *
 * Wie in Google Docs of Word een afbeelding bijsnijdt, laat het hele plaatje
 * in het bestand staan; er komt alleen een a:srcRect bij die zegt welk deel
 * er te zien is. Voert niemand die uit, dan wordt het hele plaatje in het
 * vakje van het uitgesneden stuk gedrukt en komt een omslagfoto er
 * platgeslagen uit.
 */
final class ImageCropTest extends TestCase
{
    /** Links rood, rechts blauw, onderste helft half doorzichtig groen. */
    private static function fourQuarters(): string
    {
        $image = imagecreatetruecolor(200, 100);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, 99, 99, imagecolorallocate($image, 220, 40, 40));
        imagefilledrectangle($image, 100, 0, 199, 99, imagecolorallocate($image, 40, 40, 220));
        imagefilledrectangle($image, 0, 50, 199, 99,
            imagecolorallocatealpha($image, 40, 220, 40, 63));

        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    public function testCroppingKeepsTheRequestedPartAtItsOwnSize(): void
    {
        $source = self::fourQuarters();

        $left = imagecreatefromstring((string) ImageCrop::apply($source, [0.0, 0.0, 0.5, 0.0]));
        $right = imagecreatefromstring((string) ImageCrop::apply($source, [0.5, 0.0, 0.0, 0.0]));
        $bottom = imagecreatefromstring((string) ImageCrop::apply($source, [0.0, 0.5, 0.0, 0.0]));

        // Het uitgesneden stuk heeft zijn eigen afmetingen; niets wordt gerekt.
        $this->assertSame([100, 100], [imagesx($left), imagesy($left)]);
        $this->assertSame([100, 100], [imagesx($right), imagesy($right)]);
        $this->assertSame([200, 50], [imagesx($bottom), imagesy($bottom)]);

        // En het is ook echt het gevraagde stuk.
        $this->assertSame([220, 40, 40], self::rgb($left, 50, 10), 'de linkerhelft is rood');
        $this->assertSame([40, 40, 220], self::rgb($right, 50, 10), 'de rechterhelft is blauw');
        $this->assertSame([40, 220, 40], self::rgb($bottom, 50, 10), 'de onderhelft is groen');
    }

    /** Doorzichtigheid overleeft het bijsnijden — anders krijgt een logo een kader. */
    public function testCroppingKeepsTransparency(): void
    {
        $cropped = imagecreatefromstring(
            (string) ImageCrop::apply(self::fourQuarters(), [0.0, 0.5, 0.0, 0.0])
        );

        $alpha = (imagecolorat($cropped, 50, 10) >> 24) & 0x7F;
        $this->assertSame(63, $alpha, 'de half doorzichtige band blijft half doorzichtig');
    }

    /**
     * Een uitsnede die niets overlaat, of die er niets af haalt, is geen
     * uitsnede — dan blijft de afbeelding zoals hij is.
     */
    public function testDegenerateRectanglesAreIgnored(): void
    {
        $rectangle = static function (string $xml): ?array {
            $document = new \DOMDocument();
            $document->loadXML('<a:srcRect xmlns:a="x" ' . $xml . '/>');
            return ImageCrop::rectangle($document->documentElement);
        };

        $this->assertNull($rectangle('l="0" t="0" r="0" b="0"'), 'niets eraf');
        $this->assertNull($rectangle(''), 'geen waarden');
        $this->assertNull($rectangle('l="60000" r="60000"'), 'niets over');
        $this->assertSame([0.25, 0.0, 0.0, 0.1], $rectangle('l="25000" b="10000"'));
        // Negatieve waarden zetten juist ruimte om de afbeelding; die laten we
        // met rust, want uitrekken is erger dan een randje missen.
        $this->assertSame([0.0, 0.0, 0.5, 0.0], $rectangle('l="-20000" r="50000"'));
    }

    /** Oudere Word-versies schrijven de uitsnede als losse VML-attributen. */
    public function testTheOlderVmlSpellingIsUnderstoodToo(): void
    {
        $imageData = static function (string $xml): ?array {
            $document = new \DOMDocument();
            $document->loadXML('<v:imagedata xmlns:v="x" ' . $xml . '/>');
            return ImageCrop::vmlRectangle($document->documentElement);
        };

        $this->assertSame([0.25, 0.0, 0.0, 0.1],
            $imageData('cropleft="0.25" cropbottom="0.1"'), 'als breuk');
        $this->assertSame([0.25, 0.0, 0.0, 0.0],
            $imageData('cropleft="16384f"'), '16384/65536 is een kwart');
        $this->assertNull($imageData(''), 'zonder attributen valt er niets te snijden');
    }

    /**
     * En dan de uitkomst waar het om gaat: staat het uitgesneden stuk
     * onvervormd in de PDF?
     */
    public function testACroppedImageReachesThePdfAtItsCroppedSize(): void
    {
        $builder = new DocxBuilder();
        $image = $builder->addImage(self::fourQuarters());
        // 200x100 beeldpunten, waarvan de linkerhelft — dus een vierkant —
        // getoond in een vierkant vak van 100 bij 100 punt.
        $body = '<w:p>' . DocxBuilder::imageRun($image, 1270000, 1270000,
                '<a:srcRect l="0" t="0" r="50000" b="0"/>') . '</w:p>'
            . DocxBuilder::SECTION;

        $result = (new Pipeline())->convertDocx($builder->build($body), new Options());

        $this->assertMatchesRegularExpression(
            '~/Width 100\s*/Height 100~',
            $result->pdf,
            'de afbeelding zit als 100 bij 100 in de PDF, niet als 200 bij 100 platgedrukt'
        );
        $this->assertDoesNotMatchRegularExpression('~/Width 200\s*/Height 100~', $result->pdf);
    }

    /** @return array{int, int, int} */
    private static function rgb(\GdImage $image, int $x, int $y): array
    {
        $colour = imagecolorat($image, $x, $y);
        return [($colour >> 16) & 0xFF, ($colour >> 8) & 0xFF, $colour & 0xFF];
    }
}
