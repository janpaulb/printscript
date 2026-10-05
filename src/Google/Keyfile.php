<?php

declare(strict_types=1);

namespace PrintScript\Google;

/**
 * Het sleutelbestand van het serviceaccount opzoeken en inlezen.
 *
 * De sleutel hoort buiten de webmap te staan. Dat is de hele reden dat deze
 * klasse bestaat: niet om een pad op te zoeken — dat is één regel — maar om
 * te weigeren als het pad ergens uitkomt waar een bezoeker het bestand kan
 * downloaden. Want dat gaat stil mis. Het werkt dan gewoon, en pas als iemand
 * het bestand opvraagt blijkt dat je sleutel al die tijd te halen was.
 *
 * Op DirectAdmin-hosting zit daar één valstrik in. Naast public_html staat
 * vaak private_html, en die naam liegt: dat is van oudsher de webmap voor
 * https, en tegenwoordig meestal een snelkoppeling naar public_html. Wat je
 * daarin zet, staat dus gewoon online. De echt besloten plek is de map eróm
 * heen — de domeinmap zelf.
 */
final class Keyfile
{
    /**
     * Mappen waarin élk .json-bestand als sleutel telt.
     *
     * Niet één vaste bestandsnaam. Google geeft zijn sleutel een eigen naam
     * mee en iedereen laat die staan — titelaar-serviceaccount.json,
     * project-abc123.json. Wie zo'n bestand in deze map zet heeft precies
     * gedaan wat de bedoeling was; dan moet het ook werken.
     */
    private const FOLDERS = ['printscript-keys', 'private'];

    /** Namen waar we eerst naar kijken, voor als er meer in de map staat. */
    private const PREFERRED = [
        'printscript-keys/google.json',
        'private/printscript-google.json',
    ];

    public const VARIABLE = 'PRINTSCRIPT_GOOGLE_KEYFILE';

