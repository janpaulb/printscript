<?php

declare(strict_types=1);

namespace PrintScript;

/**
 * Een bijgesneden afbeelding echt bijsnijden.
 *
 * Wie in Google Docs of Word een afbeelding bijsnijdt, gooit niets weg. Het
 * hele plaatje blijft in het bestand zitten; er komt alleen een a:srcRect bij
 * die zegt welk deel er te zien is. Een opmaakmotor kent dat niet: die tekent
 * het hele plaatje in het vakje dat voor het uitgesneden stuk bedoeld was, en
 * dan wordt het in elkaar gedrukt — een bijgesneden omslagfoto komt er
 * uitgerekt of platgeslagen uit.
 *
 * Dus snijden we hem hier zelf bij, vóór de motor hem te zien krijgt.
 */
final class ImageCrop
{
    /**
     * De uitsnede in fracties van de breedte en de hoogte.
     *
     * OOXML rekent in honderdduizendsten: r="50000" is de rechterhelft eraf.
     * Negatieve waarden bestaan ook — dan hoort er juist ruimte omheen — maar
     * die laten we met rust: uitrekken is erger dan een randje missen.
     *
     * @return ?array{0: float, 1: float, 2: float, 3: float} links, boven, rechts, onder
     */
    public static function rectangle(?\DOMElement $sourceRectangle): ?array
    {
        if ($sourceRectangle === null) {
            return null;
        }
        $sides = [];
        foreach (['l', 't', 'r', 'b'] as $side) {
            $value = $sourceRectangle->getAttribute($side);
            $sides[] = is_numeric($value) ? max((float) $value / 100000.0, 0.0) : 0.0;
        }
        if ($sides[0] + $sides[2] >= 1.0 || $sides[1] + $sides[3] >= 1.0) {
            return null;   // er zou niets overblijven
        }
        return $sides === [0.0, 0.0, 0.0, 0.0] ? null : $sides;
    }

    /**
     * Hetzelfde, maar zoals oudere Word-versies het opschrijven: als losse
     * attributen op v:imagedata, met de waarde als breuk ("0.25") of in
     * vijfenzestigduizendsten met een f erachter ("16384f").
     *
     * @return ?array{0: float, 1: float, 2: float, 3: float}
     */
    public static function vmlRectangle(?\DOMElement $imageData): ?array
    {
        if ($imageData === null) {
            return null;
        }
        $sides = [];
        foreach (['cropleft', 'croptop', 'cropright', 'cropbottom'] as $attribute) {
            $value = trim($imageData->getAttribute($attribute));
            if ($value === '') {
                $sides[] = 0.0;
                continue;
            }
            $fraction = str_ends_with($value, 'f')
                ? (float) substr($value, 0, -1) / 65536.0
                : (float) $value;
            $sides[] = max($fraction, 0.0);
        }
        if ($sides[0] + $sides[2] >= 1.0 || $sides[1] + $sides[3] >= 1.0) {
            return null;
        }
        return $sides === [0.0, 0.0, 0.0, 0.0] ? null : $sides;
    }

    public static function isAvailable(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagepng');
    }

    /**
     * Heeft deze afbeelding een doorzichtigheidskanaal?
     *
     * De vraag lijkt een detail maar is het niet. Geef je een PDF-motor een
     * afbeelding mét alfakanaal, dan moet die hem uit elkaar trekken in een
     * beeld en een masker — en dat kost ruim een seconde per schermafdruk van
     * vier miljoen beeldpunten. Bij een repetitiescript met veertig stills is
     * dat het verschil tussen een PDF en een server die er na dertig seconden
     * mee ophoudt.
     *
     * Het antwoord staat in de bytes zelf, dus dat kost niets: JPEG kan het
     * niet, PNG zegt het in zijn kopje, en bij de rest gaan we ervan uit dat
     * het kan.
     */
    private static function hasTransparency(string $blob, string $mime): bool
    {
        if (in_array(strtolower($mime), ['image/jpeg', 'image/jpg'], true)) {
            return false;
        }
        if (!str_starts_with($blob, "\x89PNG\r\n\x1a\n")) {
            return true;   // GIF, WebP en onbekend: liever te voorzichtig
        }
        // Kleurtype staat in de IHDR, het eerste blok na de acht-byte kop.
        $colourType = ord($blob[25] ?? "\x06");
        if ($colourType === 4 || $colourType === 6) {
            return true;   // grijs+alfa of RGBA
        }
        // Palet of vol kleur kan alsnog een tRNS-blok hebben.
        return str_contains(substr($blob, 0, 4096), 'tRNS');
    }

    /**
     * Levert het uitgesneden stuk terug als [bytes, mediatype], of null.
     *
     * Een repetitiescript zit vol schermafdrukken van vier miljoen
     * beeldpunten, en die gaan hier allemaal doorheen. Het opnieuw opslaan is
     * het duurste deel, dus dat moet zuinig: een JPEG blijft een JPEG, en een
     * afbeelding zonder doorzichtigheid krijgt er ook geen.
     *
     * @param array{0: float, 1: float, 2: float, 3: float} $crop
     * @return ?array{0: string, 1: string}
     */
    public static function apply(string $blob, array $crop, string $mime = 'image/png'): ?array
    {
        if (!self::isAvailable()) {
            return null;
        }

        $source = @imagecreatefromstring($blob);
        if ($source === false) {
            return null;
        }

        try {
            $width = imagesx($source);
            $height = imagesy($source);

            $left = (int) round($crop[0] * $width);
            $top = (int) round($crop[1] * $height);
            $keepWidth = max((int) round((1.0 - $crop[0] - $crop[2]) * $width), 1);
            $keepHeight = max((int) round((1.0 - $crop[1] - $crop[3]) * $height), 1);
            $keepWidth = min($keepWidth, $width - $left);
            $keepHeight = min($keepHeight, $height - $top);
            if ($keepWidth < 1 || $keepHeight < 1) {
                return null;
            }

            $target = imagecreatetruecolor($keepWidth, $keepHeight);
            if ($target === false) {
                return null;
            }

            try {
                $transparent = self::hasTransparency($blob, $mime);
                if ($transparent) {
                    // Doorzichtig beginnen en doorzichtig blijven: zonder deze
                    // twee regels vult GD het vlak met zwart en krijgt een
                    // uitgesneden logo een zwarte achtergrond.
                    imagealphablending($target, false);
                    imagesavealpha($target, true);
                    $clear = imagecolorallocatealpha($target, 0, 0, 0, 127);
                    if ($clear !== false) {
                        imagefilledrectangle($target, 0, 0, $keepWidth, $keepHeight, $clear);
                    }
                }
                imagecopy($target, $source, 0, 0, $left, $top, $keepWidth, $keepHeight);

                $asJpeg = !$transparent
                    && in_array(strtolower($mime), ['image/jpeg', 'image/jpg'], true)
                    && function_exists('imagejpeg');

                ob_start();
                $written = $asJpeg
                    ? imagejpeg($target, null, 90)
                    : imagepng($target);
                $bytes = (string) ob_get_clean();

                return $written && $bytes !== ''
                    ? [$bytes, $asJpeg ? 'image/jpeg' : 'image/png']
                    : null;
            } finally {
                imagedestroy($target);
            }
        } finally {
            imagedestroy($source);
        }
    }
}
