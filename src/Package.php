<?php

declare(strict_types=1);

namespace PrintScript;

/**
 * Een .docx-pakket in het geheugen.
 *
 * Een .docx is een zip vol XML-onderdelen die met relatiebestanden aan elkaar
 * hangen. Deze klasse geeft de rest van PrintScript een klein, voorspelbaar
 * beeld daarvan: lees een onderdeel, ontleed het, volg een relatie, gooi er
 * een weg.
 *
 * Er komt geen bestandssysteem aan te pas: een pakket ontstaat uit bytes en
 * kan weer bytes worden. Dat is precies wat het testbaar maakt.
 */
final class Package
{
    public const CONTENT_TYPES = '[Content_Types].xml';

    private const OFFICE_DOCUMENT =
        'http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument';

    /** @var array<string, string> onderdeel => inhoud */
    private array $parts = [];

    /** @var array<string, \DOMDocument> ontlede onderdelen, gedeeld zodat wijzigingen blijven */
    private array $documents = [];

    /** @var array<string, array<string, Relationship>> */
    private array $relationships = [];

    private ?string $mainPart = null;

    public function __construct(string $data)
    {
        if ($data === '') {
            throw new InvalidDocxException('Het bestand is leeg.');
        }

        $temporary = tempnam(sys_get_temp_dir(), 'printscript');
        if ($temporary === false) {
            throw new \RuntimeException('Kan geen tijdelijk bestand aanmaken.');
        }

        try {
            file_put_contents($temporary, $data);
            $zip = new \ZipArchive();
            $opened = $zip->open($temporary, \ZipArchive::CHECKCONS);
            if ($opened !== true) {
                throw new InvalidDocxException(
                    'Dit is geen geldig .docx-bestand (geen zip-pakket).'
                );
            }
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if ($name === false || str_ends_with($name, '/')) {
                    continue;
                }
                $contents = $zip->getFromIndex($index);
                if ($contents !== false) {
                    $this->parts[$name] = $contents;
                }
            }
            $zip->close();
        } finally {
            @unlink($temporary);
        }

