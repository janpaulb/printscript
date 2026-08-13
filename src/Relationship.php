<?php

declare(strict_types=1);

namespace PrintScript;

/** Eén regel uit een .rels-bestand. */
final class Relationship
{
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $target,
        public readonly bool $external,
    ) {
    }

    /** Het laatste stuk van het relatietype: 'image', 'header', 'styles', ... */
    public function kind(): string
    {
        $position = strrpos($this->type, '/');
        return $position === false ? $this->type : substr($this->type, $position + 1);
    }
}
