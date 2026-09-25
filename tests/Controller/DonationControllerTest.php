<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Donation;
use App\Entity\DonationStatus;
use App\Repository\DonationRepository;
use App\Tests\Double\FakeCheckoutSessionFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class DonationControllerTest extends WebTestCase
{
    public function testTheSupportPageShowsSuggestedAmounts(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/nous-soutenir');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('form.formulaire--don'));

        // Quatre montants suggérés plus « Autre montant ».
        self::assertCount(5, $crawler->filter('input[type="radio"]'));
        self::assertCount(1, $crawler->filter('#donation_custom'));

        // Stripe.js n'est chargé qu'au passage au paiement, par le contrôleur
        // Stimulus : ni son poids ni ses cookies pour un simple visiteur.
        self::assertStringNotContainsString('js.stripe.com', (string) $client->getResponse()->getContent());
        self::assertCount(1, $crawler->filter('[data-controller="don"][data-don-cle-publique-value="pk_test_liboke"]'));
        self::assertCount(0, $crawler->filter('[data-don-secret-value]'));

        // C'est une page de contenu indexable, au contraire de la page de
        // retour de paiement : elle figure donc au sitemap.
        $client->request('GET', '/sitemap.xml');
        self::assertStringContainsString('/nous-soutenir', (string) $client->getResponse()->getContent());
    }

    /**
     * Parcours nominal : le contrôleur Stimulus poste en JSON et reçoit le
     * secret client qui ouvre le formulaire Stripe intégré.
     */
    public function testASuggestedAmountCreatesAPendingDonationAndReturnsTheClientSecret(): void
    {
        $client = static::createClient();
        $this->purge($client);

        $this->poster($client, ['preset' => '2500'], json: true);

        self::assertResponseIsSuccessful();
        $reponse = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($reponse);
        self::assertIsString($reponse['clientSecret'] ?? null);
        self::assertStringEndsWith('_secret_test', $reponse['clientSecret']);
        self::assertSame('http://localhost/don/merci', $this->fake($client)->derniereUrlRetour);

        $dons = $this->donations($client)->findAll();
        self::assertCount(1, $dons);
        self::assertSame(2500, $dons[0]->getAmountCents());
        self::assertSame('EUR', $dons[0]->getCurrency());
        self::assertSame(DonationStatus::Pending, $dons[0]->getStatus(), 'Seul le webhook peut confirmer un paiement.');
        self::assertNull($dons[0]->getPaidAt());
    }

    /**
     * Sans le contrôleur Stimulus (formulaire envoyé classiquement), la page
     * est réaffichée avec le paiement ouvert.
     */
    public function testAPlainFormSubmissionReopensThePageWithThePayment(): void
    {
        $client = static::createClient();
        $this->purge($client);

        $crawler = $this->poster($client, ['preset' => '2500']);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-controller="don"][data-don-secret-value$="_secret_test"]'));
        self::assertCount(1, $crawler->filter('[data-don-target="formulaire"][hidden]'));
        self::assertCount(0, $crawler->filter('[data-don-target="paiement"][hidden]'));
    }

    public function testInvalidAmountsComeBackAsAFormFragmentInJson(): void
    {
        $client = static::createClient();
        $this->purge($client);

        $this->poster($client, ['preset' => 'libre', 'custom' => '1'], json: true);

        self::assertResponseStatusCodeSame(422);
        $reponse = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($reponse);
        self::assertIsString($reponse['form'] ?? null);
        self::assertStringContainsString('Le montant doit être compris entre', $reponse['form']);
        self::assertStringContainsString('data-action="don#envoyer"', $reponse['form']);
        self::assertArrayNotHasKey('clientSecret', $reponse);
        self::assertNull($this->fake($client)->dernierMontant);
    }

    public function testTheAmountSentToStripeIsTheOneValidatedServerSide(): void
    {
        $client = static::createClient();
        $this->purge($client);

        $this->poster($client, ['preset' => 'libre', 'custom' => '75'], json: true);

        self::assertResponseIsSuccessful();
        self::assertSame(7500, $this->fake($client)->dernierMontant, '75 € doivent devenir 7500 centimes.');
        self::assertSame('EUR', $this->fake($client)->derniereDevise);
    }

    /**
     * Cœur de la règle 1 du §10 : un montant posté qui ne figure pas parmi
     * les suggestions ne doit jamais atteindre Stripe.
     */
    public function testAForgedPresetAmountIsRefused(): void
    {
        $client = static::createClient();
        $this->purge($client);

        $this->poster($client, ['preset' => '1']);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->fake($client)->dernierMontant, 'Stripe ne doit pas avoir été appelé.');
        self::assertCount(0, $this->donations($client)->findAll());
    }

    public function testAnAmountBelowTheMinimumIsRefused(): void
    {
        $client = static::createClient();
        $this->purge($client);

        $this->poster($client, ['preset' => 'libre', 'custom' => '1']);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->fake($client)->dernierMontant);
        self::assertCount(0, $this->donations($client)->findAll());
    }

    public function testAnAmountAboveTheMaximumIsRefused(): void
    {
        $client = static::createClient();
        $this->purge($client);

        $this->poster($client, ['preset' => 'libre', 'custom' => '999999']);

        self::assertResponseStatusCodeSame(422);
        self::assertNull($this->fake($client)->dernierMontant);
    }

    public function testAMissingCustomAmountIsRefused(): void
    {
        $client = static::createClient();
        $this->purge($client);

        $this->poster($client, ['preset' => 'libre']);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->donations($client)->findAll());
    }

    public function testAStripeOutageShowsAMessageInsteadOfAnError(): void
    {
        $client = static::createClient();
        $this->purge($client);
        $this->fake($client)->echoue = true;

        $this->poster($client, ['preset' => '1000']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alerte--erreur', 'momentanément indisponible');
        self::assertCount(0, $this->donations($client)->findAll(), 'Aucun don ne doit être enregistré sans session Stripe.');
    }

    public function testCheckoutIsRateLimited(): void
    {
        $client = static::createClient();
        $this->purge($client);

        for ($i = 1; $i <= 10; ++$i) {
            $this->poster($client, ['preset' => '1000'], json: true);
            self::assertResponseIsSuccessful(\sprintf('La tentative %d doit passer.', $i));
        }

        $this->poster($client, ['preset' => '1000']);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alerte--erreur', 'Trop de tentatives');
        self::assertCount(10, $this->donations($client)->findAll());
    }

    /**
     * Le retour du navigateur n'est pas une preuve d'encaissement : la page
     * ne doit rien affirmer (CLAUDE.md §10).
     */
    public function testTheThanksPageDoesNotClaimThePaymentIsConfirmed(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/don/merci');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('meta[name="robots"][content*="noindex"]'));

        $texte = $crawler->filter('.page__contenu')->text();
        self::assertStringContainsString('dès que le paiement aura été validé', $texte);
        self::assertStringNotContainsString('paiement confirmé', $texte);
    }

    /**
     * Poste le formulaire comme le ferait un navigateur.
     *
     * Le Referer est indispensable : la protection CSRF sans état de
     * Symfony 8 vérifie l'origine de la requête lorsqu'aucun jeton n'est
     * présent dans la charge utile.
     *
     * @param array<string, string> $donation
     * @param bool                  $json     requête du contrôleur Stimulus (Accept JSON)
     */
    private function poster(KernelBrowser $client, array $donation, bool $json = false): Crawler
    {
        // Reproduit ce qu'un navigateur envoie : le champ CSRF contient le
        // marqueur littéral « csrf-token », et l'origine de la requête est
        // vérifiée par la protection sans état de Symfony 8.
        $donation['_token'] = 'csrf-token';

        $serveur = ['HTTP_REFERER' => 'http://localhost/nous-soutenir'];

        if ($json) {
            $serveur['HTTP_ACCEPT'] = 'application/json';
        }

        return $client->request('POST', '/don/checkout', ['donation' => $donation], [], $serveur);
    }

    private function fake(KernelBrowser $client): FakeCheckoutSessionFactory
    {
        $service = $client->getContainer()->get(FakeCheckoutSessionFactory::class);
        self::assertInstanceOf(FakeCheckoutSessionFactory::class, $service);

        return $service;
    }

    private function donations(KernelBrowser $client): DonationRepository
    {
        $depot = $client->getContainer()->get(DonationRepository::class);
        self::assertInstanceOf(DonationRepository::class, $depot);

        return $depot;
    }

    private function purge(KernelBrowser $client): void
    {
        $manager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);
        $manager->createQuery('DELETE FROM '.Donation::class.' d')->execute();

        $limiteur = $client->getContainer()->get('limiter.donation_checkout');
        self::assertInstanceOf(RateLimiterFactoryInterface::class, $limiteur);
        $limiteur->create('127.0.0.1')->reset();
    }
}
