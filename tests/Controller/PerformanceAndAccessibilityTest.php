<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Content\PageRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Garde-fous de la phase 8 (CLAUDE.md §8), sur toutes les pages publiques :
 * ils tiendront quand le contenu réel arrivera.
 */
final class PerformanceAndAccessibilityTest extends WebTestCase
{
    /**
     * @return list<string>
     */
    private function cheminsPublics(): array
    {
        $pages = static::getContainer()->get(PageRepository::class);
        self::assertInstanceOf(PageRepository::class, $pages);

        $chemins = array_map(static fn ($page): string => $page->path, $pages->all());

        return array_values(array_unique([...$chemins, '/contact', '/nous-soutenir']));
    }

    public function testEveryImageHasAnAlternativeTextAndIntrinsicDimensions(): void
    {
        $client = static::createClient();

        foreach ($this->cheminsPublics() as $chemin) {
            $crawler = $client->request('GET', $chemin);
            self::assertResponseIsSuccessful($chemin);

            $crawler->filter('img')->each(static function (Crawler $img) use ($chemin): void {
                $src = (string) $img->attr('src');
                self::assertNotNull($img->attr('alt'), \sprintf('%s : <img src="%s"> sans attribut alt.', $chemin, $src));
                self::assertNotEmpty($img->attr('width'), \sprintf('%s : <img src="%s"> sans width.', $chemin, $src));
                self::assertNotEmpty($img->attr('height'), \sprintf('%s : <img src="%s"> sans height.', $chemin, $src));
            });

            self::assertLessThanOrEqual(1, $crawler->filter('img[fetchpriority="high"]')->count(), \sprintf('%s : une seule image prioritaire au plus.', $chemin));
            self::assertCount(0, $crawler->filter('img[fetchpriority="high"][loading="lazy"]'), $chemin);
        }
    }

    public function testTheHomeImageIsTheLcpCandidate(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $image = $crawler->filter('.accueil__visuel img');
        self::assertCount(1, $image);
        self::assertSame('high', $image->attr('fetchpriority'));
        self::assertNotEmpty($image->attr('srcset'));
        self::assertCount(1, $crawler->filter('.accueil__visuel source[type="image/webp"]'));
    }

    public function testTheMobileMenuIsADisclosureButton(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $bouton = $crawler->filter('nav.navigation button.navigation__bouton');
        self::assertCount(1, $bouton);
        self::assertSame('false', $bouton->attr('aria-expanded'));
        self::assertSame('menu-principal', $bouton->attr('aria-controls'));
        self::assertCount(1, $crawler->filter('ul#menu-principal'));
    }

    /**
     * Pages identiques pour tous : cache partagé autorisé.
     *
     * @return iterable<string, array{string}>
     */
    public static function pagesCachables(): iterable
    {
        yield 'accueil' => ['/'];
        yield 'page de contenu' => ['/l-association'];
        yield 'sitemap' => ['/sitemap.xml'];
        yield 'robots' => ['/robots.txt'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pagesCachables')]
    public function testContentPagesAreCacheable(string $chemin): void
    {
        $client = static::createClient();
        $client->request('GET', $chemin);

        $cache = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringContainsString('public', $cache, $chemin);
        self::assertStringContainsString('s-maxage=3600', $cache, $chemin);
        self::assertStringContainsString('max-age=600', $cache, $chemin);
    }

    /**
     * Une page publiée plus tard ne doit pas rester introuvable dans un
     * cache partagé.
     */
    public function testANotFoundPageIsNotSharedCached(): void
    {
        $client = static::createClient();
        $client->request('GET', '/page-qui-nexiste-pas');

        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('s-maxage', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    /**
     * Pages à formulaire : jetons et messages propres à chaque visiteur.
     *
     * @return iterable<string, array{string}>
     */
    public static function pagesPrivees(): iterable
    {
        yield 'contact' => ['/contact'];
        yield 'don' => ['/nous-soutenir'];
        yield 'admin' => ['/admin/connexion'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('pagesPrivees')]
    public function testPagesWithFormsAreNeverSharedCached(string $chemin): void
    {
        $client = static::createClient();
        $client->request('GET', $chemin);

        $cache = (string) $client->getResponse()->headers->get('Cache-Control');
        self::assertStringNotContainsString('public', $cache, $chemin);
        self::assertStringNotContainsString('s-maxage', $cache, $chemin);
    }
}
