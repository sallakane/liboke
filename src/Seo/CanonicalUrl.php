<?php

declare(strict_types=1);

namespace App\Seo;

/**
 * Construit les URLs absolues du site à partir de l'hôte canonique.
 *
 * CANONICAL_URL vaut https://localhost en développement et l'hôte de
 * production au déploiement. Le choix www / sans-www reste à arrêter
 * (CLAUDE.md §7).
 */
final readonly class CanonicalUrl
{
    private string $base;

    public function __construct(string $canonicalUrl)
    {
        $this->base = rtrim($canonicalUrl, '/');
    }

    public function base(): string
    {
        return $this->base;
    }

    public function forPath(string $path): string
    {
        if ('' === $path || '/' === $path) {
            return $this->base.'/';
        }

        return $this->base.'/'.ltrim($path, '/');
    }

    /**
     * Hôte et schéma attendus, pour la redirection 301 vers l'hôte canonique.
     */
    public function schemeAndHost(): string
    {
        return $this->base;
    }
}
