<?php

declare(strict_types=1);

namespace App\Twig;

use App\Content\Page;
use App\Seo\CanonicalUrl;
use App\Seo\StructuredData;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class SeoExtension extends AbstractExtension
{
    /**
     * JSON_HEX_TAG interdit qu'une valeur referme la balise <script>.
     */
    private const int OPTIONS_JSON = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_HEX_TAG;

    public function __construct(
        private readonly CanonicalUrl $canonical,
        private readonly StructuredData $donnees,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('url_canonique', $this->urlCanonique(...)),
            new TwigFunction('json_ld_organisation', $this->jsonLdOrganisation(...), ['is_safe' => ['html']]),
            new TwigFunction('json_ld_site', $this->jsonLdSite(...), ['is_safe' => ['html']]),
            new TwigFunction('json_ld_fil_ariane', $this->jsonLdFilAriane(...), ['is_safe' => ['html']]),
        ];
    }

    public function urlCanonique(string $chemin): string
    {
        return $this->canonical->forPath($chemin);
    }

    public function jsonLdOrganisation(): string
    {
        return json_encode($this->donnees->organisation(), self::OPTIONS_JSON | \JSON_THROW_ON_ERROR);
    }

    public function jsonLdSite(): string
    {
        return json_encode($this->donnees->siteWeb(), self::OPTIONS_JSON | \JSON_THROW_ON_ERROR);
    }

    public function jsonLdFilAriane(Page $page): string
    {
        return json_encode($this->donnees->filAriane($page), self::OPTIONS_JSON | \JSON_THROW_ON_ERROR);
    }
}
