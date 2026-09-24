<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Les gabarits d'erreur ne sont pas rendus par une requête normale en test
 * (le mode debug affiche la page d'exception). On passe donc par les routes
 * de prévisualisation /_error, activées en test dans config/routes/framework.yaml.
 */
final class ErrorPageTest extends WebTestCase
{
    public function testNotFoundPageUsesTheSiteDesign(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/_error/404');

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'Page introuvable');
        self::assertCount(1, $crawler->filter('header.entete-site'));
        self::assertCount(1, $crawler->filter('nav.navigation'));
    }

    public function testServerErrorPageDoesNotDependOnTheContentLayer(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/_error/500');

        self::assertResponseStatusCodeSame(500);
        self::assertSelectorTextContains('h1', 'Une erreur technique est survenue');

        // Pas de menu : la panne peut justement venir de PageRepository.
        self::assertCount(0, $crawler->filter('.navigation__lien'));
        self::assertCount(1, $crawler->filter('.entete__marque img'));
    }

    public function testErrorPagesAreNeverIndexable(): void
    {
        $client = static::createClient();
        $client->request('GET', '/_error/404');

        self::assertCount(1, $client->getCrawler()->filter('meta[name="robots"]'));
    }
}
