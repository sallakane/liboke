<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Content\Page;
use App\Content\PageRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PageControllerTest extends WebTestCase
{
    /**
     * Parcourt toutes les pages du contenu (CLAUDE.md §14) : le test couvre
     * automatiquement toute page ajoutée dans content/pages.
     */
    public function testEveryContentPageIsServedAndSeoReady(): void
    {
        $client = static::createClient();
        $pages = static::getContainer()->get(PageRepository::class);
        self::assertInstanceOf(PageRepository::class, $pages);

        $all = $pages->all();
        self::assertNotEmpty($all, 'Aucune page trouvée dans content/pages.');

        foreach ($all as $page) {
            $crawler = $client->request('GET', $page->path);

            self::assertResponseIsSuccessful(\sprintf('La page %s doit répondre 200.', $page->path));
            self::assertCount(1, $crawler->filter('h1'), \sprintf('%s doit contenir exactement un <h1>.', $page->path));

            $title = trim($crawler->filter('title')->text());
            self::assertNotSame('', $title, \sprintf('%s doit avoir un <title>.', $page->path));

            $description = $crawler->filter('meta[name="description"]')->attr('content');
            self::assertNotNull($description);
            self::assertNotSame('', trim($description), \sprintf('%s doit avoir une meta description.', $page->path));

            self::assertCount(1, $crawler->filter('link[rel="canonical"]'), \sprintf('%s doit avoir une canonique.', $page->path));
        }
    }

    public function testHomeSlugRedirectsToTheRoot(): void
    {
        $client = static::createClient();
        $client->request('GET', '/accueil');

        // Sinon l'accueil existerait à deux URLs : contenu dupliqué.
        // C'était aussi un alias de l'ancien site Drupal, d'où la 301
        // plutôt qu'une 404 (config/redirects.yaml).
        self::assertResponseStatusCodeSame(301);
        self::assertResponseRedirects('http://localhost/');
    }

    public function testUnknownSlugReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/page-qui-nexiste-pas');

        self::assertResponseStatusCodeSame(404);
    }

    public function testMarkdownIsRenderedAsHeadings(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/l-association');

        self::assertGreaterThan(0, $crawler->filter('.page__contenu h2')->count());
    }

    public function testMenuIsBuiltFromFrontMatterAndSortedByWeight(): void
    {
        self::bootKernel();
        $pages = static::getContainer()->get(PageRepository::class);
        self::assertInstanceOf(PageRepository::class, $pages);

        $labels = array_map(static fn (Page $p): string => $p->menu->label ?? '', $pages->menu());

        self::assertSame(
            ['Accueil', "L'association", 'Nos actions', 'Partenaires', 'Nous soutenir', 'Contact'],
            $labels,
        );
    }

    public function testLegalPagesAreExcludedFromTheMainMenu(): void
    {
        self::bootKernel();
        $pages = static::getContainer()->get(PageRepository::class);
        self::assertInstanceOf(PageRepository::class, $pages);

        $slugs = array_map(static fn (Page $p): string => $p->slug, $pages->menu());

        self::assertNotContains('mentions-legales', $slugs);
        self::assertNotContains('politique-de-confidentialite', $slugs);
    }
}
