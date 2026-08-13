<?php

declare(strict_types=1);

namespace PrintScript;

use PrintScript\Engine\EngineInterface;
use PrintScript\Engine\MpdfEngine;

/**
 * De vier stappen aan elkaar geknoopt.
 *
 *     Google Docs-link ─┐
 *                       ├─► .docx ─► schoonmaken ─► lezen ─► opmaken ─► PDF
 *     Geüploade .docx  ─┘
 *
 * Alles gaat door het geheugen: bytes erin, bytes eruit. Dat is wat het van
 * begin tot eind testbaar maakt.
 */
final class Pipeline
{
    public const MAX_UPLOAD_BYTES = 50 * 1024 * 1024;

    private EngineInterface $engine;

    public function __construct(?EngineInterface $engine = null)
    {
        $this->engine = $engine ?? new MpdfEngine();
    }

    /** Zet .docx-bytes om in een drukklare PDF. */
    public function convertDocx(
        string $data,
        Options $options = new Options(),
        ?string $title = null
    ): ConversionResult {
        if ($data === '') {
            throw new InvalidDocxException('Het bestand is leeg.');
        }
        if (strlen($data) > self::MAX_UPLOAD_BYTES) {
            throw new InvalidDocxException(sprintf(
                'Het bestand is groter dan de limiet van %d MB.',
                intdiv(self::MAX_UPLOAD_BYTES, 1024 * 1024)
            ));
        }

        $package = new Package($data);
        $report = Clean::clean($package);
        $document = HtmlRenderer::render($package, $options);
        $result = $this->engine->render($document, $options);

        return new ConversionResult(
            pdf: $result->pdf,
            filename: self::safeFilename($title) . ' - printklaar.pdf',
            pageCount: $result->pageCount,
            imagesRemoved: $result->imagesRemoved,
            report: $report,
            warnings: $result->warnings,
            engine: $result->engine,
        );
    }

    /** Haalt een document bij Google op en zet het om. */
    public function convertGoogleDoc(
        string $url,
        Options $options = new Options(),
        ?string $accessToken = null,
        ?GoogleDocs $client = null
    ): ConversionResult {
        $document = ($client ?? new GoogleDocs())->download($url, $accessToken);
        return $this->convertDocx(
            $document->data,
            $options,
            $document->title ?? substr($document->id, 0, 12)
        );
    }

    /** Een downloadnaam die op elk platform veilig is, zonder extensie. */
    public static function safeFilename(?string $title, string $fallback = 'document'): string
    {
        $stem = trim((string) $title);
        $stem = (string) preg_replace('~^.*[\\\\/]~', '', $stem);
        if (str_ends_with(strtolower($stem), '.docx')) {
            $stem = substr($stem, 0, -5);
        }
        $stem = (string) preg_replace('~[^\p{L}\p{N} \-()\[\]&.,]~u', ' ', $stem);
        $stem = trim((string) preg_replace('~\s+~u', ' ', $stem), " .\t\n");
        $stem = mb_substr($stem, 0, 80);
        return $stem === '' ? $fallback : $stem;
    }
}
