<?php

declare(strict_types=1);

namespace PrintScript;

/** Alles waar de gebruiker invloed op heeft. */
final class Options
{
    public function __construct(
        public bool $imagesFirstPageOnly = true,
        public bool $addPageNumbers = true,
        public bool $pageNumbersOnFirstPage = true,
    ) {
    }

    /** Uit een JSON-verzoek, met de standaardwaarden als vangnet. */
    public static function fromArray(mixed $payload): self
    {
        $options = new self();
        if (!is_array($payload)) {
            return $options;
        }
        return new self(
            self::flag($payload, 'images_first_page_only', $options->imagesFirstPageOnly),
            self::flag($payload, 'add_page_numbers', $options->addPageNumbers),
            self::flag($payload, 'page_numbers_on_first_page', $options->pageNumbersOnFirstPage),
        );
    }

    private static function flag(array $payload, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $payload)) {
            return $default;
        }
        $value = $payload[$key];
        if (is_string($value)) {
            return !in_array(strtolower($value), ['0', 'false', 'no', 'off', ''], true);
        }
        return (bool) $value;
    }
}
