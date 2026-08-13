<?php

declare(strict_types=1);

namespace PrintScript;

/**
 * DOCX naar HTML en CSS.
 *
 * Dit is een rechtstreekse lezer van WordprocessingML: hij loopt de OOXML-boom
 * af en maakt er HTML van waarvan de CSS de opmaak van het document nabootst.
 *
 * De opzet
 * --------
 * - Stijlen van Word worden CSS-klassen (.ps-s-<stijl>) met de basedOn-keten
 *   al platgeslagen; wat met de hand is ingesteld wordt een style-attribuut.
 *   De cascade van CSS doet daarna het werk dat Word ook zou doen.
 * - Kop- en voetteksten komen apart te staan, per sectie. Welke vorm ze
 *   uiteindelijk krijgen, bepaalt de PDF-motor.
 * - PAGE- en NUMPAGES-velden worden merktekens die de motor invult, zodat er
 *   staat wat er werkelijk geprint wordt en niet wat Word ooit onthield.
 * - Wijzigingen worden opgelost: invoegingen blijven, schrappingen verdwijnen.
 */
final class HtmlRenderer
{
    private const DEFAULT_PAGE_WIDTH  = 595.3;   // A4 staand, in punten
    private const DEFAULT_PAGE_HEIGHT = 841.9;
    private const DEFAULT_MARGIN      = 72.0;
    private const DEFAULT_TAB         = 36.0;

    public const PAGE_NUMBER_MARK = '<span class="ps-pagenum"></span>';
    public const PAGE_COUNT_MARK  = '<span class="ps-pagecount"></span>';
    public const PAGE_BREAK_MARK  = '<!--ps-pagebreak-->';

    private const UNSUPPORTED_IMAGES = ['emf', 'wmf', 'x-emf', 'x-wmf'];

    private const ALIGNMENT = [
        'left' => 'left', 'start' => 'left',
        'right' => 'right', 'end' => 'right',
        'center' => 'center',
        'both' => 'justify', 'distribute' => 'justify',
    ];

    private const UNDERLINE = [
        'single' => 'solid', 'words' => 'solid', 'double' => 'double',
        'dotted' => 'dotted', 'dottedHeavy' => 'dotted',
        'dash' => 'dashed', 'dashedHeavy' => 'dashed', 'dashLong' => 'dashed',
        'wave' => 'solid', 'wavyHeavy' => 'solid', 'wavyDouble' => 'solid',
        'thick' => 'solid',
    ];

    private const BORDER_STYLE = [
        'single' => 'solid', 'thick' => 'solid', 'double' => 'double',
        'dotted' => 'dotted', 'dashed' => 'dashed', 'dashSmallGap' => 'dashed',
        'dotDash' => 'dashed', 'dotDotDash' => 'dashed',
        'wave' => 'solid', 'triple' => 'double',
    ];

    /** Opsommingstekens die Word als Symbol- of Wingdings-teken opslaat. */
    private const BULLETS = [
        "\u{F0B7}" => '•', "\u{F0A7}" => '▪', "\u{F0D8}" => '➢',
        "\u{F076}" => '❖', "\u{F0FC}" => '✔', "\u{F0E0}" => '→',
        "\u{F02D}" => '–', "\u{F0A8}" => '■', 'o' => '◦',
    ];

    private const SERIF_FONTS = [
        'times new roman', 'times', 'georgia', 'garamond', 'cambria',
        'book antiqua', 'palatino', 'palatino linotype', 'merriweather',
        'pt serif', 'noto serif', 'liberation serif', 'eb garamond',
    ];
    private const MONO_FONTS = [
        'courier new', 'courier', 'consolas', 'menlo', 'monaco',
        'roboto mono', 'source code pro', 'liberation mono', 'inconsolata',
    ];

    private Styles $styles;
    private Numbering $numbering;
    private ?string $mainPart;
    private ?\DOMElement $settings = null;
    private array $themeFonts = [];

    private array $rules = [];
    private array $warnings = [];
    private array $warned = [];
    private array $imageIds = [];
    private int $imageSequence = 0;
    private array $listCounters = [];
    private float $defaultTab = self::DEFAULT_TAB;
    private float $contentWidth = self::DEFAULT_PAGE_WIDTH - 2 * self::DEFAULT_MARGIN;

    public function __construct(
        private Package $package,
        private Options $options = new Options(),
    ) {
        $this->mainPart = $package->mainPartName();
        $main = $this->mainPart ?? '';

        $this->styles = new Styles($package, $package->relatedPartOfKind($main, 'styles'));
        $this->numbering = new Numbering(
            $package,
            $package->relatedPartOfKind($main, 'numbering'),
            $this->styles
        );

        $settings = $package->document($package->relatedPartOfKind($main, 'settings'));
        $this->settings = $settings?->documentElement;
        $this->themeFonts = $this->readThemeFonts();
        $this->defaultTab = Ns::twipsToPt(
            Ns::attr($this->childOf($this->settings, 'defaultTabStop'), 'w:val'),
            self::DEFAULT_TAB
        ) ?? self::DEFAULT_TAB;
    }

    public static function render(Package $package, Options $options = new Options()): RenderedDocument
    {
        return (new self($package, $options))->build();
    }

    public function build(): RenderedDocument
    {
        $this->emitBaseRules();
        $this->emitStyleRules();

        $xpath = $this->package->xpath((string) $this->mainPart);
        $body = $xpath?->query('/w:document/w:body')?->item(0);
        if (!$body instanceof \DOMElement) {
            throw new InvalidDocxException('Het document bevat geen tekst (w:body ontbreekt).');
        }

        $sections = [];
        foreach ($this->splitSections($body) as $index => [$children, $sectionProperties]) {
            $sections[] = $this->renderSection($index, $children, $sectionProperties);
        }

        return new RenderedDocument(
            implode("\n", $this->rules),
            $sections,
            $this->warnings,
            $this->imageIds
        );
    }

    // ── Opmaakregels ─────────────────────────────────────────────────────────

