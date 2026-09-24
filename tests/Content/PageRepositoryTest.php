<?php

declare(strict_types=1);

namespace App\Tests\Content;

use App\Content\MarkdownRenderer;
use App\Content\PageRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Cache\CacheInterface;

final class PageRepositoryTest extends TestCase
{
    private const string HOME_SLUG = 'accueil';

    public function testHomePageIsExposedOnTheRoot(): void
    {
        $pages = $this->repository(new ArrayAdapter());

        self::assertSame('/', $pages->home()->path);
        self::assertTrue($pages->home()->isHome());
        self::assertSame('/contact', $pages->find('contact')?->path);
    }

    public function testUnknownSlugReturnsNull(): void
    {
        self::assertNull($this->repository(new ArrayAdapter())->find('inexistant'));
    }

    /**
     * Chemin réellement emprunté en production : les pages sont sérialisées
     * dans le cache puis relues. Un objet non sérialisable casserait le site
     * en prod sans jamais se voir en développement, où le cache est ignoré.
     */
    public function testPagesSurviveSerialisationThroughTheCache(): void
    {
        // ArrayAdapter ne fait que cloner : il ne prouverait rien.
        // Un adaptateur fichier sérialise réellement les objets Page.
        $repertoire = sys_get_temp_dir().'/liboke-test-cache-'.bin2hex(random_bytes(6));
        $cache = new FilesystemAdapter('pages', 0, $repertoire);

        try {
            $ecriture = $this->repository($cache, debug: false)->all();
            $lecture = $this->repository($cache, debug: false)->all();

            self::assertEquals($ecriture, $lecture);
            self::assertNotSame([], $lecture);
            self::assertSame(
                array_keys($ecriture),
                array_keys($lecture),
                'Le cache doit restituer exactement les mêmes pages.',
            );
        } finally {
            (new Filesystem())->remove($repertoire);
        }
    }

    public function testMenuIsSortedByWeight(): void
    {
        $poids = array_map(
            static fn ($page): int => $page->menu->weight ?? 0,
            $this->repository(new ArrayAdapter())->menu(),
        );

        $trie = $poids;
        sort($trie);

        self::assertSame($trie, $poids);
    }

    public function testIndexablePagesExcludeNothingByDefault(): void
    {
        $pages = $this->repository(new ArrayAdapter());

        self::assertCount(\count($pages->all()), $pages->indexable());
    }

    private function repository(CacheInterface $cache, bool $debug = true): PageRepository
    {
        return new PageRepository(
            \dirname(__DIR__, 2).'/content/pages',
            self::HOME_SLUG,
            $debug,
            $cache,
            new MarkdownRenderer(),
        );
    }
}