    /**
     * Het pad dat we gaan gebruiken, of null als er geen sleutel is.
     *
     * Geen sleutel is geen fout: dan werkt printscript zoals het altijd al
     * werkte, met een openbare link of een geüpload bestand.
     */
    public static function locate(): ?string
    {
        foreach (self::candidates() as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        foreach (self::searchFolders() as $folder) {
            $found = glob("$folder/*.json") ?: [];
            if ($found !== []) {
                sort($found);
                return $found[0];
            }
        }

        return null;
    }

    /**
     * De paden die we letterlijk proberen — ook voor de foutmelding.
     *
     * @return string[]
     */
    public static function candidates(): array
    {
        $set = getenv(self::VARIABLE) ?: ($_SERVER[self::VARIABLE] ?? '');
        $paths = is_string($set) && trim($set) !== '' ? [trim($set)] : [];

        foreach (self::parentsOfTheWebRoot() as $parent) {
            foreach (self::PREFERRED as $preferred) {
                $paths[] = "$parent/$preferred";
            }
        }
        return array_values(array_unique($paths));
    }

    /**
     * De mappen waarin elk .json-bestand meetelt.
     *
     * @return string[]
     */
    public static function searchFolders(): array
    {
        $folders = [];
        foreach (self::parentsOfTheWebRoot() as $parent) {
            foreach (self::FOLDERS as $folder) {
                $folders[] = "$parent/$folder";
            }
        }
        return array_values(array_unique($folders));
    }

    /** Waar we gezocht hebben, in mensentaal — voor als er niets gevonden is. */
    public static function searchedIn(): string
    {
        $lines = [];
        foreach (self::candidates() as $path) {
            $lines[] = $path;
        }
        foreach (self::searchFolders() as $folder) {
            $lines[] = "$folder/*.json  (elk .json-bestand in deze map)";
        }
        return implode("\n  ", array_unique($lines));
    }

    /**
     * De mappen naast de webmap: daar hoort de sleutel.
     *
     * Twee kandidaten, want printscript staat zelden in de webmap zélf maar
     * meestal in een submap daarvan (public_html/print). We gaan uit van de
     * webmap die de server doorgeeft, en van de map waar printscript zelf
     * staat — net zolang tot we buiten de webmap zijn.
     *
     * @return string[]
     */
    private static function parentsOfTheWebRoot(): array
    {
        $parents = [];

        $root = self::realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if ($root !== null) {
            $parents[] = dirname($root);
        }

        // Vanaf onze eigen map omhoog tot net buiten de webmap. Zonder
        // DOCUMENT_ROOT (op de commandline) nemen we de map boven de installatie.
        $here = self::realpath(dirname(__DIR__, 2));
        if ($here !== null) {
            $parents[] = $root !== null && str_starts_with($here, $root . '/')
                ? dirname($root)
                : dirname($here);
        }

        return array_values(array_unique(array_filter($parents, static fn($p) => $p !== '/')));
    }

    /**
     * De sleutel inlezen en nakijken.
     *
     * @return array<string, string> het serviceaccount zoals Google het schrijft
     */
    public static function read(string $path): array
    {
        $resolved = self::realpath($path);
        if ($resolved === null || !is_file($resolved)) {
            throw new GoogleAuthException(sprintf(
                "Het sleutelbestand staat niet op %s.\nGezocht op:\n  %s",
                $path,
                self::searchedIn()
            ));
        }

        self::refuseInsideTheWebRoot($resolved);

        $raw = @file_get_contents($resolved);
        if ($raw === false || trim($raw) === '') {
            throw new GoogleAuthException(
                "Het sleutelbestand kon niet gelezen worden: $resolved. "
                . 'Controleer de rechten (chmod 600 en eigenaar van het account).'
            );
        }

        $keys = json_decode($raw, true);
        if (!is_array($keys)) {
            throw new GoogleAuthException(
                "Het sleutelbestand is geen geldige JSON: $resolved."
            );
        }

        foreach (['client_email', 'private_key', 'token_uri'] as $field) {
            if (!isset($keys[$field]) || !is_string($keys[$field]) || $keys[$field] === '') {
                throw new GoogleAuthException(
                    "In het sleutelbestand ontbreekt \"$field\". Dit moet het "
                    . 'JSON-bestand zijn dat Google aanmaakt bij een serviceaccount '
                    . '("type": "service_account").'
                );
            }
        }

        return $keys;
    }

    /**
     * De bewaking waar het om begonnen was.
     *
     * Niet alleen op naam: private_html kan een snelkoppeling naar public_html
     * zijn, en dan ziet het pad er onschuldig uit terwijl het bestand gewoon
     * online staat. Daarom vergelijken we uitgezochte paden, waarin
     * snelkoppelingen al zijn gevolgd.
     */
    private static function refuseInsideTheWebRoot(string $resolved): void
    {
        $root = self::realpath((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if ($root === null || $root === '/' || !str_starts_with($resolved, $root . '/')) {
            return;
        }

        throw new GoogleAuthException(sprintf(
            "De sleutel staat in de webmap en is dus te downloaden:\n  %s\n"
            . "ligt in %s.\n\nZet hem buiten de webmap, bijvoorbeeld in:\n  %s\n"
            . 'Let op: private_html is géén besloten map — dat is meestal een '
            . 'snelkoppeling naar public_html.',
            $resolved,
            $root,
            dirname($root) . '/' . self::PREFERRED[0]
        ));
    }

    /** Staat het bestand te ruim open? Geen reden om te stoppen, wel om iets te zeggen. */
    public static function looseReadRights(string $path): bool
    {
        $mode = @fileperms($path);
        return $mode !== false && ($mode & 0o044) !== 0;
    }

    private static function realpath(string $path): ?string
    {
        if ($path === '') {
            return null;
        }
        $resolved = @realpath($path);
        return $resolved === false ? null : rtrim($resolved, '/');
    }
}