    private function emitBaseRules(): void
    {
        $defaults = [
            'font-family' => '"DejaVu Sans", Arial, sans-serif',
            'font-size'   => '11pt',
            'color'       => '#000000',
        ];
        $defaults = array_merge($defaults, $this->runCss([$this->styles->documentDefaultRun]));
        $this->rules[] = 'body { ' . self::declarations($defaults) . ' }';

        $paragraph = $this->paragraphCss([$this->styles->documentDefaultParagraph]);
        $paragraph += ['margin-top' => '0', 'margin-bottom' => '0'];
        $this->rules[] = '.ps-p { ' . self::declarations($paragraph) . ' }';

        $this->rules[] = <<<'CSS'
            .ps-num { }
            .ps-tab { }
            .ps-hf { width: 100%; }
            .ps-hf td { padding: 0; vertical-align: top; }
            .ps-table { border-collapse: collapse; }
            .ps-cell { vertical-align: top; padding: 2pt 4pt; }
            .ps-textbox { border: 0.75pt solid #999999; padding: 4pt; }
            CSS;
    }

    private function emitStyleRules(): void
    {
        foreach ($this->styles->ids() as $id) {
            $style = $this->styles->get($id);
            if ($style === null) {
                continue;
            }
            if ($style['type'] === 'paragraph') {
                $declarations = $this->paragraphCss($this->styles->paragraphProperties($id));
                $declarations = array_merge(
                    $declarations,
                    $this->runCss($this->styles->runProperties($id))
                );
                $selector = '.' . self::className('s', $id);
            } elseif ($style['type'] === 'character') {
                $declarations = $this->runCss($this->styles->runProperties($id));
                $selector = '.' . self::className('c', $id);
            } else {
                continue;
            }
            if ($declarations !== []) {
                $this->rules[] = "$selector { " . self::declarations($declarations) . ' }';
            }
        }
    }

    // ── Eigenschappen omzetten naar CSS ──────────────────────────────────────

    /** @param array<?\DOMElement> $list tekeneigenschappen, basis eerst */
    private function runCss(array $list): array
    {
        $css = [];
        $decorations = [];
        $sawDecoration = false;

        foreach ($list as $properties) {
            if (!$properties instanceof \DOMElement) {
                continue;
            }
            foreach ($properties->childNodes as $child) {
                if (!$child instanceof \DOMElement || $child->namespaceURI !== Ns::W) {
                    continue;
                }
                $value = Ns::attr($child, 'w:val');
                switch ($child->localName) {
                    case 'b': case 'bCs':
                        $css['font-weight'] = Ns::toggle($child, true) ? 'bold' : 'normal';
                        break;
                    case 'i': case 'iCs':
                        $css['font-style'] = Ns::toggle($child, true) ? 'italic' : 'normal';
                        break;
                    case 'u':
                        $sawDecoration = true;
                        if ($value === null || $value === 'none') {
                            $decorations = array_diff($decorations, ['underline']);
                        } else {
                            $decorations[] = 'underline';
                            $css['text-decoration-style'] = self::UNDERLINE[$value] ?? 'solid';
                        }
                        break;
                    case 'strike': case 'dstrike':
                        $sawDecoration = true;
                        if (Ns::toggle($child, true)) {
                            $decorations[] = 'line-through';
                        } else {
                            $decorations = array_diff($decorations, ['line-through']);
                        }
                        break;
                    case 'color':
                        $colour = Ns::colour($value);
                        if ($colour !== null) {
                            $css['color'] = $colour;
                        }
                        break;
                    case 'sz': case 'szCs':
                        $size = Ns::halfPointsToPt($value);
                        if ($size !== null && $size > 0) {
                            $css['font-size'] = Ns::pt($size);
                        }
                        break;
                    case 'rFonts':
                        $stack = $this->fontStack($child);
                        if ($stack !== null) {
                            $css['font-family'] = $stack;
                        }
                        break;
                    case 'caps':
                        $css['text-transform'] = Ns::toggle($child, true) ? 'uppercase' : 'none';
                        break;
                    case 'smallCaps':
                        $css['font-variant'] = Ns::toggle($child, true) ? 'small-caps' : 'normal';
                        break;
                    case 'vertAlign':
                        if ($value === 'superscript' || $value === 'subscript') {
                            $css['vertical-align'] = $value;
                            $css['font-size'] = '0.7em';
                        }
                        break;
                    case 'spacing':
                        $spacing = Ns::twipsToPt($value);
                        if ($spacing) {
                            $css['letter-spacing'] = Ns::pt($spacing);
                        }
                        break;
                    case 'vanish': case 'webHidden':
                        if (Ns::toggle($child, true)) {
                            $css['display'] = 'none';
                        } else {
                            unset($css['display']);
                        }
                        break;
                }
            }
        }

        $decorations = array_values(array_unique($decorations));
        if ($decorations !== []) {
            $css['text-decoration'] = implode(' ', $decorations);
        } elseif ($sawDecoration) {
            // Een uitdrukkelijk "geen onderstreping" moet een geërfde kunnen overrulen.
            $css['text-decoration'] = 'none';
        }
        return $css;
    }

    /** @param array<?\DOMElement> $list alinea-eigenschappen, basis eerst */
    private function paragraphCss(array $list): array
    {
        $css = [];
        foreach ($list as $properties) {
            if (!$properties instanceof \DOMElement) {
                continue;
            }

            $alignment = Ns::attr($this->childOf($properties, 'jc'), 'w:val');
            if ($alignment !== null && isset(self::ALIGNMENT[$alignment])) {
                $css['text-align'] = self::ALIGNMENT[$alignment];
            }

            $spacing = $this->childOf($properties, 'spacing');
            if ($spacing !== null) {
                $before = Ns::twipsToPt(Ns::attr($spacing, 'w:before'));
                $after = Ns::twipsToPt(Ns::attr($spacing, 'w:after'));
                if ($before !== null) {
                    $css['margin-top'] = Ns::pt($before);
                }
                if ($after !== null) {
                    $css['margin-bottom'] = Ns::pt($after);
                }
                $line = Ns::attr($spacing, 'w:line');
                if ($line !== null) {
                    if ((Ns::attr($spacing, 'w:lineRule', 'auto') ?? 'auto') === 'auto') {
                        $css['line-height'] = number_format(((float) $line) / 240.0, 3, '.', '');
                    } else {
                        $exact = Ns::twipsToPt($line);
                        if ($exact) {
                            $css['line-height'] = Ns::pt($exact);
                        }
                    }
                }
            }

            $indent = $this->childOf($properties, 'ind');
            if ($indent !== null) {
                $css = array_merge($css, self::indentCss($indent));
            }

            $borders = $this->childOf($properties, 'pBdr');
            if ($borders !== null) {
                $css = array_merge($css, $this->borderCss($borders));
            }
        }
        return $css;
    }

    private static function indentCss(\DOMElement $indent): array
    {
        $css = [];
        $left = Ns::twipsToPt(Ns::attr($indent, 'w:left') ?? Ns::attr($indent, 'w:start'));
        $right = Ns::twipsToPt(Ns::attr($indent, 'w:right') ?? Ns::attr($indent, 'w:end'));
        $firstLine = Ns::twipsToPt(Ns::attr($indent, 'w:firstLine'));
        $hanging = Ns::twipsToPt(Ns::attr($indent, 'w:hanging'));

        if ($left !== null) {
            $css['margin-left'] = Ns::pt($left);
        }
        if ($right !== null) {
            $css['margin-right'] = Ns::pt($right);
        }
        if ($hanging) {
            $css['text-indent'] = Ns::pt(-$hanging);
        } elseif ($firstLine) {
            $css['text-indent'] = Ns::pt($firstLine);
        }
        return $css;
    }

    private function borderCss(\DOMElement $borders): array
    {
        $css = [];
        foreach (['top', 'left', 'bottom', 'right'] as $side) {
            $value = self::borderValue($this->childOf($borders, $side));
            if ($value !== null) {
                $css["border-$side"] = $value;
            }
        }
        return $css;
    }

    private static function borderValue(?\DOMElement $border): ?string
    {
        if ($border === null) {
            return null;
        }
        $value = Ns::attr($border, 'w:val', 'single') ?? 'single';
        if ($value === 'nil' || $value === 'none') {
            return 'none';
        }
        $width = Ns::eighthPointsToPt(Ns::attr($border, 'w:sz'), 0.75) ?? 0.75;
        $colour = Ns::colour(Ns::attr($border, 'w:color')) ?? '#000000';
        return Ns::pt(max($width, 0.25)) . ' ' . (self::BORDER_STYLE[$value] ?? 'solid') . " $colour";
    }

    private function fontStack(\DOMElement $fonts): ?string
    {
        $name = Ns::attr($fonts, 'w:ascii')
            ?? Ns::attr($fonts, 'w:hAnsi')
            ?? Ns::attr($fonts, 'w:cs');
        if ($name === null) {
            $theme = Ns::attr($fonts, 'w:asciiTheme') ?? Ns::attr($fonts, 'w:hAnsiTheme');
            if ($theme !== null) {
                $name = $this->themeFonts[str_contains($theme, 'major') ? 'major' : 'minor'] ?? null;
            }
        }
        if ($name === null || $name === '') {
            return null;
        }
        $lowered = strtolower(trim($name));
        $fallback = in_array($lowered, self::MONO_FONTS, true)
            ? '"DejaVu Sans Mono", monospace'
            : (in_array($lowered, self::SERIF_FONTS, true)
                ? '"DejaVu Serif", serif'
                : '"DejaVu Sans", sans-serif');
        return '"' . str_replace('"', '', $name) . "\", $fallback";
    }

    private function readThemeFonts(): array
    {
        $part = $this->package->relatedPartOfKind((string) $this->mainPart, 'theme');
        $xpath = $part === null ? null : $this->package->xpath($part);
        if ($xpath === null) {
            return [];
        }
        $fonts = [];
        foreach (['major' => 'majorFont', 'minor' => 'minorFont'] as $key => $element) {
            $node = $xpath->query("//a:$element/a:latin")?->item(0);
            if ($node instanceof \DOMElement && $node->getAttribute('typeface') !== '') {
                $fonts[$key] = $node->getAttribute('typeface');
            }
        }
        return $fonts;
    }

    // ── Secties ──────────────────────────────────────────────────────────────

    /** @return array<int, array{0: \DOMElement[], 1: ?\DOMElement}> */
    private function splitSections(\DOMElement $body): array
    {
        $bodyProperties = null;
        foreach ($body->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'sectPr'
                && $child->namespaceURI === Ns::W) {
                $bodyProperties = $child;
            }
        }

        $sections = [];
        $current = [];
        foreach ($body->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            if ($child->localName === 'sectPr' && $child->namespaceURI === Ns::W) {
                continue;
            }
            $current[] = $child;
            if ($child->localName === 'p' && $child->namespaceURI === Ns::W) {
                $properties = $this->childOf($this->childOf($child, 'pPr'), 'sectPr');
                if ($properties !== null) {
                    $sections[] = [$current, $properties];
                    $current = [];
                }
            }
        }
        if ($current !== [] || $sections === []) {
            $sections[] = [$current, $bodyProperties];
        }
        return $sections;
    }

