<?php

declare(strict_types=1);

namespace PrintScript\Engine;

/**
 * De lettertypen waarmee de PDF wordt gezet.
 *
 * Dit is geen schoonheidskwestie. De breedte van elke letter bepaalt waar een
 * regel afbreekt, en dus waar een pagina eindigt. Zet je Arial-tekst in een
 * ander lettertype, dan loopt het hele document uit de pas met wat je in
 * Google Docs ziet — andere regelovergangen, ander aantal pagina's.
 *
 * De Liberation-familie is daarom geen willekeurige keuze: die is *metrisch
 * identiek* aan Arial, Times New Roman en Courier New. Elke letter is even
 * breed als in het origineel, alleen de vorm verschilt minimaal. LibreOffice
 * doet hetzelfde als het een document opent waarvan de lettertypen ontbreken.
 */
final class Fonts
{
    /** Waar onze eigen lettertypen staan. */
    public static function directory(): string
    {
        return dirname(__DIR__, 2) . '/fonts';
    }

    /**
     * De inschrijving bij mPDF: welk bestand hoort bij welke stijl.
     *
     * @return array<string, array<string, string>>
     */
    public static function data(): array
    {
        return [
            'liberationsans' => [
                'R'  => 'LiberationSans-Regular.ttf',
                'B'  => 'LiberationSans-Bold.ttf',
                'I'  => 'LiberationSans-Italic.ttf',
                'BI' => 'LiberationSans-BoldItalic.ttf',
            ],
            'liberationserif' => [
                'R'  => 'LiberationSerif-Regular.ttf',
                'B'  => 'LiberationSerif-Bold.ttf',
                'I'  => 'LiberationSerif-Italic.ttf',
                'BI' => 'LiberationSerif-BoldItalic.ttf',
            ],
            'liberationmono' => [
                'R'  => 'LiberationMono-Regular.ttf',
                'B'  => 'LiberationMono-Bold.ttf',
                'I'  => 'LiberationMono-Italic.ttf',
                'BI' => 'LiberationMono-BoldItalic.ttf',
            ],
        ];
    }

    /**
     * Namen uit Word en Google Docs, vertaald naar wat we werkelijk zetten.
     *
     * De eerste drie regels zijn exacte vervangers: even brede letters, dus
     * dezelfde regelovergangen. De rest is een benadering — daar kiezen we het
     * lettertype dat qua karakter het dichtst in de buurt komt.
     *
     * @return array<string, string>
     */
    public static function translations(): array
    {
        return [
            // Metrisch identiek
            'arial' => 'liberationsans',
            'helvetica' => 'liberationsans',
            'arialmt' => 'liberationsans',
            'arialnarrow' => 'liberationsans',
            'timesnewroman' => 'liberationserif',
            'timesnewromanpsmt' => 'liberationserif',
            'times' => 'liberationserif',
            'couriernew' => 'liberationmono',
            'courier' => 'liberationmono',

            // Benaderingen: schreefloos
            'calibri' => 'liberationsans',
            'verdana' => 'liberationsans',
            'tahoma' => 'liberationsans',
            'segoeui' => 'liberationsans',
            'roboto' => 'liberationsans',
            'opensans' => 'liberationsans',
            'lato' => 'liberationsans',
            'helveticaneue' => 'liberationsans',

            // Benaderingen: met schreef
            'cambria' => 'liberationserif',
            'georgia' => 'liberationserif',
            'garamond' => 'liberationserif',
            'bookantiqua' => 'liberationserif',
            'palatino' => 'liberationserif',
            'palatinolinotype' => 'liberationserif',
            'merriweather' => 'liberationserif',
            'ptserif' => 'liberationserif',
            'notoserif' => 'liberationserif',
            'eb garamond' => 'liberationserif',

            // Benaderingen: vaste breedte
            'consolas' => 'liberationmono',
            'menlo' => 'liberationmono',
            'monaco' => 'liberationmono',
            'robotomono' => 'liberationmono',
            'sourcecodepro' => 'liberationmono',
            'inconsolata' => 'liberationmono',
        ];
    }

    /** De instellingen die mPDF nodig heeft om dit alles te gebruiken. */
    public static function configuration(): array
    {
        $defaults = (new \Mpdf\Config\FontVariables())->getDefaults();

        return [
            'fontDir' => array_merge(
                (new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir'],
                [self::directory()]
            ),
            'fontdata' => $defaults['fontdata'] + self::data(),
            'default_font' => 'liberationsans',
            'sans_fonts' => array_merge(
                ['liberationsans'],
                $defaults['sans_fonts'] ?? [],
                array_keys(array_filter(
                    self::translations(),
                    static fn(string $target): bool => $target === 'liberationsans'
                ))
            ),
            'serif_fonts' => array_merge(
                ['liberationserif'],
                $defaults['serif_fonts'] ?? [],
                array_keys(array_filter(
                    self::translations(),
                    static fn(string $target): bool => $target === 'liberationserif'
                ))
            ),
            'mono_fonts' => array_merge(
                ['liberationmono'],
                $defaults['mono_fonts'] ?? [],
                array_keys(array_filter(
                    self::translations(),
                    static fn(string $target): bool => $target === 'liberationmono'
                ))
            ),
        ];
    }

    /** Ontbreekt er een bestand, dan is de opmaak stilletjes verkeerd. */
    public static function missing(): array
    {
        $missing = [];
        foreach (self::data() as $family => $styles) {
            foreach ($styles as $file) {
                if (!is_file(self::directory() . '/' . $file)) {
                    $missing[] = $file;
                }
            }
        }
        return $missing;
    }
}
