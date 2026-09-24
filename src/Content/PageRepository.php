<?php

declare(strict_types=1);

namespace App\Content;

use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use RuntimeException;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Lit content/pages/*.md, parse le front matter et le Markdown, met en cache.
 *
 * Le cache est invalidé par `cache:clear` au déploiement (CLAUDE.md §6).
 * En développement il est court-circuité : sans cela, modifier un fichier
 * Markdown n'aurait aucun effet visible tant qu'on ne vide pas le cache.
 */
final class PageRepository
{
    private const string CACHE_KEY = 'content.pages';

    /** @var array<string, Page>|null */
    private ?array $pages = null;

    public function __construct(
        private readonly string $contentDir,
        private readonly string $homeSlug,
        private readonly bool $debug,
        private readonly CacheInterface $cache,
        private readonly MarkdownRenderer $markdown,
    ) {
    }

    /**
     * Toutes les pages, indexées par slug et triées par slug.
     *
     * @return array<string, Page>
     */
    public function all(): array
    {
        if (null !== $this->pages) {
            return $this->pages;
        }

        if ($this->debug) {
            return $this->pages = $this->parseAll();
        }

        /** @var array<string, Page> $pages */
        $pages = $this->cache->get(self::CACHE_KEY, fn (): array => $this->parseAll());

        return $this->pages = $pages;
    }

    public function find(string $slug): ?Page
    {
        return $this->all()[$slug] ?? null;
    }

    public function home(): Page
    {
        $page = $this->find($this->homeSlug);

        if (null === $page) {
            throw new RuntimeException(\sprintf('La page d\'accueil « %s.md » est introuvable dans %s.', $this->homeSlug, $this->contentDir));
        }

        return $page;
    }

    /**
     * Pages déclarant une entrée de menu, triées par poids croissant.
     *
     * @return list<Page>
     */
    public function menu(): array
    {
        $pages = array_values(array_filter($this->all(), static fn (Page $p): bool => null !== $p->menu));

        usort($pages, static function (Page $a, Page $b): int {
            /** @var MenuEntry $ma */
            $ma = $a->menu;
            /** @var MenuEntry $mb */
            $mb = $b->menu;

            return [$ma->weight, $ma->label] <=> [$mb->weight, $mb->label];
        });

        return $pages;
    }

    /**
     * Pages indexables, pour sitemap.xml (phase 4).
     *
     * @return list<Page>
     */
    public function indexable(): array
    {
        return array_values(array_filter($this->all(), static fn (Page $p): bool => !$p->noindex));
    }

    /**
     * @return array<string, Page>
     */
    private function parseAll(): array
    {
        $files = glob($this->contentDir.'/*.md');

        if (false === $files) {
            throw new RuntimeException(\sprintf('Impossible de lire le répertoire de contenu %s.', $this->contentDir));
        }

        $pages = [];
        foreach ($files as $file) {
            $page = $this->parse($file);
            $pages[$page->slug] = $page;
        }

        ksort($pages);

        return $pages;
    }

    private function parse(string $file): Page
    {
        $source = file_get_contents($file);

        if (false === $source) {
            throw new RuntimeException(\sprintf('Fichier de contenu illisible : %s.', $file));
        }

        ['html' => $html, 'frontMatter' => $front] = $this->markdown->render($source);

        $defaultSlug = basename($file, '.md');
        $slug = self::readString($front, 'slug') ?? $defaultSlug;

        if (1 !== preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug)) {
            throw new RuntimeException(\sprintf('Slug invalide « %s » dans %s : minuscules, chiffres et tirets uniquement.', $slug, $file));
        }

        $title = self::readString($front, 'title');

        if (null === $title || '' === $title) {
            throw new RuntimeException(\sprintf('Le front matter de %s doit définir un "title".', $file));
        }

        $seo = self::readArray($front, 'seo');
        $template = self::readString($front, 'template') ?? 'default';

        // Le nom de gabarit sert à construire un chemin de template : il doit
        // être strictement contraint pour empêcher toute traversée de dossier.
        if (1 !== preg_match('/^[a-z0-9_-]+$/', $template)) {
            throw new RuntimeException(\sprintf('Nom de gabarit invalide « %s » dans %s.', $template, $file));
        }

        $menuData = self::readArray($front, 'menu');
        $menu = null;
        if ([] !== $menuData) {
            $menu = new MenuEntry(
                self::readString($menuData, 'label') ?? $title,
                self::readInt($menuData, 'weight') ?? 100,
            );
        }

        return new Page(
            slug: $slug,
            path: $slug === $this->homeSlug ? '/' : '/'.$slug,
            title: $title,
            html: $html,
            seoTitle: self::readString($seo, 'title') ?? $title,
            seoDescription: self::readString($seo, 'description') ?? '',
            ogImage: self::readString($seo, 'og_image'),
            noindex: self::readBool($seo, 'noindex') ?? false,
            template: $template,
            updated: self::readDate($front, 'updated'),
            menu: $menu,
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function readString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) || \is_int($value) ? (string) $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function readInt(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return \is_int($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function readBool(array $data, string $key): ?bool
    {
        $value = $data[$key] ?? null;

        return \is_bool($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function readArray(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (!\is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $k => $v) {
            if (\is_string($k)) {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function readDate(array $data, string $key): ?DateTimeImmutable
    {
        $value = $data[$key] ?? null;

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        // Sans le drapeau PARSE_DATETIME, Symfony YAML rend les dates non
        // quotées sous forme d'horodatage Unix.
        if (\is_int($value)) {
            return (new DateTimeImmutable())->setTimestamp($value);
        }

        if (!\is_string($value) || '' === $value) {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }
    }
}