    /** @param \DOMElement[] $children */
    private function renderSection(int $index, array $children, ?\DOMElement $properties): RenderedSection
    {
        $geometry = self::pageGeometry($properties);
        $this->contentWidth = max(
            $geometry['width'] - $geometry['marginLeft'] - $geometry['marginRight'],
            36.0
        );

        $break = 'none';
        if ($index > 0) {
            $type = Ns::attr($this->childOf($properties, 'type'), 'w:val', 'nextPage') ?? 'nextPage';
            $break = match ($type) {
                'evenPage' => 'left',
                'oddPage'  => 'right',
                'continuous' => 'none',
                default    => 'page',
            };
        }

        $columns = $this->childOf($properties, 'cols');
        $columnCount = (int) (Ns::attr($columns, 'w:num', '1') ?? '1');

        $titlePage = Ns::toggle($this->childOf($properties, 'titlePg'), false);
        if ($titlePage && $index > 0) {
            $this->warn('Een afwijkende eerste pagina ("titelblad") wordt alleen toegepast '
                . 'op de eerste sectie van het document.');
        }

        $html = '';
        $context = new RenderContext((string) $this->mainPart, true);
        foreach ($children as $child) {
            $html .= $this->renderBlock($child, $context);
        }

        return new RenderedSection(
            html: $html,
            width: $geometry['width'],
            height: $geometry['height'],
            marginTop: $geometry['marginTop'],
            marginRight: $geometry['marginRight'],
            marginBottom: $geometry['marginBottom'],
            marginLeft: $geometry['marginLeft'],
            marginHeader: $geometry['marginHeader'],
            marginFooter: $geometry['marginFooter'],
            header: $this->headerFooter($properties, 'header', 'default'),
            footer: $this->headerFooter($properties, 'footer', 'default'),
            firstHeader: $this->headerFooter($properties, 'header', 'first'),
            firstFooter: $this->headerFooter($properties, 'footer', 'first'),
            evenHeader: $this->headerFooter($properties, 'header', 'even'),
            evenFooter: $this->headerFooter($properties, 'footer', 'even'),
            titlePage: $titlePage,
            evenAndOddHeaders: Ns::toggle($this->childOf($this->settings, 'evenAndOddHeaders'), false),
            breakBefore: $break,
            columns: max($columnCount, 1),
        );
    }

