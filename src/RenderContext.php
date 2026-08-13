<?php

declare(strict_types=1);

namespace PrintScript;

/**
 * Wat er tijdens het lezen van één onderdeel bijgehouden moet worden: waar
 * relaties tegen worden opgezocht, en de stand van zaken bij velden.
 *
 * Velden in Word bestaan uit drie stukken: de instructie, een scheiding, en
 * het laatst bekende resultaat. Bij PAGE en NUMPAGES gooien we dat onthouden
 * resultaat weg en zetten we er een merkteken voor in de plaats; bij andere
 * velden (een datum, een inhoudsopgave) houden we het juist.
 */
final class RenderContext
{
    /** @var array<int, array{instruction: string, inResult: bool, suppress: bool}> */
    private array $fields = [];

    public function __construct(
        public readonly string $part,
        public readonly bool $tagImages = false,
        public readonly bool $headerFooter = false,
    ) {
    }

    public function beginField(): void
    {
        $this->fields[] = ['instruction' => '', 'inResult' => false, 'suppress' => false];
    }

    public function appendInstruction(string $text): void
    {
        $last = count($this->fields) - 1;
        if ($last >= 0) {
            $this->fields[$last]['instruction'] .= $text;
        }
    }

    /** Sluit de instructie af en meldt om welk soort veld het gaat. */
    public function startResult(): string
    {
        $last = count($this->fields) - 1;
        if ($last < 0) {
            return '';
        }
        $this->fields[$last]['inResult'] = true;

        $instruction = trim($this->fields[$last]['instruction']);
        $keyword = strtoupper(strtok($instruction, " \t") ?: '');
        if (in_array($keyword, ['PAGE', 'NUMPAGES', 'SECTIONPAGES'], true)) {
            $this->fields[$last]['suppress'] = true;
            return $keyword;
        }
        return '';
    }

    public function endField(): void
    {
        array_pop($this->fields);
    }

    /** Binnen de instructie, of binnen een resultaat dat we vervangen. */
    public function isSuppressing(): bool
    {
        $last = count($this->fields) - 1;
        if ($last < 0) {
            return false;
        }
        return !$this->fields[$last]['inResult'] || $this->fields[$last]['suppress'];
    }
}
