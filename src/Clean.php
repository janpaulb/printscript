<?php

declare(strict_types=1);

namespace PrintScript;

/**
 * Opmerkingen en markeringen uit het document halen.
 *
 * Beide bewerkingen lopen over élk inhoudelijk onderdeel van het pakket (de
 * body, kop- en voetteksten, voet- en eindnoten) en over styles.xml en
 * numbering.xml, zodat een markering die in een stijl is vastgelegd net zo
 * goed verdwijnt als een die met de hand is aangebracht.
 *
 * Afbeeldingen na pagina 1 worden hier níet aangepakt: op welke pagina een
 * afbeelding belandt, blijkt pas als het document is opgemaakt. Die stap zit
 * daarom in de PDF-motor.
 */
final class Clean
{
    private const CONTENT_KINDS = ['header', 'footer', 'footnotes', 'endnotes'];

    private const COMMENT_KINDS = [
        'comments', 'commentsExtended', 'commentsIds', 'commentsExtensible', 'people',
    ];

    private const COMMENT_MARKERS = [
        'commentRangeStart', 'commentRangeEnd', 'commentReference', 'annotationRef',
    ];

    public static function clean(Package $package): CleanReport
    {
        $report = new CleanReport();
        self::removeComments($package, $report);
        self::removeHighlighting($package, $report);
        return $report;
    }

    /** Hoofddocument plus elk kop-, voet- en notenonderdeel waar het naar verwijst. */
    public static function contentParts(Package $package): array
    {
        $main = $package->mainPartName();
        if ($main === null) {
            return [];
        }
        $parts = [$main];
        foreach ($package->relationships($main) as $relationship) {
            if (!in_array($relationship->kind(), self::CONTENT_KINDS, true) || $relationship->external) {
                continue;
            }
            $name = $package->relatedPartName($main, $relationship->id);
            if ($name !== null && $package->hasPart($name) && !in_array($name, $parts, true)) {
                $parts[] = $name;
            }
        }
        return $parts;
    }

    /** De inhoudelijke onderdelen plus de onderdelen die opmaak vastleggen. */
    public static function stylingParts(Package $package): array
    {
        $parts = self::contentParts($package);
        $main = $package->mainPartName();
        if ($main === null) {
            return $parts;
        }
        foreach (['styles', 'numbering'] as $kind) {
            $name = $package->relatedPartOfKind($main, $kind);
            if ($name !== null && !in_array($name, $parts, true)) {
                $parts[] = $name;
            }
        }
        return $parts;
    }

    // ── 1. Opmerkingen ───────────────────────────────────────────────────────

    /**
     * Alles wat met opmerkingen te maken heeft verdwijnt: de ankers in de
     * tekst, de runs die alleen bestaan om naar een opmerking te wijzen, en de
     * onderdelen met de opmerkingen zelf.
     */
    public static function removeComments(Package $package, ?CleanReport $report = null): CleanReport
    {
        $report ??= new CleanReport();

        foreach (self::contentParts($package) as $part) {
            $document = $package->document($part);
            if ($document === null) {
                continue;
            }

            $touched = [];
            foreach (self::COMMENT_MARKERS as $marker) {
                foreach (self::elements($document, Ns::W, $marker) as $node) {
                    $parent = $node->parentNode;
                    if ($parent instanceof \DOMElement
                        && $parent->localName === 'r'
                        && $parent->namespaceURI === Ns::W) {
                        $touched[] = $parent;
                    }
                    $parent?->removeChild($node);
                    $report->commentMarkersRemoved++;
                }
            }

            // Een run die alleen bestond om naar een opmerking te wijzen, is nu
            // leeg en kan weg.
            //
            // Alleen díe runs. Google Docs zet in vrijwel elke alinea een run
            // zonder tekst, en juist die bepaalt hoe hoog een lege regel is.
            // Wie ze allemaal opruimt, laat de witruimte van het hele document
            // inklappen — en dat is precies wat hier gebeurde.
            foreach ($touched as $run) {
                $meaningful = false;
                foreach ($run->childNodes as $child) {
                    if ($child instanceof \DOMElement && $child->localName !== 'rPr') {
                        $meaningful = true;
                        break;
                    }
                }
                if (!$meaningful) {
                    $run->parentNode?->removeChild($run);
                }
            }
        }

        $main = $package->mainPartName();
        if ($main !== null) {
            foreach ($package->relationships($main) as $relationship) {
                if (!in_array($relationship->kind(), self::COMMENT_KINDS, true) || $relationship->external) {
                    continue;
                }
                $name = $package->relatedPartName($main, $relationship->id);
                if ($name !== null && $package->hasPart($name)) {
                    $package->dropPart($name);
                    $report->commentPartsRemoved++;
                }
            }
        }

        return $report;
    }

    // ── 2. Markeringen ───────────────────────────────────────────────────────

    /**
     * Alle markeringen en alle teken- en alineaschaduw verdwijnen.
     *
     * De markeerstift van Word schrijft w:highlight; de markeerkleur van
     * Google Docs schrijft w:shd binnen de tekenopmaak. Allebei weg. De
     * tekstkleur (w:color) en de vulkleur van tabelcellen blijven met opzet
     * staan: gekleurde tekst blijft gekleurd en tabelontwerpen blijven heel.
     */
    public static function removeHighlighting(Package $package, ?CleanReport $report = null): CleanReport
    {
        $report ??= new CleanReport();

        foreach (self::stylingParts($package) as $part) {
            $document = $package->document($part);
            if ($document === null) {
                continue;
            }

            foreach (self::elements($document, Ns::W, 'highlight') as $node) {
                $node->parentNode?->removeChild($node);
                $report->highlightsRemoved++;
            }

            foreach (self::elements($document, Ns::W, 'shd') as $node) {
                $parent = $node->parentNode;
                if ($parent instanceof \DOMElement
                    && in_array($parent->localName, ['rPr', 'pPr'], true)
                    && $parent->namespaceURI === Ns::W) {
                    $parent->removeChild($node);
                    $report->shadingsRemoved++;
                }
            }
        }

        return $report;
    }

    // ── Hulpmiddelen voor de tests ───────────────────────────────────────────

    public static function countCommentMarkers(Package $package): int
    {
        $total = 0;
        foreach (self::contentParts($package) as $part) {
            $document = $package->document($part);
            if ($document === null) {
                continue;
            }
            foreach (self::COMMENT_MARKERS as $marker) {
                $total += count(self::elements($document, Ns::W, $marker));
            }
        }
        return $total;
    }

    public static function countHighlighting(Package $package): int
    {
        $total = 0;
        foreach (self::stylingParts($package) as $part) {
            $document = $package->document($part);
            if ($document === null) {
                continue;
            }
            $total += count(self::elements($document, Ns::W, 'highlight'));
            foreach (self::elements($document, Ns::W, 'shd') as $node) {
                $parent = $node->parentNode;
                if ($parent instanceof \DOMElement
                    && in_array($parent->localName, ['rPr', 'pPr'], true)) {
                    $total++;
                }
            }
        }
        return $total;
    }

    /**
     * Een vaste lijst in plaats van een levende NodeList: tijdens het
     * verwijderen mag de verzameling niet onder je handen veranderen.
     *
     * @return \DOMElement[]
     */
    private static function elements(\DOMDocument $document, string $namespace, string $name): array
    {
        return iterator_to_array($document->getElementsByTagNameNS($namespace, $name));
    }
}