        if (!isset($this->parts[self::CONTENT_TYPES])) {
            throw new InvalidDocxException(
                'Dit is geen geldig .docx-bestand ([Content_Types].xml ontbreekt).'
            );
        }
        if ($this->mainPartName() === null) {
            throw new InvalidDocxException(
                'Dit .docx-bestand bevat geen hoofddocument (word/document.xml).'
            );
        }
    }

    // ── Onderdelen ───────────────────────────────────────────────────────────

    /** @return string[] */
    public function partNames(): array
    {
        return array_keys($this->parts);
    }

    public function hasPart(string $name): bool
    {
        return isset($this->parts[$name]);
    }

    public function blob(?string $name): ?string
    {
        return $name === null ? null : ($this->parts[$name] ?? null);
    }

    /** Het ontlede onderdeel; wijzigingen blijven behouden. */
    public function document(?string $name): ?\DOMDocument
    {
        if ($name === null) {
            return null;
        }
        if (isset($this->documents[$name])) {
            return $this->documents[$name];
        }
        if (!isset($this->parts[$name])) {
            return null;
        }

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML(
            $this->parts[$name],
            LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            return null;
        }
        return $this->documents[$name] = $document;
    }

    public function xpath(string $name): ?\DOMXPath
    {
        $document = $this->document($name);
        if ($document === null) {
            return null;
        }
        $xpath = new \DOMXPath($document);
        Ns::registerOn($xpath);
        return $xpath;
    }

    /** Verwijdert een onderdeel plus zijn content-type en inkomende relaties. */
    public function dropPart(string $name): void
    {
        if (!isset($this->parts[$name])) {
            return;
        }
        unset($this->parts[$name], $this->documents[$name]);
        $this->dropContentTypeOverride($name);
        $this->dropIncomingRelationships($name);
    }

    // ── Relaties ─────────────────────────────────────────────────────────────

    public static function relationshipPartFor(string $part): string
    {
        $directory = str_contains($part, '/') ? dirname($part) : '';
        $base = basename($part);
        return $directory === '' ? "_rels/$base.rels" : "$directory/_rels/$base.rels";
    }

    /** @return array<string, Relationship> gesleuteld op r:id */
    public function relationships(string $part): array
    {
        if (isset($this->relationships[$part])) {
            return $this->relationships[$part];
        }

        $found = [];
        $document = $this->document(self::relationshipPartFor($part));
        if ($document !== null) {
            foreach ($document->getElementsByTagNameNS(Ns::REL, 'Relationship') as $node) {
                $id = $node->getAttribute('Id');
                if ($id === '') {
                    continue;
                }
                $found[$id] = new Relationship(
                    $id,
                    $node->getAttribute('Type'),
                    $node->getAttribute('Target'),
                    $node->getAttribute('TargetMode') === 'External'
                );
            }
        }
        return $this->relationships[$part] = $found;
    }

    /** Zet een r:id om in een volledige onderdeelnaam; extern levert null op. */
    public function relatedPartName(string $part, ?string $id): ?string
    {
        if ($id === null || $id === '') {
            return null;
        }
        $relationship = $this->relationships($part)[$id] ?? null;
        if ($relationship === null || $relationship->external) {
            return null;
        }
        return self::resolveTarget($part, $relationship->target);
    }

    public function relatedBlob(string $part, ?string $id): ?string
    {
        return $this->blob($this->relatedPartName($part, $id));
    }

    /** De eerste relatie van een bepaalde soort ('styles', 'footer', ...). */
    public function relatedPartOfKind(string $part, string $kind): ?string
    {
        foreach ($this->relationships($part) as $relationship) {
            if ($relationship->kind() === $kind && !$relationship->external) {
                $name = self::resolveTarget($part, $relationship->target);
                if (isset($this->parts[$name])) {
                    return $name;
                }
            }
        }
        return null;
    }

    public static function resolveTarget(string $source, string $target): string
    {
        if (str_starts_with($target, '/')) {
            return ltrim($target, '/');
        }
        $base = str_contains($source, '/') ? dirname($source) : '';
        $path = $base === '' || $base === '.' ? $target : "$base/$target";

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        return implode('/', $segments);
    }

    public function mainPartName(): ?string
    {
        if ($this->mainPart !== null) {
            return $this->mainPart;
        }
        foreach ($this->relationships('') as $relationship) {
            if ($relationship->type === self::OFFICE_DOCUMENT && !$relationship->external) {
                $name = self::resolveTarget('', $relationship->target);
                if (isset($this->parts[$name])) {
                    return $this->mainPart = $name;
                }
            }
        }
        return $this->mainPart = isset($this->parts['word/document.xml'])
            ? 'word/document.xml'
            : null;
    }

    public function contentTypeOf(string $part): ?string
    {
        $document = $this->document(self::CONTENT_TYPES);
        if ($document === null) {
            return null;
        }
        foreach ($document->getElementsByTagNameNS(Ns::CT, 'Override') as $override) {
            if ($override->getAttribute('PartName') === '/' . $part) {
                return $override->getAttribute('ContentType');
            }
        }
        $extension = strtolower(pathinfo($part, PATHINFO_EXTENSION));
        foreach ($document->getElementsByTagNameNS(Ns::CT, 'Default') as $default) {
            if (strtolower($default->getAttribute('Extension')) === $extension) {
                return $default->getAttribute('ContentType');
            }
        }
        return null;
    }

    // ── Terugschrijven ───────────────────────────────────────────────────────

    /** Het pakket weer als zip, met de gewijzigde onderdelen erin. */
    public function toBytes(): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'printscript');
        if ($temporary === false) {
            throw new \RuntimeException('Kan geen tijdelijk bestand aanmaken.');
        }
        try {
            $zip = new \ZipArchive();
            $zip->open($temporary, \ZipArchive::OVERWRITE | \ZipArchive::CREATE);
            foreach ($this->parts as $name => $contents) {
                if (isset($this->documents[$name])) {
                    $serialised = $this->documents[$name]->saveXML();
                    if ($serialised !== false) {
                        $contents = $serialised;
                    }
                }
                $zip->addFromString($name, $contents);
            }
            $zip->close();
            return (string) file_get_contents($temporary);
        } finally {
            @unlink($temporary);
        }
    }

    // ── Interne huishouding ──────────────────────────────────────────────────

    private function dropContentTypeOverride(string $part): void
    {
        $document = $this->document(self::CONTENT_TYPES);
        if ($document === null) {
            return;
        }
        $wanted = '/' . $part;
        foreach (iterator_to_array($document->getElementsByTagNameNS(Ns::CT, 'Override')) as $override) {
            if ($override->getAttribute('PartName') === $wanted) {
                $override->parentNode?->removeChild($override);
            }
        }
        $serialised = $document->saveXML();
        if ($serialised !== false) {
            $this->parts[self::CONTENT_TYPES] = $serialised;
        }
    }

    private function dropIncomingRelationships(string $part): void
    {
        foreach (array_keys($this->parts) as $name) {
            if (!str_ends_with($name, '.rels')) {
                continue;
            }
            $document = $this->document($name);
            if ($document === null) {
                continue;
            }
            $owner = self::ownerOfRelationshipPart($name);
            $changed = false;
            foreach (iterator_to_array($document->getElementsByTagNameNS(Ns::REL, 'Relationship')) as $node) {
                if ($node->getAttribute('TargetMode') === 'External') {
                    continue;
                }
                if (self::resolveTarget($owner, $node->getAttribute('Target')) === $part) {
                    $node->parentNode?->removeChild($node);
                    $changed = true;
                }
            }
            if ($changed) {
                $serialised = $document->saveXML();
                if ($serialised !== false) {
                    $this->parts[$name] = $serialised;
                }
                unset($this->relationships[$owner]);
            }
        }
    }

    /** 'word/_rels/document.xml.rels' hoort bij 'word/document.xml'. */
    private static function ownerOfRelationshipPart(string $part): string
    {
        $directory = str_contains($part, '/') ? dirname($part) : '';
        $parent = preg_replace('~/?_rels$~', '', $directory) ?? '';
        $stem = substr(basename($part), 0, -strlen('.rels'));
        return $parent === '' || $parent === '.' ? $stem : "$parent/$stem";
    }
}
