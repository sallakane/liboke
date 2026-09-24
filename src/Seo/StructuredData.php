<?php

declare(strict_types=1);

namespace App\Seo;

use App\Content\Page;
use App\Content\SiteConfig;
use Symfony\Component\Asset\Packages;

/**
 * Données structurées JSON-LD (CLAUDE.md §7).
 *
 * Les valeurs encore marquées « [À COMPLÉTER] » sont omises : publier un
 * numéro de téléphone factice dans un balisage lu par Google serait pire
 * que de ne rien publier.
 */
final readonly class StructuredData
{
    private const string MARQUEUR_PROVISOIRE = '[À COMPLÉTER]';

    public function __construct(
        private SiteConfig $site,
        private CanonicalUrl $canonical,
        private Packages $assets,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function organisation(): array
    {
        $donnees = [
            '@context' => 'https://schema.org',
            '@type' => 'NGO',
            'name' => $this->site->name,
            'url' => $this->canonical->forPath('/'),
            'logo' => $this->canonical->base().$this->assets->getUrl('images/logo.svg'),
            'inLanguage' => 'fr',
        ];

        if ('' !== $this->site->baseline) {
            $donnees['slogan'] = $this->site->baseline;
        }

        $sameAs = array_values(array_filter(
            array_map(static fn (array $r): string => $r['href'], $this->site->reseaux),
            static fn (string $href): bool => '' !== $href,
        ));

        if ([] !== $sameAs) {
            $donnees['sameAs'] = $sameAs;
        }

        if (self::estRenseigne($this->site->contact['email'])) {
            $donnees['email'] = $this->site->contact['email'];
        }

        if (self::estRenseigne($this->site->contact['telephone'])) {
            $donnees['telephone'] = $this->site->contact['telephone'];
        }

        if (self::estRenseigne($this->site->contact['adresse'])) {
            $donnees['address'] = [
                '@type' => 'PostalAddress',
                'streetAddress' => $this->site->contact['adresse'],
                'addressCountry' => 'FR',
            ];
        }

        return $donnees;
    }

    /**
     * @return array<string, mixed>
     */
    public function siteWeb(): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $this->site->name,
            'url' => $this->canonical->forPath('/'),
            'inLanguage' => 'fr',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filAriane(Page $page): array
    {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => [
                [
                    '@type' => 'ListItem',
                    'position' => 1,
                    'name' => 'Accueil',
                    'item' => $this->canonical->forPath('/'),
                ],
                [
                    '@type' => 'ListItem',
                    'position' => 2,
                    'name' => $page->title,
                    'item' => $this->canonical->forPath($page->path),
                ],
            ],
        ];
    }

    private static function estRenseigne(string $valeur): bool
    {
        return '' !== $valeur && !str_contains($valeur, self::MARQUEUR_PROVISOIRE);
    }
}
