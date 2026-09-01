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

    /**
     * De lettertypen waarvan het bestand er werkelijk staat.
     *
     * mPDF wordt geleverd met een lijst van ruim honderd lettertypen, maar met
     * de bestanden van een handvol daarvan. Wij dunnen dat nog verder uit: het
     * uitrolpakket moet op een gewone hosting passen, dus alles wat we niet
     * gebruiken gaat eruit.
     *
     * Die twee lijsten moeten wel bij elkaar blijven. Doen ze dat niet, dan
     * gaat het pas mis op het moment dat iemand een letter gebruikt die
     * nergens in staat — een Chinees teken, een emoji in een script — en dan
     * stopt de hele conversie met een foutmelding over een bestand waar de
     * gebruiker part noch deel aan heeft. Dus houden we hier alleen over wat
     * er echt is.
     *
     * @param string[] $directories
     * @param array<string, array<string, mixed>> $fontdata
     * @return array<string, array<string, mixed>>
     */
    private static function available(array $fontdata, array $directories): array
    {
        $exists = static function (string $file) use ($directories): bool {
            foreach ($directories as $directory) {
                if (is_file(rtrim($directory, '/\\') . '/' . $file)) {
                    return true;
                }
            }
            return false;
        };

        $available = [];
        foreach ($fontdata as $family => $styles) {
            if (!isset($styles['R']) || !is_string($styles['R']) || !$exists($styles['R'])) {
                continue;   // zonder rechte variant valt er niets te zetten
            }
            foreach (['B', 'I', 'BI'] as $style) {
                if (isset($styles[$style]) && is_string($styles[$style])
                    && !$exists($styles[$style])) {
                    unset($styles[$style], $styles['TTCfontID'][$style]);
                }
            }
            $available[$family] = $styles;
        }
        return $available;
    }

    /** De instellingen die mPDF nodig heeft om dit alles te gebruiken. */
    public static function configuration(): array
    {
        $defaults = (new \Mpdf\Config\FontVariables())->getDefaults();
        $directories = array_merge(
            (new \Mpdf\Config\ConfigVariables())->getDefaults()['fontDir'],
            [self::directory()]
        );
        $fontdata = self::available($defaults['fontdata'] + self::data(), $directories);

        return [
            'fontDir' => $directories,
            'fontdata' => $fontdata,
            // De lettertypen waar mPDF op terugvalt voor een teken dat in het
            // gekozen lettertype niet bestaat. Standaard staat sun-exta hier
            // ook in; dat bestand leveren we niet mee, en dan valt de conversie
            // om over een Chinees teken of een emoji.
            'backupSubsFont' => array_values(array_filter(
                (array) ($defaults['backupSubsFont'] ?? []),
                static fn(string $name): bool => isset($fontdata[$name])
            )),
            'backupSIPFont' => isset($fontdata[$defaults['backupSIPFont'] ?? ''])
                ? $defaults['backupSIPFont']
                : '',
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
