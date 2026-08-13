<?php

declare(strict_types=1);

namespace PrintScript\Tests;

/**
 * Leest een PDF uit, zodat de tests kunnen controleren wat er werkelijk op
 * papier komt in plaats van welke functie is aangeroepen.
 *
 * Twee dingen maken dat lastig, en beide worden hier afgehandeld: de inhoud
 * van een pagina is samengeperst, en de tekst bestaat uit glyph-nummers van
 * een subset-lettertype. De vertaling naar leesbare tekens staat in de
 * ToUnicode-tabel die de PDF zelf meelevert.
 */
final class PdfInspector
{
    private string $expanded;
    private array $unicode;

    public function __construct(private string $pdf)
    {
        $this->expanded = self::inflate($pdf);
        $this->unicode = self::readUnicodeMap($this->expanded);
    }

    /** @return string[] de tekst per pagina, in de volgorde van het document */
    public function pageTexts(): array
    {
        $pages = [];
        foreach ($this->contentStreams() as $stream) {
            $text = '';
            preg_match_all('~(\((?:[^()\\\\]|\\\\.)*\)|<[0-9A-Fa-f\s]*>)~', $stream, $chunks);
            foreach ($chunks[1] as $chunk) {
                $text .= $this->decode(substr($chunk, 1, -1), $chunk[0] === '<');
            }
            // Een harde spatie is voor de lezer gewoon een spatie.
            $text = str_replace("\u{00A0}", ' ', $text);
            $pages[] = trim((string) preg_replace('~[ \t]+~', ' ', $text));
        }
        return $pages;
    }

    public function text(): string
    {
        return implode("\n", $this->pageTexts());
    }

    public function pageCount(): int
    {
        return count($this->contentStreams());
    }

    /**
     * Het aantal afbeeldingen dat elke pagina daadwerkelijk tekent.
     *
     * Niet het aantal dat in de bronnen staat: een PDF deelt zijn bronnen
     * tussen pagina's, dus alleen de Do-opdrachten in de paginastroom tellen.
     *
     * @return int[]
     */
    public function imagesPerPage(): array
    {
        $counts = [];
        foreach ($this->contentStreams() as $stream) {
            preg_match_all('~/[A-Za-z0-9#_.]+\s+Do\b~', $stream, $draws);
            $counts[] = count($draws[0]);
        }
        return $counts;
    }

    /** @return array<int, array{0: float, 1: float}> breedte en hoogte per pagina, in punten */
    public function pageSizes(): array
    {
        preg_match_all('~/Type\s*/Page[^s].*?/MediaBox\s*\[([^\]]+)\]~s', $this->pdf, $matches);
        if ($matches[1] === []) {
            // mPDF zet de MediaBox soms voor het Type; dan alle boxen behalve de boom.
            preg_match_all('~/MediaBox\s*\[([^\]]+)\]~', $this->pdf, $matches);
            array_shift($matches[1]);
        }
        $sizes = [];
        foreach ($matches[1] as $box) {
            $numbers = preg_split('~\s+~', trim($box)) ?: [];
            if (count($numbers) === 4) {
                $sizes[] = [(float) $numbers[2], (float) $numbers[3]];
            }
        }
        return $sizes;
    }

    // ── Binnenwerk ───────────────────────────────────────────────────────────

    /** @return string[] */
    private function contentStreams(): array
    {
        preg_match_all('~stream\n(.*?)\nendstream~s', $this->expanded, $streams);
        return array_values(array_filter(
            $streams[1],
            static fn(string $stream): bool => str_contains($stream, 'BT')
        ));
    }

    private static function inflate(string $pdf): string
    {
        return (string) preg_replace_callback(
            '~stream\r?\n(.*?)\r?\nendstream~s',
            static function (array $match): string {
                $out = @gzuncompress($match[1]);
                if ($out === false) {
                    $out = @gzinflate($match[1]);
                }
                return "stream\n" . ($out !== false ? $out : $match[1]) . "\nendstream";
            },
            $pdf
        );
    }

    /** @return array<string, string> glyph-code => teken */
    private static function readUnicodeMap(string $pdf): array
    {
        $map = [];
        if (preg_match_all('~beginbfchar(.*?)endbfchar~s', $pdf, $blocks)) {
            foreach ($blocks[1] as $block) {
                preg_match_all('~<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>~', $block, $pairs, PREG_SET_ORDER);
                foreach ($pairs as $pair) {
                    $map[strtolower($pair[1])] = self::fromUtf16Hex($pair[2]);
                }
            }
        }
        if (preg_match_all('~beginbfrange(.*?)endbfrange~s', $pdf, $blocks)) {
            foreach ($blocks[1] as $block) {
                preg_match_all(
                    '~<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>\s*<([0-9A-Fa-f]+)>~',
                    $block, $ranges, PREG_SET_ORDER
                );
                foreach ($ranges as $range) {
                    $from = hexdec($range[1]);
                    $to = min(hexdec($range[2]), $from + 1024);
                    $base = hexdec($range[3]);
                    for ($code = $from; $code <= $to; $code++) {
                        $map[sprintf('%04x', $code)] = mb_chr((int) ($base + $code - $from), 'UTF-8');
                    }
                }
            }
        }
        return $map;
    }

    private static function fromUtf16Hex(string $hex): string
    {
        $text = '';
        foreach (str_split($hex, 4) as $chunk) {
            $text .= mb_chr((int) hexdec(str_pad($chunk, 4, '0')), 'UTF-8');
        }
        return $text;
    }

    private function decode(string $raw, bool $isHex): string
    {
        if ($isHex) {
            $out = '';
            foreach (str_split(strtolower((string) preg_replace('~\s+~', '', $raw)), 4) as $code) {
                $out .= $this->unicode[$code] ?? '';
            }
            return $out;
        }

        $bytes = (string) preg_replace_callback(
            '~\\\\([nrtbf()\\\\]|[0-7]{1,3})~',
            static function (array $match): string {
                $named = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C"];
                if (isset($named[$match[1]])) {
                    return $named[$match[1]];
                }
                return ctype_digit($match[1]) ? chr((int) octdec($match[1])) : $match[1];
            },
            $raw
        );

        if (strlen($bytes) >= 2 && str_contains($bytes, "\0")) {
            $out = '';
            foreach (str_split($bytes, 2) as $pair) {
                $code = strtolower(bin2hex(str_pad($pair, 2, "\0")));
                $out .= $this->unicode[$code]
                    ?? (string) mb_convert_encoding($pair, 'UTF-8', 'UTF-16BE');
            }
            return $out;
        }
        return $bytes;
    }
}
