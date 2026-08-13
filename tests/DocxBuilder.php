<?php

declare(strict_types=1);

namespace PrintScript\Tests;

/**
 * .docx-pakketten met de hand in elkaar gezet.
 *
 * De tests hebben documenten nodig met dingen die je met een gewone
 * bibliotheek niet makkelijk maakt: opmerkingen, arceringen, sectie-einden,
 * PAGE-velden. Daarom bouwt deze klasse het OOXML-pakket rechtstreeks op. Dat
 * betekent meteen dat de testsuite van geen enkele schrijvende bibliotheek
 * afhangt.
 */
final class DocxBuilder
{
    public const MAIN = 'word/document.xml';

    private const DECL = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

    public const NS = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" '
        . 'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" '
        . 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '
        . 'xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture" '
        . 'xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" '
        . 'xmlns:v="urn:schemas-microsoft-com:vml"';

    private const RELS_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const OFFICE = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const WORD_TYPE = 'application/vnd.openxmlformats-officedocument.wordprocessingml'
        . '.document.main+xml';

    private const TYPES = [
        'styles' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml',
        'numbering' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml',
        'settings' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.settings+xml',
        'comments' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.comments+xml',
        'header' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml',
        'footer' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml',
    ];

    public const SECTION = '<w:sectPr>'
        . '<w:pgSz w:w="11906" w:h="16838"/>'
        . '<w:pgMar w:top="1417" w:right="1417" w:bottom="1417" w:left="1417" '
        . 'w:header="708" w:footer="708"/></w:sectPr>';

    public const STYLES = '<w:styles ' . self::NS . '>'
        . '<w:docDefaults><w:rPrDefault><w:rPr>'
        . '<w:rFonts w:ascii="Arial" w:hAnsi="Arial"/><w:sz w:val="22"/>'
        . '</w:rPr></w:rPrDefault></w:docDefaults>'
        . '<w:style w:type="paragraph" w:default="1" w:styleId="Normal">'
        . '<w:name w:val="Normal"/></w:style>'
        . '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/>'
        . '<w:basedOn w:val="Normal"/><w:pPr><w:spacing w:before="240" w:after="120"/></w:pPr>'
        . '<w:rPr><w:b/><w:sz w:val="32"/></w:rPr></w:style></w:styles>';

    /** @var array<string, string> */
    private array $parts = [];

    /** @var array<string, array<int, array{0: string, 1: string, 2: string}>> */
    private array $rels = [];

    /** @var array<string, string> */
    private array $overrides = [];

    private int $nextRel = 1;

    public function __construct()
    {
        $this->addPart('styles', 'word/styles.xml', self::STYLES);
    }

    public function addPart(string $kind, string $path, string $xml, ?string $owner = null): string
    {
        $id = 'rId' . (++$this->nextRel);
        $this->parts[$path] = self::DECL . $xml;
        $this->overrides['/' . $path] = self::TYPES[$kind];
        $this->rels[$owner ?? self::MAIN][] = [$id, $kind, substr($path, strpos($path, '/') + 1)];
        return $id;
    }

    public function addHeader(string $xml): string
    {
        $index = $this->countParts('word/header') + 1;
        return $this->addPart('header', "word/header$index.xml",
            '<w:hdr ' . self::NS . '>' . $xml . '</w:hdr>');
    }

    public function addFooter(string $xml): string
    {
        $index = $this->countParts('word/footer') + 1;
        return $this->addPart('footer', "word/footer$index.xml",
            '<w:ftr ' . self::NS . '>' . $xml . '</w:ftr>');
    }

    public function addComments(string $xml): string
    {
        return $this->addPart('comments', 'word/comments.xml',
            '<w:comments ' . self::NS . '>' . $xml . '</w:comments>');
    }

    public function addNumbering(string $xml): string
    {
        return $this->addPart('numbering', 'word/numbering.xml',
            '<w:numbering ' . self::NS . '>' . $xml . '</w:numbering>');
    }

    public function addSettings(string $xml = ''): string
    {
        return $this->addPart('settings', 'word/settings.xml',
            '<w:settings ' . self::NS . '>' . $xml . '</w:settings>');
    }

    public function setStyles(string $xml): void
    {
        $this->parts['word/styles.xml'] = self::DECL . $xml;
    }

    /** @param string $owner het onderdeel dat naar deze afbeelding verwijst */
    public function addImage(string $data, string $extension = 'png', ?string $owner = null): string
    {
        $id = 'rId' . (++$this->nextRel);
        $index = $this->countParts('word/media/') + 1;
        $path = "word/media/image$index.$extension";
        $this->parts[$path] = $data;
        $this->rels[$owner ?? self::MAIN][] = [$id, 'image', substr($path, strpos($path, '/') + 1)];
        return $id;
    }

