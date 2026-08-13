<?php

declare(strict_types=1);

namespace PrintScript;

/** Een document zoals het bij Google vandaan komt. */
final class DownloadedDocument
{
    public function __construct(
        public readonly string $data,
        public readonly string $id,
        public readonly ?string $title = null,
    ) {
    }
}
