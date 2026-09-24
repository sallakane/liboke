<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeControllerTest extends WebTestCase
{
    public function testHomePageIsReachableAndSeoReady(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('html[lang="fr"]');
        self::assertCount(1, $crawler->filter('h1'), 'La page doit contenir exactement un <h1>.');
        self::assertNotSame('', trim($crawler->filter('title')->text()));
        self::assertNotSame('', $crawler->filter('meta[name="description"]')->attr('content') ?? '');
        self::assertCount(1, $crawler->filter('link[rel="canonical"]'));
    }

    public function testLayoutIsSemanticAndAccessible(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertCount(1, $crawler->filter('header'));
        self::assertCount(1, $crawler->filter('main#contenu'));
        self::assertCount(1, $crawler->filter('footer'));
        self::assertCount(1, $crawler->filter('nav[aria-label]'));
        self::assertSame('Aller au contenu principal', trim($crawler->filter('a.lien-evitement')->text()));

        // Toute image doit porter un attribut alt (vide assumé si décorative).
        $crawler->filter('img')->each(static function ($img): void {
            self::assertNotNull($img->attr('alt'), \sprintf('Image sans attribut alt : %s', $img->attr('src')));
        });
    }

    public function testMainMenuIsRenderedWithCurrentPageMarked(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        self::assertCount(6, $crawler->filter('.navigation__lien'));
        self::assertCount(1, $crawler->filter('.navigation__lien[aria-current="page"]'));
        self::assertSame('Accueil', trim($crawler->filter('.navigation__lien[aria-current="page"]')->text()));
    }

    public function testHomePageHidesThePhotoBand(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        // Le thème Drupal n'affichait le bandeau que hors page d'accueil.
        self::assertCount(0, $crawler->filter('.bandeau-photos'));
    }

    public function testHeroImageIsPrioritisedForLargestContentfulPaint(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/');

        $hero = $crawler->filter('.accueil__visuel img');
        self::assertCount(1, $hero);
        self::assertSame('high', $hero->attr('fetchpriority'));
        self::assertNotNull($hero->attr('width'));
        self::assertNotNull($hero->attr('height'));
        self::assertCount(1, $crawler->filter('.accueil__visuel source[type="image/webp"]'));
    }

    public function testUnknownPageReturnsNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/page-qui-nexiste-pas');

        self::assertResponseStatusCodeSame(404);
    }
}
