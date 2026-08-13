<?php

declare(strict_types=1);

namespace PrintScript;

/**
 * styles.xml plattgeslagen.
 *
 * De opmaak van Word is een cascade: standaardwaarden van het document, dan de
 * alineastijl via zijn basedOn-keten, dan de nummering, dan wat er met de hand
 * is ingesteld. Deze klasse maakt van de eerste stappen een geordende lijst
 * eigenschappen; de renderer loopt die af en maakt er CSS van.
 */
final class Styles
{
    private const MAX_CHAIN = 24;  // vangt kringetjes in handmatig bewerkte documenten

    public ?string $defaultParagraphStyle = null;
    public ?string $defaultCharacterStyle = null;
    public ?\DOMElement $documentDefaultParagraph = null;
    public ?\DOMElement $documentDefaultRun = null;

    /** @var array<string, array<string, mixed>> */
    private array $styles = [];

    public function __construct(Package $package, ?string $part)
    {
        $xpath = $part === null ? null : $package->xpath($part);
        if ($xpath === null) {
            return;
        }
        $root = $xpath->document->documentElement;
        if ($root === null) {
            return;
        }

        $this->documentDefaultRun = self::first($xpath, 'w:docDefaults/w:rPrDefault/w:rPr', $root);
        $this->documentDefaultParagraph = self::first($xpath, 'w:docDefaults/w:pPrDefault/w:pPr', $root);

        foreach ($xpath->query('w:style', $root) ?: [] as $style) {
            if (!$style instanceof \DOMElement) {
                continue;
            }
            $id = Ns::attr($style, 'w:styleId');
            if ($id === null) {
                continue;
            }
            $type = Ns::attr($style, 'w:type', 'paragraph');
            $name = Ns::attr(self::first($xpath, 'w:name', $style), 'w:val', '') ?? '';

            $this->styles[$id] = [
                'id'      => $id,
                'type'    => $type,
                'name'    => $name,
                'basedOn' => Ns::attr(self::first($xpath, 'w:basedOn', $style), 'w:val'),
                'pPr'     => self::first($xpath, 'w:pPr', $style),
                'rPr'     => self::first($xpath, 'w:rPr', $style),
                'tblPr'   => self::first($xpath, 'w:tblPr', $style),
                'numId'   => Ns::attr(self::first($xpath, 'w:pPr/w:numPr/w:numId', $style), 'w:val'),
                'numLevel' => Ns::attr(self::first($xpath, 'w:pPr/w:numPr/w:ilvl', $style), 'w:val'),
                'heading' => self::detectHeadingLevel($id, $name),
            ];

            if (in_array(Ns::attr($style, 'w:default'), ['1', 'true', 'on'], true)) {
                if ($type === 'paragraph' && $this->defaultParagraphStyle === null) {
                    $this->defaultParagraphStyle = $id;
                } elseif ($type === 'character' && $this->defaultCharacterStyle === null) {
                    $this->defaultCharacterStyle = $id;
                }
            }
        }
    }

    public function has(?string $id): bool
    {
        return $id !== null && isset($this->styles[$id]);
    }

    /** @return string[] */
    public function ids(): array
    {
        return array_keys($this->styles);
    }

    public function get(?string $id): ?array
    {
        return $id === null ? null : ($this->styles[$id] ?? null);
    }

    /**
     * De stijlen van de wortel van de basedOn-keten tot aan $id.
     *
     * @return array<int, array<string, mixed>>
     */
    public function chain(?string $id): array
    {
        $chain = [];
        $seen = [];
        $current = $this->get($id);
        while ($current !== null && !isset($seen[$current['id']]) && count($chain) < self::MAX_CHAIN) {
            $seen[$current['id']] = true;
            $chain[] = $current;
            $current = $this->get($current['basedOn']);
        }
        return array_reverse($chain);
    }

    /** @return \DOMElement[] alinea-eigenschappen, basis eerst */
    public function paragraphProperties(?string $id): array
    {
        return array_values(array_filter(array_column($this->chain($id), 'pPr')));
    }

    /** @return \DOMElement[] tekeneigenschappen, basis eerst */
    public function runProperties(?string $id): array
    {
        return array_values(array_filter(array_column($this->chain($id), 'rPr')));
    }

    /** @return \DOMElement[] tabeleigenschappen, basis eerst */
    public function tableProperties(?string $id): array
    {
        return array_values(array_filter(array_column($this->chain($id), 'tblPr')));
    }

    public function headingLevel(?string $id): ?int
    {
        foreach (array_reverse($this->chain($id)) as $style) {
            if ($style['heading'] !== null) {
                return $style['heading'];
            }
        }
        return null;
    }

    /** @return array{0: ?string, 1: int} de nummering die een alineastijl meebrengt */
    public function numbering(?string $id): array
    {
        $numId = null;
        $level = 0;
        foreach ($this->chain($id) as $style) {
            if ($style['numId'] !== null) {
                $numId = $style['numId'];
                $level = (int) ($style['numLevel'] ?? 0);
            }
        }
        return [$numId, $level];
    }

    /** "heading 2", "Heading2" en "Kop2" (met de naam "heading 2") tellen alle drie. */
    private static function detectHeadingLevel(string $id, string $name): ?int
    {
        foreach ([$name, $id] as $candidate) {
            if ($candidate === '') {
                continue;
            }
            $normalised = strtolower(trim($candidate));
            if (preg_match('~^heading\s*([1-9])$~', $normalised, $match)) {
                return (int) $match[1];
            }
        }
        return null;
    }

    private static function first(\DOMXPath $xpath, string $expression, \DOMNode $context): ?\DOMElement
    {
        $nodes = $xpath->query($expression, $context);
        $node = $nodes === false ? null : $nodes->item(0);
        return $node instanceof \DOMElement ? $node : null;
    }
}
