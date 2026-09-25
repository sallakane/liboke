<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ContactMessage;
use App\Entity\Donation;
use App\Security\AdminUserProvider;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

final class AdminControllerTest extends WebTestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function pagesProtegees(): iterable
    {
        yield 'accueil admin' => ['/admin'];
        yield 'dons' => ['/admin/dons'];
        yield 'export CSV' => ['/admin/dons.csv'];
        yield 'messages' => ['/admin/messages'];
    }

    /**
     * CLAUDE.md §14 : un visiteur anonyme est renvoyé vers l'authentification.
     */
    #[DataProvider('pagesProtegees')]
    public function testAnonymousVisitorsAreSentToTheLoginPage(string $chemin): void
    {
        $client = static::createClient();
        $client->request('GET', $chemin);

        self::assertResponseRedirects('http://localhost/admin/connexion');
    }

    public function testTheLoginPageIsNeverIndexedNorCached(): void
    {
        $client = static::createClient();
        $crawler = $client->request('GET', '/admin/connexion');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('h1'));
        self::assertCount(1, $crawler->filter('meta[name="robots"][content*="noindex"]'));
        self::assertResponseHeaderSame('X-Robots-Tag', 'noindex, nofollow');
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testTheAdministratorCanLogIn(): void
    {
        $client = static::createClient();
        $this->seConnecter($client, 'admin', 'mot-de-passe-de-test');

        self::assertResponseRedirects('http://localhost/admin/dons');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Dons');
    }

    public function testAWrongPasswordIsRefusedWithAVagueMessage(): void
    {
        $client = static::createClient();
        $this->seConnecter($client, 'admin', 'mauvais');

        self::assertResponseRedirects('http://localhost/admin/connexion');
        $client->followRedirect();
        self::assertSelectorTextContains('.alerte--erreur', 'Identifiant ou mot de passe incorrect');

        $client->request('GET', '/admin/dons');
        self::assertResponseRedirects('http://localhost/admin/connexion', null, 'Un échec ne doit ouvrir aucune session.');
    }

    public function testAnUnknownUsernameGetsTheSameMessage(): void
    {
        $client = static::createClient();
        $this->seConnecter($client, 'root', 'mot-de-passe-de-test');

        $client->followRedirect();
        self::assertSelectorTextContains('.alerte--erreur', 'Identifiant ou mot de passe incorrect');
    }

    public function testTheDonationListShowsTotalsAndFiltersByStatus(): void
    {
        $client = $this->clientConnecte();
        $this->viderBase($client);

        $paye = new Donation('cs_test_paye', 2500, 'EUR');
        $paye->setDonor('Awa Diop', 'awa@example.org');
        $paye->markPaid('pi_test', 'cus_test');
        $this->enregistrer($client, $paye, new Donation('cs_test_attente', 1000, 'EUR'));

        $crawler = $client->request('GET', '/admin/dons');

        self::assertResponseIsSuccessful();
        self::assertCount(2, $crawler->filter('.admin__tableau tbody tr'));
        self::assertStringContainsString('1 don · 25,00 €', $crawler->filter('.admin__totaux')->text());
        self::assertStringContainsString('Awa Diop', $crawler->filter('.admin__tableau')->text());

        $crawler = $client->request('GET', '/admin/dons?statut=pending');

        self::assertCount(1, $crawler->filter('.admin__tableau tbody tr'));
        self::assertStringContainsString('cs_test_attente', $crawler->filter('.admin__tableau')->text());
    }

    public function testTheDateFilterIncludesTheWholeLastDay(): void
    {
        $client = $this->clientConnecte();
        $this->viderBase($client);

        $this->enregistrer($client, new Donation('cs_test_mars', 1000, 'EUR'), new Donation('cs_test_avril', 1000, 'EUR'));
        $this->dater($client, 'cs_test_mars', '2026-03-31 23:30:00');
        $this->dater($client, 'cs_test_avril', '2026-04-01 00:10:00');

        $crawler = $client->request('GET', '/admin/dons?du=2026-03-01&au=2026-03-31');

        self::assertCount(1, $crawler->filter('.admin__tableau tbody tr'));
        self::assertStringContainsString('cs_test_mars', $crawler->filter('.admin__tableau')->text());
    }

    public function testTheCsvExportIsSpreadsheetReadyAndDefusesFormulas(): void
    {
        $client = $this->clientConnecte();
        $this->viderBase($client);

        $don = new Donation('cs_test_csv', 4250, 'EUR');
        // Nom saisi chez Stripe par un inconnu : ne doit jamais devenir une
        // formule dans le tableur du trésorier.
        $don->setDonor('=HYPERLINK("http://example.test")', 'donateur@example.org', 'Rue des Écoles', '75005', 'Paris', 'FR');
        $don->markPaid('pi_test_csv', null);
        $this->enregistrer($client, $don);

        $client->request('GET', '/admin/dons.csv?statut=paid');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/csv; charset=UTF-8');
        self::assertStringContainsString('attachment; filename=dons-liboke-', (string) $client->getResponse()->headers->get('Content-Disposition'));

        $csv = (string) $client->getInternalResponse()->getContent();
        self::assertStringStartsWith("\u{FEFF}\"Créé le\";\"Payé le\";Statut", $csv);

        $lignes = array_values(array_filter(explode("\n", $csv)));
        self::assertCount(2, $lignes);
        self::assertStringContainsString(';Payé;one_time;42,50;EUR;', $lignes[1]);
        self::assertStringContainsString('"\'=HYPERLINK(""http://example.test"")"', $lignes[1]);
        self::assertStringContainsString('Rue des Écoles', $lignes[1]);
    }

    public function testContactMessagesAreListedAndEscaped(): void
    {
        $client = $this->clientConnecte();
        $this->viderBase($client);

        $this->enregistrer($client, new ContactMessage(
            'Fatou Ndiaye',
            'fatou@example.org',
            'Partenariat',
            "Bonjour,\n<script>alert(1)</script>",
            true,
        ));

        $crawler = $client->request('GET', '/admin/messages');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.admin__messages h2', 'Partenariat');
        self::assertCount(0, $crawler->filter('.admin__message script'), 'Le message doit être échappé.');
        self::assertStringContainsString('&lt;script&gt;', (string) $client->getResponse()->getContent());
    }

    /**
     * Lecture seule (CLAUDE.md §10) : aucune route n'accepte d'écriture.
     */
    public function testTheAdminIsReadOnly(): void
    {
        $client = $this->clientConnecte();

        foreach (['/admin/dons', '/admin/messages', '/admin/dons.csv'] as $chemin) {
            $client->request('POST', $chemin);
            self::assertResponseStatusCodeSame(405, $chemin);
        }
    }

    /**
     * Le pare-feu ne couvre que /admin : une page publique n'ouvre aucune
     * session et reste cachable.
     */
    public function testPublicPagesDoNotOpenASession(): void
    {
        $client = static::createClient();
        $client->request('GET', '/nous-soutenir');

        self::assertResponseIsSuccessful();
        self::assertFalse($client->getResponse()->headers->has('Set-Cookie'));
    }

    public function testNobodyCanLogInWhileTheAccountIsNotConfigured(): void
    {
        $provider = new AdminUserProvider('', '');

        $this->expectException(UserNotFoundException::class);
        $provider->loadUserByIdentifier('');
    }

    private function seConnecter(KernelBrowser $client, string $identifiant, string $motDePasse): void
    {
        // Le limiteur de tentatives stocke ses compteurs dans le cache de
        // fichiers, qui survit d'une exécution de la suite à l'autre : après
        // quelques lancements rapprochés, 127.0.0.1 serait bloqué.
        $limiteurs = $client->getContainer()->get('cache.rate_limiter');
        self::assertInstanceOf(CacheItemPoolInterface::class, $limiteurs);
        $limiteurs->clear();

        // Même protocole que les autres formulaires : marqueur CSRF littéral
        // et en-tête Referer (protection CSRF sans état de Symfony 8).
        $client->request('POST', '/admin/connexion', [
            '_username' => $identifiant,
            '_password' => $motDePasse,
            '_csrf_token' => 'csrf-token',
        ], [], ['HTTP_REFERER' => 'http://localhost/admin/connexion']);
    }

    private function clientConnecte(): KernelBrowser
    {
        $client = static::createClient();

        // L'utilisateur doit venir du vrai fournisseur : à chaque requête,
        // Symfony compare son hash à celui de l'environnement.
        $provider = static::getContainer()->get(AdminUserProvider::class);
        self::assertInstanceOf(AdminUserProvider::class, $provider);
        $client->loginUser($provider->loadUserByIdentifier('admin'), 'admin');

        return $client;
    }

    private function manager(KernelBrowser $client): EntityManagerInterface
    {
        $manager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    private function viderBase(KernelBrowser $client): void
    {
        $manager = $this->manager($client);
        $manager->createQuery('DELETE FROM '.Donation::class.' d')->execute();
        $manager->createQuery('DELETE FROM '.ContactMessage::class.' m')->execute();
    }

    private function enregistrer(KernelBrowser $client, object ...$entites): void
    {
        $manager = $this->manager($client);

        foreach ($entites as $entite) {
            $manager->persist($entite);
        }

        $manager->flush();
        $manager->clear();
    }

    private function dater(KernelBrowser $client, string $session, string $date): void
    {
        $this->manager($client)
            ->createQuery('UPDATE '.Donation::class.' d SET d.createdAt = :date WHERE d.stripeSessionId = :session')
            ->setParameter('date', new DateTimeImmutable($date))
            ->setParameter('session', $session)
            ->execute();
    }
}
