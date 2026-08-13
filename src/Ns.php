<?php

declare(strict_types=1);

namespace PrintScript;

/**
 * OOXML-naamruimten en de rekeneenheden van Word.
 *
 * Word bewaart lengtes in twintigsten van een punt (twips), tekengroottes in
 * halve punten, randdiktes in achtsten van een punt en tekeningen in EMU's.
 */
final class Ns
{
    public const W   = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    public const R   = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    public const A   = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    public const PIC = 'http://schemas.openxmlformats.org/drawingml/2006/picture';
    public const WP  = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';
    public const MC  = 'http://schemas.openxmlformats.org/markup-compatibility/2006';
    public const V   = 'urn:schemas-microsoft-com:vml';
    public const REL = 'http://schemas.openxmlformats.org/package/2006/relationships';
    public const CT  = 'http://schemas.openxmlformats.org/package/2006/content-types';

    /** Voorvoegsels zoals ze in XPath-expressies worden gebruikt. */
    public const PREFIXES = [
        'w'   => self::W,
        'r'   => self::R,
        'a'   => self::A,
        'pic' => self::PIC,
        'wp'  => self::WP,
        'mc'  => self::MC,
        'v'   => self::V,
        'rel' => self::REL,
        'ct'  => self::CT,
    ];

    public static function registerOn(\DOMXPath $xpath): void
    {
        foreach (self::PREFIXES as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }
    }

    // ── Eenheden ─────────────────────────────────────────────────────────────

    public static function twipsToPt(?string $value, ?float $default = null): ?float
    {
        return is_numeric($value) ? ((float) $value) / 20.0 : $default;
    }

    public static function halfPointsToPt(?string $value, ?float $default = null): ?float
    {
        return is_numeric($value) ? ((float) $value) / 2.0 : $default;
    }

    public static function eighthPointsToPt(?string $value, ?float $default = null): ?float
    {
        return is_numeric($value) ? ((float) $value) / 8.0 : $default;
    }

    public static function emuToPt(?string $value, ?float $default = null): ?float
    {
        return is_numeric($value) ? ((float) $value) / 12700.0 : $default;
    }

    /** 12.0 wordt '12pt', 12.25 wordt '12.25pt'. */
    public static function pt(float $value): string
    {
        $rounded = round($value, 2);
        if (abs($rounded - round($rounded)) < 0.001) {
            return ((int) round($rounded)) . 'pt';
        }
        return rtrim(rtrim(number_format($rounded, 2, '.', ''), '0'), '.') . 'pt';
    }

    /**
     * Aan/uit-schakelaars in OOXML: het element zelf betekent "aan",
     * w:val="0" betekent "uit", en geen element betekent "erven".
     */
    public static function toggle(?\DOMElement $element, bool $default = false): bool
    {
        if ($element === null) {
            return $default;
        }
        $value = $element->getAttributeNS(self::W, 'val');
        if ($value === '') {
            return true;
        }
        return !in_array($value, ['0', 'false', 'off'], true);
    }

    public static function attr(?\DOMElement $element, string $name, ?string $default = null): ?string
    {
        if ($element === null) {
            return $default;
        }
        [$prefix, $local] = explode(':', $name, 2);
        $value = $element->getAttributeNS(self::PREFIXES[$prefix], $local);
        return $value === '' ? $default : $value;
    }

    /** Een kleur uit Word omzetten naar CSS; 'auto' levert niets op. */
    public static function colour(?string $value): ?string
    {
        if ($value === null || $value === '' || strtolower($value) === 'auto') {
            return null;
        }
        $value = ltrim(trim($value), '#');
        return preg_match('~^[0-9A-Fa-f]{6}$~', $value) ? '#' . strtoupper($value) : null;
    }
}