    private static function pageGeometry(?\DOMElement $properties): array
    {
        $size = null;
        $margin = null;
        if ($properties !== null) {
            foreach ($properties->childNodes as $child) {
                if (!$child instanceof \DOMElement || $child->namespaceURI !== Ns::W) {
                    continue;
                }
                if ($child->localName === 'pgSz') {
                    $size = $child;
                } elseif ($child->localName === 'pgMar') {
                    $margin = $child;
                }
            }
        }

        $width = Ns::twipsToPt(Ns::attr($size, 'w:w'), self::DEFAULT_PAGE_WIDTH) ?? self::DEFAULT_PAGE_WIDTH;
        $height = Ns::twipsToPt(Ns::attr($size, 'w:h'), self::DEFAULT_PAGE_HEIGHT) ?? self::DEFAULT_PAGE_HEIGHT;
        if (Ns::attr($size, 'w:orient') === 'landscape' && $width < $height) {
            [$width, $height] = [$height, $width];
        }

        $of = static fn(string $name, float $fallback): float => max(
            Ns::twipsToPt(Ns::attr($margin, $name), $fallback) ?? $fallback,
            0.0
        );

        return [
            'width'        => $width,
            'height'       => $height,
            'marginTop'    => $of('w:top', self::DEFAULT_MARGIN),
            'marginRight'  => $of('w:right', self::DEFAULT_MARGIN),
            'marginBottom' => $of('w:bottom', self::DEFAULT_MARGIN),
            'marginLeft'   => $of('w:left', self::DEFAULT_MARGIN),
            'marginHeader' => $of('w:header', 35.0),
            'marginFooter' => $of('w:footer', 35.0),
        ];
    }

    private function headerFooter(?\DOMElement $properties, string $kind, string $which): ?string
    {
        if ($properties === null) {
            return null;
        }
        $tag = $kind === 'header' ? 'headerReference' : 'footerReference';
        foreach ($properties->childNodes as $child) {
            if (!$child instanceof \DOMElement
                || $child->localName !== $tag
                || $child->namespaceURI !== Ns::W) {
                continue;
            }
            if ((Ns::attr($child, 'w:type', 'default') ?? 'default') !== $which) {
                continue;
            }
            $part = $this->package->relatedPartName(
                (string) $this->mainPart,
                Ns::attr($child, 'r:id')
            );
            $document = $this->package->document($part);
            if ($document?->documentElement === null) {
                continue;
            }
            $context = new RenderContext((string) $part, false, true);
            $html = '';
            foreach ($document->documentElement->childNodes as $node) {
                if ($node instanceof \DOMElement) {
                    $html .= $this->renderBlock($node, $context);
                }
            }
            return trim($html) === '' ? null : $html;
        }
        return null;
    }

    // ── Blokniveau ───────────────────────────────────────────────────────────

    private function renderBlock(\DOMElement $element, RenderContext $context): string
    {
        if ($element->namespaceURI === Ns::W) {
            return match ($element->localName) {
                'p'   => $this->renderParagraph($element, $context),
                'tbl' => $this->renderTable($element, $context),
                'sdt' => $this->renderChildrenOf($this->childOf($element, 'sdtContent'), $context, true),
                default => '',
            };
        }
        if ($element->namespaceURI === Ns::MC && $element->localName === 'AlternateContent') {
            $html = '';
            foreach ($this->alternateChildren($element) as $child) {
                $html .= $this->renderBlock($child, $context);
            }
            return $html;
        }
        return '';
    }

