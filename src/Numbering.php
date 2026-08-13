<?php

declare(strict_types=1);

namespace PrintScript;

/**
 * numbering.xml plattgeslagen: welk teken of nummer hoort bij welk niveau.
 */
final class Numbering
{
    /** @var array<string, array<int, \DOMElement>> abstractNumId => niveau => w:lvl */
    private array $abstract = [];

    /** @var array<string, string> numId => abstractNumId */
    private array $numToAbstract = [];

    /** @var array<string, \DOMElement> "numId:niveau" => w:lvl */
    private array $overrides = [];

    /** @var array<string, string> abstractNumId => stijl-id */
    private array $styleLinks = [];

    public function __construct(Package $package, ?string $part, private ?Styles $styles = null)
    {
        $xpath = $part === null ? null : $package->xpath($part);
        if ($xpath === null) {
            return;
        }
        $root = $xpath->document->documentElement;
        if ($root === null) {
            return;
        }

        foreach ($xpath->query('w:abstractNum', $root) ?: [] as $abstract) {
            if (!$abstract instanceof \DOMElement) {
                continue;
            }
            $id = Ns::attr($abstract, 'w:abstractNumId');
            if ($id === null) {
                continue;
            }
            $levels = [];
            foreach ($xpath->query('w:lvl', $abstract) ?: [] as $level) {
                if ($level instanceof \DOMElement) {
                    $levels[(int) (Ns::attr($level, 'w:ilvl') ?? '0')] = $level;
                }
            }
            $this->abstract[$id] = $levels;

            $link = $xpath->query('w:numStyleLink', $abstract)?->item(0);
            if ($link instanceof \DOMElement) {
                $target = Ns::attr($link, 'w:val');
                if ($target !== null) {
                    $this->styleLinks[$id] = $target;
                }
            }
        }

        foreach ($xpath->query('w:num', $root) ?: [] as $num) {
            if (!$num instanceof \DOMElement) {
                continue;
            }
            $numId = Ns::attr($num, 'w:numId');
            $abstractNode = $xpath->query('w:abstractNumId', $num)?->item(0);
            $abstractId = $abstractNode instanceof \DOMElement ? Ns::attr($abstractNode, 'w:val') : null;
            if ($numId === null || $abstractId === null) {
                continue;
            }
            $this->numToAbstract[$numId] = $abstractId;

            foreach ($xpath->query('w:lvlOverride', $num) ?: [] as $override) {
                if (!$override instanceof \DOMElement) {
                    continue;
                }
                $level = (int) (Ns::attr($override, 'w:ilvl') ?? '0');
                $node = $xpath->query('w:lvl', $override)?->item(0);
                if ($node instanceof \DOMElement) {
                    $this->overrides["$numId:$level"] = $node;
                }
            }
        }
    }

    public function isEmpty(): bool
    {
        return $this->numToAbstract === [];
    }

    public function level(?string $numId, int $level): ?\DOMElement
    {
        if ($numId === null || $numId === '' || $numId === '0') {
            return null;
        }
        if (isset($this->overrides["$numId:$level"])) {
            return $this->overrides["$numId:$level"];
        }

        $abstractId = $this->numToAbstract[$numId] ?? null;
        $seen = [];
        while ($abstractId !== null && !isset($seen[$abstractId])) {
            $seen[$abstractId] = true;
            $link = $this->styleLinks[$abstractId] ?? null;
            if ($link !== null && $this->styles !== null) {
                [$linkedNum] = $this->styles->numbering($link);
                $next = $linkedNum === null ? null : ($this->numToAbstract[$linkedNum] ?? null);
                if ($next !== null && !isset($seen[$next])) {
                    $abstractId = $next;
                    continue;
                }
            }
            return $this->abstract[$abstractId][$level] ?? null;
        }
        return null;
    }

    public function format(?string $numId, int $level): string
    {
        return $this->childValue($numId, $level, 'numFmt') ?? 'decimal';
    }

    public function template(?string $numId, int $level): string
    {
        return $this->childValue($numId, $level, 'lvlText') ?? '';
    }

    public function startAt(?string $numId, int $level): int
    {
        $value = $this->childValue($numId, $level, 'start');
        return is_numeric($value) ? (int) $value : 1;
    }

    public function indent(?string $numId, int $level): ?\DOMElement
    {
        return $this->descendant($numId, $level, ['pPr', 'ind']);
    }

    public function runProperties(?string $numId, int $level): ?\DOMElement
    {
        return $this->descendant($numId, $level, ['rPr']);
    }

    private function childValue(?string $numId, int $level, string $name): ?string
    {
        $node = $this->descendant($numId, $level, [$name]);
        return $node === null ? null : Ns::attr($node, 'w:val');
    }

    /** @param string[] $path */
    private function descendant(?string $numId, int $level, array $path): ?\DOMElement
    {
        $node = $this->level($numId, $level);
        foreach ($path as $step) {
            if ($node === null) {
                return null;
            }
            $found = null;
            foreach ($node->childNodes as $child) {
                if ($child instanceof \DOMElement
                    && $child->localName === $step
                    && $child->namespaceURI === Ns::W) {
                    $found = $child;
                    break;
                }
            }
            $node = $found;
        }
        return $node;
    }

    /** Nummer opmaken in de stijl van Word: 1, a, i, A, I of 01. */
    public static function formatNumber(int $value, string $format): string
    {
        return match ($format) {
            'decimalZero' => sprintf('%02d', $value),
            'lowerLetter' => self::letters($value),
            'upperLetter' => strtoupper(self::letters($value)),
            'lowerRoman'  => self::roman($value),
            'upperRoman'  => strtoupper(self::roman($value)),
            'none'        => '',
            default       => (string) $value,
        };
    }

    private static function letters(int $value): string
    {
        $letters = '';
        $number = max($value, 1);
        while ($number > 0) {
            $remainder = ($number - 1) % 26;
            $letters = chr(ord('a') + $remainder) . $letters;
            $number = intdiv($number - 1, 26);
        }
        return $letters;
    }

    private static function roman(int $value): string
    {
        $table = [
            1000 => 'm', 900 => 'cm', 500 => 'd', 400 => 'cd', 100 => 'c',
            90 => 'xc', 50 => 'l', 40 => 'xl', 10 => 'x', 9 => 'ix',
            5 => 'v', 4 => 'iv', 1 => 'i',
        ];
        $number = max($value, 1);
        $out = '';
        foreach ($table as $amount => $numeral) {
            while ($number >= $amount) {
                $out .= $numeral;
                $number -= $amount;
            }
        }
        return $out;
    }
}
