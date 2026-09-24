<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RedirectTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function redirectionsProvider(): iterable
    {
        yield 'slash final' => ['/contact/', 'http://localhost/contact'];
        yield 'préfixe de langue hérité de Drupal' => ['/fr/contact', 'http://localhost/contact'];
        yield 'racine de langue' => ['/fr', 'http://localhost/'];
        yield 'alias de la page d\'accueil' => ['/accueil', 'http://localhost/'];
        yield 'préfixe et slash final combinés' => ['/fr/nos-actions/', 'http://localhost/nos-actions'];
    }

    #[DataProvider('redirectionsProvider')]
    public function testLegacyUrlsRedirectPermanently(string $depuis, string $vers): void
    {
        $client = static::createClient();
        $client->request('GET', $depuis);

        self::assertResponseStatusCodeSame(301);
        self::assertResponseRedirects($vers);
    }

    public function testQueryStringIsPreservedThroughRedirects(): void
    {
        $client = static::createClient();
        $client->request('GET', '/fr/contact?utm_source=newsletter');

        self::assertResponseRedirects('http://localhost/contact?utm_source=newsletter');
    }

    /**
     * Une 301 sur le webhook casserait la vérification de signature Stripe :
     * le corps de la requête n'est pas rejoué (CLAUDE.md §7 et §10).
     */
    public function testStripeWebhookIsNeverRedirected(): void
    {
        $client = static::createClient();
        $client->request('POST', '/stripe/webhook/');

        self::assertNotSame(301, $client->getResponse()->getStatusCode());
    }

    public function testUnknownLegacyPathsStillReturnNotFound(): void
    {
        $client = static::createClient();

        // Le tunnel de commande de bons n'a pas d'équivalent : une redirection
        // vers une page sans rapport serait traitée comme une soft 404.
        $client->request('GET', '/commande/panier');

        self::assertResponseStatusCodeSame(404);
    }
}