    private function renderChildrenOf(?\DOMElement $parent, RenderContext $context, bool $block): string
    {
        if ($parent === null) {
            return '';
        }
        $html = '';
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $html .= $block
                    ? $this->renderBlock($child, $context)
                    : $this->renderInline($child, $context);
            }
        }
        return $html;
    }

    private function renderParagraph(\DOMElement $paragraph, RenderContext $context): string
    {
        $properties = $this->childOf($paragraph, 'pPr');
        $styleId = Ns::attr($this->childOf($properties, 'pStyle'), 'w:val')
            ?? $this->styles->defaultParagraphStyle;
        $heading = $this->styles->headingLevel($styleId);
        $tag = $heading !== null && $heading >= 1 && $heading <= 6 ? "h$heading" : 'p';

        $classes = ['ps-p'];
        if ($this->styles->has($styleId)) {
            $classes[] = self::className('s', (string) $styleId);
        }

        $declarations = $this->paragraphCss([$properties]);
        [$numId, $level] = $this->resolveNumbering($properties, $styleId);
        $marker = '';
        if ($numId !== null) {
            [$marker, $indent] = $this->listMarker($numId, $level, $properties);
            $declarations = array_merge($indent, $declarations);
        }

        $pageBreakBefore = $this->childOf($properties, 'pageBreakBefore');
        $breakBefore = $pageBreakBefore !== null && Ns::toggle($pageBreakBefore, true);

        // Kop- en voetteksten bouwen hun links/midden/rechts met tabs op.
        if ($context->headerFooter && $this->tabCount($paragraph) >= 1
            && $this->tabCount($paragraph) <= 2) {
            return $this->renderHeaderFooterRow($paragraph, $context, $declarations);
        }

        $inner = $marker;
        foreach ($paragraph->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $inner .= $this->renderInline($child, $context);
            }
        }

        if (trim(strip_tags(str_replace(self::PAGE_BREAK_MARK, '', $inner))) === ''
            && !str_contains($inner, '<img')) {
            $inner .= '&nbsp;';
        }

        // Een pagina-einde midden in een alinea splitst de alinea, want in een
        // PDF-motor is een pagina-einde een blok, geen letter.
        $attributes = ' class="' . implode(' ', $classes) . '"'
            . ($declarations === [] ? '' : ' style="' . self::declarations($declarations) . '"');
        $open = "<$tag$attributes>";
        $close = "</$tag>";

        $pieces = explode(self::PAGE_BREAK_MARK, $inner);
        $html = ($breakBefore ? self::PAGE_BREAK_MARK : '');
        foreach ($pieces as $position => $piece) {
            if ($position > 0) {
                $html .= self::PAGE_BREAK_MARK;
            }
            $html .= $open . $piece . $close;
        }
        return $html;
    }

    private function renderHeaderFooterRow(
        \DOMElement $paragraph,
        RenderContext $context,
        array $declarations
    ): string {
        $cells = ['', '', ''];
        $index = 0;
        foreach ($paragraph->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            if ($child->localName === 'r' && $child->namespaceURI === Ns::W) {
                foreach ($child->childNodes as $piece) {
                    if (!$piece instanceof \DOMElement) {
                        continue;
                    }
                    if ($piece->localName === 'tab' && $piece->namespaceURI === Ns::W) {
                        $index = min($index + 1, 2);
                        continue;
                    }
                    $cells[$index] .= $this->renderRunChild(
                        $piece,
                        $context,
                        $this->childOf($child, 'rPr')
                    );
                }
            } else {
                $cells[$index] .= $this->renderInline($child, $context);
            }
        }

        $widths = ['33%', '34%', '33%'];
        $aligns = ['left', 'center', 'right'];
        $style = $declarations === [] ? '' : ' style="' . self::declarations($declarations) . '"';
        $html = '<table class="ps-hf"' . $style . '><tr>';
        foreach ($cells as $position => $cell) {
            $html .= '<td width="' . $widths[$position] . '" align="' . $aligns[$position] . '">'
                . ($cell === '' ? '&nbsp;' : $cell) . '</td>';
        }
        return $html . '</tr></table>';
    }

    /** @return array{0: ?string, 1: int} */
    private function resolveNumbering(?\DOMElement $properties, ?string $styleId): array
    {
        $numberProperties = $this->childOf($properties, 'numPr');
        $numId = Ns::attr($this->childOf($numberProperties, 'numId'), 'w:val');
        $level = (int) (Ns::attr($this->childOf($numberProperties, 'ilvl'), 'w:val', '0') ?? '0');

        if ($numId === null) {
            [$numId, $level] = $this->styles->numbering($styleId);
        }
        if ($numId === null || $numId === '0' || $this->numbering->level($numId, $level) === null) {
            return [null, 0];
        }
        return [$numId, $level];
    }

    /** @return array{0: string, 1: array<string, string>} */
    private function listMarker(string $numId, int $level, ?\DOMElement $properties): array
    {
        $key = "$numId:$level";
        if (isset($this->listCounters[$key])) {
            $this->listCounters[$key]++;
        } else {
            $this->listCounters[$key] = $this->numbering->startAt($numId, $level);
        }
        foreach (array_keys($this->listCounters) as $other) {
            [$otherNum, $otherLevel] = explode(':', $other);
            if ($otherNum === $numId && (int) $otherLevel > $level) {
                unset($this->listCounters[$other]);
            }
        }

        $format = $this->numbering->format($numId, $level);
        $template = $this->numbering->template($numId, $level);

        if ($format === 'bullet') {
            $text = strtr($template, self::BULLETS);
            if (trim($text) === '') {
                $text = '•';
            }
        } else {
            $text = $template !== '' ? $template : '%' . ($level + 1) . '.';
            for ($depth = 0; $depth < 9; $depth++) {
                $placeholder = '%' . ($depth + 1);
                if (str_contains($text, $placeholder)) {
                    $counter = $this->listCounters["$numId:$depth"]
                        ?? $this->numbering->startAt($numId, $depth);
                    $text = str_replace(
                        $placeholder,
                        Numbering::formatNumber($counter, $this->numbering->format($numId, $depth)),
                        $text
                    );
                }
            }
        }

        $indent = $this->childOf($properties, 'ind') ?? $this->numbering->indent($numId, $level);
        $declarations = $indent === null ? [] : self::indentCss($indent);
        $hanging = $indent === null ? null : Ns::twipsToPt(Ns::attr($indent, 'w:hanging'));

        // De inspringing van Word wordt hier benaderd met een vaste ruimte:
        // een PDF-motor honoreert min-width op een inline element niet, dus
        // zonder deze scheiding plakt "1." tegen het eerste woord aan.
        $markerStyle = $hanging ? 'min-width: ' . Ns::pt($hanging) : 'padding-right: 6pt';
        $marker = '<span class="ps-num" style="' . $markerStyle . '">'
            . self::escape($text) . '</span>&nbsp;';

        return [$marker, $declarations];
    }

    // ── Tekstniveau ──────────────────────────────────────────────────────────

    private function renderInline(\DOMElement $element, RenderContext $context): string
    {
        if ($element->namespaceURI === Ns::MC && $element->localName === 'AlternateContent') {
            $html = '';
            foreach ($this->alternateChildren($element) as $child) {
                $html .= $this->renderInline($child, $context);
            }
            return $html;
        }
        if ($element->namespaceURI !== Ns::W) {
            return '';
        }

        return match ($element->localName) {
            'r' => $this->renderRun($element, $context),
            'hyperlink' => $this->renderHyperlink($element, $context),
            'ins', 'smartTag', 'bdo', 'dir' => $this->renderChildrenOf($element, $context, false),
            'del' => '',                       // geschrapte tekst wordt nooit geprint
            'sdt' => $this->renderChildrenOf($this->childOf($element, 'sdtContent'), $context, false),
            'fldSimple' => $this->renderSimpleField($element, $context),
            default => '',
        };
    }

    private function renderHyperlink(\DOMElement $element, RenderContext $context): string
    {
        $inner = $this->renderChildrenOf($element, $context, false);
        $relationship = $this->package->relationships($context->part)[Ns::attr($element, 'r:id') ?? ''] ?? null;
        if ($relationship !== null && $relationship->external) {
            return '<a href="' . self::escape($relationship->target)
                . '" style="color: inherit; text-decoration: inherit">' . $inner . '</a>';
        }
        return $inner;
    }

    private function renderRun(\DOMElement $run, RenderContext $context): string
    {
        $properties = $this->childOf($run, 'rPr');
        $declarations = $this->runCss([$properties]);
        if (($declarations['display'] ?? '') === 'none') {
            return '';
        }
        unset($declarations['display']);

        $styleId = Ns::attr($this->childOf($properties, 'rStyle'), 'w:val');
        $inner = '';
        foreach ($run->childNodes as $child) {
            if ($child instanceof \DOMElement) {
                $inner .= $this->renderRunChild($child, $context, $properties);
            }
        }
        if ($inner === '') {
            return '';
        }

        $classes = $this->styles->has($styleId) ? ' class="' . self::className('c', (string) $styleId) . '"' : '';
        $style = $declarations === [] ? '' : ' style="' . self::declarations($declarations) . '"';
        if ($classes === '' && $style === '') {
            return $inner;
        }
        return "<span$classes$style>$inner</span>";
    }

    private function renderRunChild(
        \DOMElement $child,
        RenderContext $context,
        ?\DOMElement $properties
    ): string {
        if ($child->namespaceURI === Ns::MC && $child->localName === 'AlternateContent') {
            $html = '';
            foreach ($this->alternateChildren($child) as $piece) {
                $html .= $this->renderRunChild($piece, $context, $properties);
            }
            return $html;
        }
        if ($child->namespaceURI !== Ns::W) {
            return '';
        }

        // Velden hebben hun eigen boekhouding en gaan voor op het onderdrukken.
        if ($child->localName === 'fldChar') {
            return $this->handleFieldChar($child, $context);
        }
        if ($child->localName === 'instrText') {
            $context->appendInstruction($child->textContent);
            return '';
        }
        if ($context->isSuppressing() || $child->localName === 'delText') {
            return '';
        }

        return match ($child->localName) {
            't' => self::escape($child->textContent),
            'tab' => '<span class="ps-tab" style="margin-left: '
                . Ns::pt($this->defaultTab) . '"></span>',
            'br' => (Ns::attr($child, 'w:type', 'textWrapping') === 'page')
                ? self::PAGE_BREAK_MARK
                : '<br>',
            'cr' => '<br>',
            'noBreakHyphen' => '&#8209;',
            'softHyphen' => '&shy;',
            'sym' => $this->renderSymbol($child),
            'drawing' => $this->renderDrawing($child, $context),
            'pict' => $this->renderVml($child, $context),
            'object' => $this->warnOnce('Ingesloten objecten (OLE) worden niet in de PDF opgenomen.'),
            default => '',
        };
    }

    private function renderSymbol(\DOMElement $element): string
    {
        $code = Ns::attr($element, 'w:char');
        if ($code === null || !ctype_xdigit($code)) {
            return '';
        }
        $character = mb_chr((int) hexdec($code), 'UTF-8');
        return self::escape(self::BULLETS[$character] ?? $character);
    }

    private function handleFieldChar(\DOMElement $element, RenderContext $context): string
    {
        $type = Ns::attr($element, 'w:fldCharType', 'begin') ?? 'begin';
        if ($type === 'begin') {
            $context->beginField();
            return '';
        }
        if ($type === 'end') {
            $context->endField();
            return '';
        }
        if ($type === 'separate') {
            $keyword = $context->startResult();
            return self::fieldMark($keyword);
        }
        return '';
    }

    private function renderSimpleField(\DOMElement $element, RenderContext $context): string
    {
        $instruction = trim(Ns::attr($element, 'w:instr', '') ?? '');
        $keyword = strtoupper(strtok($instruction, " \t") ?: '');
        $mark = self::fieldMark($keyword);
        if ($mark !== '') {
            return $mark;
        }
        return $this->renderChildrenOf($element, $context, false);
    }

    private static function fieldMark(string $keyword): string
    {
        return match ($keyword) {
            'PAGE' => self::PAGE_NUMBER_MARK,
            'NUMPAGES', 'SECTIONPAGES' => self::PAGE_COUNT_MARK,
            default => '',
        };
    }

    // ── Afbeeldingen ─────────────────────────────────────────────────────────

    private function renderDrawing(\DOMElement $drawing, RenderContext $context): string
    {
        $xpath = new \DOMXPath($drawing->ownerDocument);
        Ns::registerOn($xpath);

        $blip = $xpath->query('.//a:blip', $drawing)?->item(0);
        if ($blip instanceof \DOMElement) {
            $extent = $xpath->query('.//wp:extent', $drawing)?->item(0);
            $width = $extent instanceof \DOMElement ? Ns::emuToPt($extent->getAttribute('cx')) : null;
            $height = $extent instanceof \DOMElement ? Ns::emuToPt($extent->getAttribute('cy')) : null;
            $id = Ns::attr($blip, 'r:embed') ?? Ns::attr($blip, 'r:link');
            return $this->image($id, $width, $height, $context);
        }

        $textbox = $xpath->query('.//w:txbxContent', $drawing)?->item(0);
        if ($textbox instanceof \DOMElement) {
            return '<div class="ps-textbox">'
                . $this->renderChildrenOf($textbox, $context, true) . '</div>';
        }

        return $this->warnOnce('Een tekening zonder afbeelding is overgeslagen '
            . '(vorm, grafiek of diagram).');
    }

    private function renderVml(\DOMElement $picture, RenderContext $context): string
    {
        $xpath = new \DOMXPath($picture->ownerDocument);
        Ns::registerOn($xpath);

        $data = $xpath->query('.//v:imagedata', $picture)?->item(0);
        if (!$data instanceof \DOMElement) {
            $textbox = $xpath->query('.//w:txbxContent', $picture)?->item(0);
            return $textbox instanceof \DOMElement
                ? '<div class="ps-textbox">' . $this->renderChildrenOf($textbox, $context, true) . '</div>'
                : '';
        }

        $shape = $xpath->query('.//v:shape', $picture)?->item(0);
        [$width, $height] = $shape instanceof \DOMElement
            ? self::vmlSize($shape->getAttribute('style'))
            : [null, null];

        return $this->image(Ns::attr($data, 'r:id'), $width, $height, $context);
    }

    private function image(?string $id, ?float $width, ?float $height, RenderContext $context): string
    {
        if ($id === null) {
            return '';
        }
        $part = $this->package->relatedPartName($context->part, $id);
        $blob = $this->package->blob($part);
        if ($blob === null || $blob === '') {
            return $this->warnOnce('Een afbeelding kon niet worden gelezen en is overgeslagen.');
        }

        $mime = ($part !== null ? $this->package->contentTypeOf($part) : null)
            ?? self::mimeFromName($part);
        $subtype = strtolower(substr(strrchr($mime, '/') ?: '', 1));
        if (in_array($subtype, self::UNSUPPORTED_IMAGES, true)) {
            return $this->warnOnce('Een afbeelding in ' . strtoupper(ltrim($subtype, 'x-'))
                . '-formaat kan niet in een PDF worden gezet en is overgeslagen.');
        }

        // Liever evenredig verkleinen dan de verhouding laten platdrukken.
        if ($width !== null && $width > $this->contentWidth) {
            $factor = $this->contentWidth / $width;
            $width = $this->contentWidth;
            $height = $height !== null ? $height * $factor : null;
        }

        $style = [];
        if ($width !== null) {
            $style[] = 'width: ' . Ns::pt($width);
        }
        if ($height !== null) {
            $style[] = 'height: ' . Ns::pt($height);
        }

        $tag = '<img src="data:' . $mime . ';base64,' . base64_encode($blob) . '"'
            . ($style === [] ? '' : ' style="' . implode('; ', $style) . '"') . '>';

        if (!$context->tagImages) {
            return $tag;
        }

        // Elke afbeelding in de lopende tekst krijgt een merkteken, zodat de
        // motor na de eerste opmaakronde kan zien op welke pagina hij staat.
        $this->imageSequence++;
        $marker = 'psimg' . $this->imageSequence;
        $this->imageIds[] = $marker;
        return "<!--$marker-->$tag<!--/$marker-->";
    }

    private static function vmlSize(string $style): array
    {
        $values = [];
        foreach (explode(';', $style) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $name = strtolower(trim($parts[0]));
            $value = strtolower(trim($parts[1]));
            if (in_array($name, ['width', 'height'], true) && str_ends_with($value, 'pt')) {
                $values[$name] = (float) substr($value, 0, -2);
            }
        }
        return [$values['width'] ?? null, $values['height'] ?? null];
    }

    private static function mimeFromName(?string $name): string
    {
        return match (strtolower(pathinfo($name ?? '', PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'bmp' => 'image/bmp',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'tif', 'tiff' => 'image/tiff',
            'emf' => 'image/emf',
            'wmf' => 'image/wmf',
            default => 'application/octet-stream',
        };
    }

    // ── Tabellen ─────────────────────────────────────────────────────────────

    private function renderTable(\DOMElement $table, RenderContext $context): string
    {
        $properties = $this->childOf($table, 'tblPr');
        $styleId = Ns::attr($this->childOf($properties, 'tblStyle'), 'w:val');

        $borders = [];
        foreach ($this->styles->tableProperties($styleId) as $styleProperties) {
            $source = $this->childOf($styleProperties, 'tblBorders');
            if ($source !== null) {
                $borders = array_merge($borders, $this->tableBorders($source));
            }
        }
        $own = $this->childOf($properties, 'tblBorders');
        if ($own !== null) {
            $borders = array_merge($borders, $this->tableBorders($own));
        }

        $declarations = self::tableWidth($this->childOf($properties, 'tblW'));
        foreach (['top', 'left', 'bottom', 'right'] as $side) {
            if (isset($borders[$side])) {
                $declarations["border-$side"] = $borders[$side];
            }
        }

        $rows = '';
        $openCells = [];
        foreach ($table->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'tr'
                && $child->namespaceURI === Ns::W) {
                $rows .= $this->renderRow($child, $context, $borders, $openCells);
            }
        }
        if (trim($rows) === '') {
            return '';
        }

        return '<table class="ps-table" style="' . self::declarations($declarations) . '">'
            . $rows . '</table>';
    }

    private function renderRow(
        \DOMElement $row,
        RenderContext $context,
        array $borders,
        array &$openCells
    ): string {
        $cells = '';
        $column = 0;
        foreach ($row->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->localName !== 'tc'
                || $child->namespaceURI !== Ns::W) {
                continue;
            }

            $properties = $this->childOf($child, 'tcPr');
            $span = (int) (Ns::attr($this->childOf($properties, 'gridSpan'), 'w:val', '1') ?? '1');
            $merge = $this->childOf($properties, 'vMerge');
            $continuing = $merge !== null
                && (Ns::attr($merge, 'w:val', 'continue') ?? 'continue') !== 'restart';

            // Een voortgezette cel telt op bij de cel erboven.
            if ($continuing && isset($openCells[$column])) {
                $openCells[$column]++;
                $column += $span;
                continue;
            }

            $declarations = [];
            if (isset($borders['insideH'])) {
                $declarations['border-bottom'] = $borders['insideH'];
            }
            if (isset($borders['insideV'])) {
                $declarations['border-right'] = $borders['insideV'];
            }
            $cellBorders = $this->childOf($properties, 'tcBorders');
            if ($cellBorders !== null) {
                $declarations = array_merge($declarations, $this->borderCss($cellBorders));
            }
            $shading = $this->childOf($properties, 'shd');
            $fill = $shading === null ? null : Ns::colour(Ns::attr($shading, 'w:fill'));
            if ($fill !== null) {
                $declarations['background-color'] = $fill;
            }
            $alignment = Ns::attr($this->childOf($properties, 'vAlign'), 'w:val');
            if ($alignment === 'center') {
                $declarations['vertical-align'] = 'middle';
            } elseif ($alignment === 'bottom') {
                $declarations['vertical-align'] = 'bottom';
            }
            $width = $this->childOf($properties, 'tcW');
            $cellWidth = self::tableWidth($width);

            $inner = '';
            foreach ($child->childNodes as $node) {
                if ($node instanceof \DOMElement) {
                    $inner .= $this->renderBlock($node, $context);
                }
            }
            if (trim(strip_tags($inner)) === '' && !str_contains($inner, '<img')) {
                $inner .= '&nbsp;';
            }

            $attributes = ' class="ps-cell"';
            if ($span > 1) {
                $attributes .= ' colspan="' . $span . '"';
            }
            $attributes .= ' style="' . self::declarations(array_merge($cellWidth, $declarations)) . '"';
            $cells .= "<td$attributes>$inner</td>";

            $openCells[$column] = $merge !== null ? 1 : null;
            if ($openCells[$column] === null) {
                unset($openCells[$column]);
            }
            $column += $span;
        }

        return $cells === '' ? '' : "<tr>$cells</tr>";
    }

    private function tableBorders(\DOMElement $borders): array
    {
        $found = [];
        foreach (['top', 'left', 'bottom', 'right', 'insideH', 'insideV'] as $side) {
            $value = self::borderValue($this->childOf($borders, $side));
            if ($value !== null) {
                $found[$side] = $value;
            }
        }
        return $found;
    }

    private static function tableWidth(?\DOMElement $width): array
    {
        if ($width === null) {
            return ['width' => '100%'];
        }
        $type = Ns::attr($width, 'w:type', 'auto');
        $value = Ns::attr($width, 'w:w', '0') ?? '0';
        if ($type === 'pct') {
            $percent = ((float) rtrim($value, '%')) / 50.0;
            return ['width' => number_format(min($percent, 100.0), 2, '.', '') . '%'];
        }
        if ($type === 'dxa') {
            $points = Ns::twipsToPt($value);
            return $points ? ['width' => Ns::pt($points)] : ['width' => '100%'];
        }
        return ['width' => '100%'];
    }

    // ── Klein grut ───────────────────────────────────────────────────────────

    private function childOf(?\DOMElement $parent, string $name): ?\DOMElement
    {
        if ($parent === null) {
            return null;
        }
        foreach ($parent->childNodes as $child) {
            if ($child instanceof \DOMElement
                && $child->localName === $name
                && $child->namespaceURI === Ns::W) {
                return $child;
            }
        }
        return null;
    }

    /** @return \DOMElement[] */
    private function alternateChildren(\DOMElement $alternate): array
    {
        foreach (['Choice', 'Fallback'] as $preference) {
            foreach ($alternate->childNodes as $child) {
                if ($child instanceof \DOMElement
                    && $child->localName === $preference
                    && $child->namespaceURI === Ns::MC
                    && $child->childNodes->length > 0) {
                    $found = [];
                    foreach ($child->childNodes as $node) {
                        if ($node instanceof \DOMElement) {
                            $found[] = $node;
                        }
                    }
                    if ($found !== []) {
                        return $found;
                    }
                }
            }
        }
        return [];
    }

    private function tabCount(\DOMElement $paragraph): int
    {
        return $paragraph->getElementsByTagNameNS(Ns::W, 'tab')->length;
    }

    private function warn(string $message): void
    {
        if (!isset($this->warned[$message])) {
            $this->warned[$message] = true;
            $this->warnings[] = $message;
        }
    }

    private function warnOnce(string $message): string
    {
        $this->warn($message);
        return '';
    }

    private static function className(string $prefix, string $id): string
    {
        return "ps-$prefix-" . preg_replace('~[^A-Za-z0-9_-]~', '_', $id);
    }

    private static function declarations(array $declarations): string
    {
        $pieces = [];
        foreach ($declarations as $property => $value) {
            if ($value !== '' && $value !== null) {
                $pieces[] = "$property: $value";
            }
        }
        return implode('; ', $pieces);
    }

    /** Tekst uit Word veilig in HTML zetten, met behoud van meerdere spaties. */
    private static function escape(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        // HTML plakt opeenvolgende spaties samen; Word bedoelde ze echt.
        return preg_replace_callback(
            '~  +~',
            static fn(array $m): string => str_repeat('&nbsp;', strlen($m[0]) - 1) . ' ',
            $escaped
        ) ?? $escaped;
    }
}