    public function build(string $body): string
    {
        $document = self::DECL . '<w:document ' . self::NS . '><w:body>' . $body
            . '</w:body></w:document>';

        $types = [self::DECL,
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">',
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>',
            '<Default Extension="xml" ContentType="application/xml"/>',
            '<Default Extension="png" ContentType="image/png"/>',
            '<Default Extension="jpeg" ContentType="image/jpeg"/>',
            '<Default Extension="emf" ContentType="image/emf"/>',
            '<Override PartName="/word/document.xml" ContentType="' . self::WORD_TYPE . '"/>'];
        foreach ($this->overrides as $part => $type) {
            $types[] = '<Override PartName="' . $part . '" ContentType="' . $type . '"/>';
        }
        $types[] = '</Types>';

        $packageRels = self::DECL . '<Relationships xmlns="' . self::RELS_NS . '">'
            . '<Relationship Id="rId1" Type="' . self::OFFICE . '/officeDocument" '
            . 'Target="word/document.xml"/></Relationships>';

        $file = tempnam(sys_get_temp_dir(), 'fixture');
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::OVERWRITE | \ZipArchive::CREATE);
        $zip->addFromString('[Content_Types].xml', implode('', $types));
        $zip->addFromString('_rels/.rels', $packageRels);
        $zip->addFromString('word/document.xml', $document);
        foreach ($this->rels as $owner => $relationships) {
            $lines = [self::DECL, '<Relationships xmlns="' . self::RELS_NS . '">'];
            foreach ($relationships as [$id, $kind, $target]) {
                $lines[] = '<Relationship Id="' . $id . '" Type="' . self::OFFICE . '/' . $kind
                    . '" Target="' . $target . '"/>';
            }
            $lines[] = '</Relationships>';
            $directory = dirname($owner);
            $zip->addFromString("$directory/_rels/" . basename($owner) . '.rels', implode('', $lines));
        }
        foreach ($this->parts as $path => $contents) {
            $zip->addFromString($path, $contents);
        }
        $zip->close();

        $bytes = (string) file_get_contents($file);
        @unlink($file);
        return $bytes;
    }

    private function countParts(string $prefix): int
    {
        return count(array_filter(
            array_keys($this->parts),
            static fn(string $name): bool => str_starts_with($name, $prefix)
        ));
    }

    // ── Bouwstenen ───────────────────────────────────────────────────────────

    public static function paragraph(
        string $text = '',
        ?string $style = null,
        string $runProperties = '',
        string $properties = ''
    ): string {
        $ppr = ($style !== null || $properties !== '')
            ? '<w:pPr>' . ($style !== null ? '<w:pStyle w:val="' . $style . '"/>' : '')
              . $properties . '</w:pPr>'
            : '';
        $run = $text === '' ? '' : '<w:r>'
            . ($runProperties !== '' ? '<w:rPr>' . $runProperties . '</w:rPr>' : '')
            . '<w:t xml:space="preserve">' . $text . '</w:t></w:r>';
        return "<w:p>$ppr$run</w:p>";
    }

    public static function pageBreak(): string
    {
        return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
    }

    public static function imageRun(string $relId, int $width = 1905000, int $height = 1905000): string
    {
        return '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
            . '<wp:extent cx="' . $width . '" cy="' . $height . '"/>'
            . '<wp:docPr id="1" name="Afbeelding"/>'
            . '<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            . '<pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="Afbeelding"/><pic:cNvPicPr/></pic:nvPicPr>'
            . '<pic:blipFill><a:blip r:embed="' . $relId . '"/><a:stretch><a:fillRect/></a:stretch>'
            . '</pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/>'
            . '<a:ext cx="' . $width . '" cy="' . $height . '"/></a:xfrm>'
            . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>'
            . '</pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
    }

    public static function commentedParagraph(string $text, string $id = '1'): string
    {
        return '<w:p><w:commentRangeStart w:id="' . $id . '"/>'
            . '<w:r><w:t xml:space="preserve">' . $text . '</w:t></w:r>'
            . '<w:commentRangeEnd w:id="' . $id . '"/>'
            . '<w:r><w:commentReference w:id="' . $id . '"/></w:r></w:p>';
    }

    public static function commentsPart(string $text, string $id = '1'): string
    {
        return '<w:comment w:id="' . $id . '" w:author="Redacteur" w:initials="R">'
            . '<w:p><w:r><w:t>' . $text . '</w:t></w:r></w:p></w:comment>';
    }

    /** Een voettekst met een echt PAGE-veld, inclusief het getal dat Word onthield. */
    public static function pageFieldFooter(string $prefix = 'Pagina '): string
    {
        return '<w:p><w:pPr><w:jc w:val="center"/></w:pPr>'
            . '<w:r><w:t xml:space="preserve">' . $prefix . '</w:t></w:r>'
            . '<w:r><w:fldChar w:fldCharType="begin"/></w:r>'
            . '<w:r><w:instrText xml:space="preserve"> PAGE </w:instrText></w:r>'
            . '<w:r><w:fldChar w:fldCharType="separate"/></w:r>'
            . '<w:r><w:t>7</w:t></w:r>'
            . '<w:r><w:fldChar w:fldCharType="end"/></w:r></w:p>';
    }

    public static function sectionWithFooter(
        string $footerId,
        ?string $headerId = null,
        bool $titlePage = false,
        string $extra = ''
    ): string {
        $references = ($headerId !== null
                ? '<w:headerReference w:type="default" r:id="' . $headerId . '"/>' : '')
            . '<w:footerReference w:type="default" r:id="' . $footerId . '"/>';
        return '<w:sectPr>' . $references . $extra
            . ($titlePage ? '<w:titlePg/>' : '')
            . '<w:pgSz w:w="11906" w:h="16838"/>'
            . '<w:pgMar w:top="1417" w:right="1417" w:bottom="1417" w:left="1417" '
            . 'w:header="708" w:footer="708"/></w:sectPr>';
    }

    /** Een echte, minimale PNG — zonder bibliotheken. */
    public static function png(int $width = 40, int $height = 40, array $colour = [220, 40, 40]): string
    {
        $row = chr(0) . str_repeat(chr($colour[0]) . chr($colour[1]) . chr($colour[2]), $width);
        $raw = str_repeat($row, $height);

        $chunk = static function (string $tag, string $payload): string {
            return pack('N', strlen($payload)) . $tag . $payload
                . pack('N', crc32($tag . $payload));
        };

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
            . $chunk('IDAT', (string) gzcompress($raw))
            . $chunk('IEND', '');
    }
}
