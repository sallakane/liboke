<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Content\PageRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SeoControllerTest extends WebTestCase
{
    public function testSitemapIsValidXmlAndListsEveryIndexablePage(): void
    {
        $client = static::createClient();
        $client->request('GET', '/sitemap.xml');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/xml; charset=UTF-8');

        $pages = static::getContainer()->get(PageRepository::class);
        self::assertInstanceOf(PageRepository::class, $pages);

        $xml = simplexml_load_string((string) $client->getResponse()->getContent());
        self::assertNotFalse($xml, 'Le sitemap doit être un XML valide.');

        $locs = [];
        foreach ($xml->url as $url) {
            $locs[] = (string) $url->loc;
        }

        foreach ($pages->indexable() as $page) {
            $attendu = 'http://localhost'.('/' === $page->path ? '/' : $page->path);
            self::assertContains($attendu, $locs, \sprintf('%s doit figurer au sitemap.', $page->path));
        }

        self::assertCount(\count($pages->indexable()), $locs);
    }

    public function testSitemapUsesAbsoluteUrlsOnly(): void
    {
        $client = static::createClient();
        $client->request('GET', '/sitemap.xml');

        $xml = simplexml_load_string((string) $client->getResponse()->getContent());
        self::assertNotFalse($xml);

        foreach ($xml->url as $url) {
            self::assertStringStartsWith('http', (string) $url->loc);
        }
    }

    public function testRobotsForbidsEverythingWhenTheSiteIsNotIndexable(): void
    {
        $client = static::createClient();
        $client->request('GET', '/robots.txt');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/plain; charset=UTF-8');

        $corps = (string) $client->getResponse()->getContent();
        self::assertStringContainsString('Disallow: /', $corps);
        self::assertStringNotContainsString('Sitemap:', $corps);
    }

    public function testNonIndexableSiteSendsTheRobotsHeader(): void
    {
        $client = static::createClient();
        $client->request('GET', '/');

        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
    }

    public function testEveryPageCarriesAnAbsoluteCanonicalUrl(): void
    {
        $client = static::createClient();
        $pages = static::getContainer()->get(PageRepository::class);
        self::assertInstanceOf(PageRepository::class, $pages);

        foreach ($pages->all() as $page) {
            $crawler = $client->request('GET', $page->path);
            $canonical = $crawler->filter('link[rel="canonical"]')->attr('href');

            // Assertion sur l'URL complète : plus stricte qu'un suffixe, et
            // elle vérifie du même coup que l'hôte canonique est bien appliqué.
            self::assertSame(
                'http://localhost'.('/' === $page->path ? '/' : $page->path),
                $canonical,
                \sprintf('Canonique incorrecte sur %s.', $page->path),
            );
        }
    }

    /**
     * La fourchette est imposée par CLAUDE.md §6. Tant que le contenu est
     * provisoire, seules les descriptions réellement rédigées sont contrôlées :
     * ce test devient exigeant au fur et à mesure que le client fournit ses textes.
     */
    public function testWrittenMetaDescriptionsRespectTheRecommendedLength(): void
    {
        self::bootKernel();
        $pages = static::getContainer()->get(PageRepository::class);
        self::assertInstanceOf(PageRepository::class, $pages);
        self::assertNotSame([], $pages->all());

        foreach ($pages->all() as $page) {
            if (str_contains($page->seoDescription, '[À COMPLÉTER]')) {
                continue;
            }

            $longueur = mb_strlen($page->seoDescription);
            self::assertGreaterThanOrEqual(150, $longueur, \sprintf('Description trop courte sur %s.', $page->path));
            self::assertLessThanOrEqual(160, $longueur, \sprintf('Description trop longue sur %s.', $page->path));
        }
    }

    public function testHomePageDeclaresTheOrganisationAndTheWebsite(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $blocs = $crawler->filter('script[type="application/ld+json"]')->each(
            static fn ($node): mixed => json_decode($node->text(), true, 512, \JSON_THROW_ON_ERROR),
        );

        $types = array_map(static fn (mixed $b): mixed => \is_array($b) ? ($b['@type'] ?? null) : null, $blocs);

        self::assertContains('NGO', $types);
        self::assertContains('WebSite', $types);
        self::assertNotContains('BreadcrumbList', $types, "L'accueil n'a pas de fil d'Ariane.");
    }

    public function testInnerPagesDeclareABreadcrumb(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/contact');

        $bloc = json_decode(
            $crawler->filter('script[type="application/ld+json"]')->text(),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($bloc);
        self::assertSame('BreadcrumbList', $bloc['@type']);
        self::assertIsArray($bloc['itemListElement']);
        self::assertCount(2, $bloc['itemListElement']);

        // Et le fil d'Ariane est aussi visible : le thème Drupal le masquait.
        self::assertCount(1, $crawler->filter('nav.fil-ariane'));
    }

    public function testPlaceholderContactDetailsAreNotPublishedInStructuredData(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $organisation = json_decode(
            $crawler->filter('script[type="application/ld+json"]')->first()->text(),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        );

        self::assertIsArray($organisation);
        self::assertArrayNotHasKey('telephone', $organisation);
        self::assertArrayNotHasKey('address', $organisation);
        self::assertArrayHasKey('sameAs', $organisation);
    }

    public function testSocialPreviewMetadataIsPresent(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertCount(1, $crawler->filter('meta[property="og:image"]'));
        self::assertCount(1, $crawler->filter('meta[name="twitter:card"]'));

        $image = $crawler->filter('meta[property="og:image"]')->attr('content');
        self::assertNotNull($image);
        self::assertStringStartsWith('http://localhost/assets/', $image);
    }
}
