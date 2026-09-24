<?php

declare(strict_types=1);

namespace App\Content;

use DateTimeImmutable;

/**
 * Une page du site, issue d'un fichier content/pages/*.md.
 *
 * Objet immuable : il est mis en cache tel quel par PageRepository.
 */
final readonly class Page
{
    public function __construct(
        public string $slug,
        public string $path,
        public string $title,
        public string $html,
        public string $seoTitle,
        public string $seoDescription,
        public ?string $ogImage,
        public bool $noindex,
        public string $template,
        public ?DateTimeImmutable $updated,
        public ?MenuEntry $menu,
    ) {
    }

    public function isHome(): bool
    {
        return '/' === $this->path;
    }
}
