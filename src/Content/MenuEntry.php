<?php

declare(strict_types=1);

namespace App\Content;

/**
 * Entrée de menu déclarée dans le front matter d'une page.
 */
final readonly class MenuEntry
{
    public function __construct(
        public string $label,
        public int $weight,
    ) {
    }
}
