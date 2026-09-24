<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Donation;
use App\Entity\DonationStatus;
use App\Entity\StripeEvent;
use App\Message\SendDonationThanks;
use App\Repository\DonationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class StripeWebhookTest extends WebTestCase
{
    private const string SECRET = 'whsec_test_liboke';

    public function testAnInvalidSignatureIsRejected(): void
    {
        $client = static::createClient();
        $this->purge($client);
        $this->donationEnAttente($client, 'cs_test_sig');

        $charge = $this->chargeUtile('evt_sig', 'checkout.session.completed', $this->sessionPayee('cs_test_sig'));
        $this->envoyer($client, $charge, 't=1,v1=0000000000000000000000000000000000000000000000000000000000000000');

        self::assertResponseStatusCodeSame(400);
        self::assertSame(DonationStatus::Pending, $this->don($client, 'cs_test_sig')->getStatus());
        self::assertCount(0, $this->evenements($client));
    }

    public function testAMissingSignatureIsRejected(): void
    {
        $client = static::createClient();
        $this->purge($client);

        $client->request('POST', '/stripe/webhook', [], [], ['CONTENT_TYPE' => 'application/json'], '{}');

        self::assertResponseStatusCodeSame(400);
    }

    /**
     * Le corps est signé tel quel : modifier un octet après signature doit
     * invalider la requête.
     */
    public function testATamperedPayloadIsRejected(): void
    {
        $client = static::createClient();
        $this->purge($client);
        $this->donationEnAttente($client, 'cs_test_falsifie');

        $original = $this->chargeUtile('evt_falsifie', 'checkout.session.completed', $this->sessionPayee('cs_test_falsifie'));
        $signature = $this->signer($original);
        $falsifie = str_replace('"amount_total":2500', '"amount_total":1', $original);
        self::assertNotSame($original, $falsifie, 'La charge utile doit réellement avoir été modifiée.');

        $this->envoyer($client, $falsifie, $signature);

        self::assertResponseStatusCodeSame(400);
        self::assertSame(DonationStatus::Pending, $this->don($client, 'cs_test_falsifie')->getStatus());
    }

    public function testACompletedCheckoutMarksTheDonationPaidAndQueuesTheThanks(): void
    {
        $client = static::createClient();
        $this->purge($client);
        $this->donationEnAttente($client, 'cs_test_ok');

        $charge = $this->chargeUtile('evt_ok', 'checkout.session.completed', $this->sessionPayee('cs_test_ok'));
        $this->envoyer($client, $charge, $this->signer($charge));

        self::assertResponseIsSuccessful();
        self::assertSame('Processed', (string) $client->getResponse()->getContent());

        $don = $this->don($client, 'cs_test_ok');
        self::assertSame(DonationStatus::Paid, $don->getStatus());
        self::assertSame('pi_test_ok', $don->getStripePaymentIntentId());
        self::assertSame('cus_test_ok', $don->getStripeCustomerId());
        self::assertSame('Awa Ndiaye', $don->getDonorName());
        self::assertSame('awa@example.org', $don->getDonorEmail());
        self::assertNotNull($don->getPaidAt());

        // Le remerciement sort de la requête : le webhook doit répondre vite.
        $messages = $this->fileAsync($client)->getSent();
        self::assertCount(1, $messages);
        self::assertInstanceOf(SendDonationThanks::class, $messages[0]->getMessage());
    }

    /**
     * Stripe rejoue ses événements. Le rejeu ne doit ni recompter le don ni
     * renvoyer un second remerciement (CLAUDE.md §10, règle 3).
     */
    public function testReplayingTheSameEventChangesNothing(): void
    {
        $client = static::createClient();
        $this->purge($client);
        $this->donationEnAttente($client, 'cs_test_rejeu');

        $charge = $this->chargeUtile('evt_rejeu', 'checkout.session.completed', $this->sessionPayee('cs_test_rejeu'));
        $signature = $this->signer($charge);

        $this->envoyer($client, $charge, $signature);
        self::assertSame('Processed', (string) $client->getResponse()->getContent());
        $premierPaiement = $this->don($client, 'cs_test_rejeu')->getPaidAt();

        $this->envoyer($client, $charge, $this->signer($charge));

        self::assertResponseIsSuccessful();
        self::assertSame('Duplicate', (string) $client->getResponse()->getContent());
        self::assertEquals($premierPaiement, $this->don($client, 'cs_test_rejeu')->getPaidAt(), 'La date de paiement ne doit pas bouger.');
        self::assertCount(1, $this->evenements($client), 'Un seul événement doit être journalisé.');
        self::assertCount(0, $this->fileAsync($client)->getSent(), 'Aucun second remerciement.');
    }

    public function testAnUnpaidSessionLeavesTheDonationPending(): void
    {
        $client = static::createClient();
        $this->purge($client);
        $this->donationEnAttente($client, 'cs_test_impaye');

        $session = str_replace('"payment_status":"paid"', '"payment_status":"unpaid"', $this->sessionPayee('cs_test_impaye'));
        $charge = $this->chargeUtile('evt_impaye', 'checkout.session.completed', $session);
        $this->envoyer($client, $charge, $this->signer($charge));

        self::assertResponseIsSuccessful();
        self::assertSame('Ignored', (string) $client->getResponse()->getContent());
        self::assertSame(DonationStatus::Pending, $this->don($client, 'cs_test_impaye')->getStatus());
        self::assertCount(0, $this->fileAsync($client)->getSent());
    }

    public function testAnUnknownSessionIsIgnoredWithoutError(): void
    {
        $client = static::createClient();
        $this->purge($client);

        $charge = $this->chargeUtile('evt_inconnu', 'checkout.session.completed', $this->sessionPayee('cs_test_jamais_vue'));
        $this->envoyer($client, $charge, $this->signer($charge));

        self::assertResponseIsSuccessful();
        self::assertSame('Ignored', (string) $client->getResponse()->getContent());
    }

    public function testARefundFlipsTheDonation(): void
    {
        $client = static::createClient();
        $this->purge($client);
        $this->donationEnAttente($client, 'cs_test_rembourse');

        $charge = $this->chargeUtile('evt_paye', 'checkout.session.completed', $this->sessionPayee('cs_test_rembourse'));
        $this->envoyer($client, $charge, $this->signer($charge));
        self::assertSame(DonationStatus::Paid, $this->don($client, 'cs_test_rembourse')->getStatus());

        $remboursement = $this->chargeUtile('evt_remb', 'charge.refunded', '{"id":"ch_test","object":"charge","payment_intent":"pi_test_ok","amount_refunded":2500}');
        $this->envoyer($client, $remboursement, $this->signer($remboursement));

        self::assertResponseIsSuccessful();
        self::assertSame('Processed', (string) $client->getResponse()->getContent());
        self::assertSame(DonationStatus::Refunded, $this->don($client, 'cs_test_rembourse')->getStatus());
    }

    public function testAnUnhandledEventTypeAnswersOk(): void
    {
        $client = static::createClient();
        $this->purge($client);

        $charge = $this->chargeUtile('evt_autre', 'customer.created', '{"id":"cus_x","object":"customer"}');
        $this->envoyer($client, $charge, $this->signer($charge));

        // Répondre 200 évite que Stripe réessaie indéfiniment.
        self::assertResponseIsSuccessful();
        self::assertSame('Ignored', (string) $client->getResponse()->getContent());
    }

    public function testTheWebhookIsNeitherRedirectedNorTaggedForRobots(): void
    {
        $client = static::createClient();
        $this->purge($client);

        $charge = $this->chargeUtile('evt_entetes', 'customer.created', '{"id":"cus_y","object":"customer"}');
        $this->envoyer($client, $charge, $this->signer($charge));

        self::assertResponseStatusCodeSame(200);
        self::assertFalse($client->getResponse()->headers->has('X-Robots-Tag'));
    }

    // ---------------------------------------------------------------- outils

    private function envoyer(KernelBrowser $client, string $charge, string $signature): void
    {
        $client->request('POST', '/stripe/webhook', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $signature,
        ], $charge);
    }

    /**
     * Reproduit le schéma de signature de Stripe : HMAC-SHA256 de
     * « horodatage.corps » avec le secret du endpoint.
     */
    private function signer(string $charge): string
    {
        $ts = time();

        return \sprintf('t=%d,v1=%s', $ts, hash_hmac('sha256', $ts.'.'.$charge, self::SECRET));
    }

    private function chargeUtile(string $eventId, string $type, string $objet): string
    {
        return \sprintf('{"id":"%s","object":"event","api_version":"2024-06-20","type":"%s","data":{"object":%s}}', $eventId, $type, $objet);
    }

    private function sessionPayee(string $sessionId): string
    {
        return \sprintf(
            '{"id":"%s","object":"checkout.session","payment_status":"paid","amount_total":2500,"currency":"eur","payment_intent":"pi_test_ok","customer":"cus_test_ok","customer_details":{"name":"Awa Ndiaye","email":"awa@example.org","address":{"line1":"12 rue des Lilas","postal_code":"75011","city":"Paris","country":"FR"}}}',
            $sessionId,
        );
    }

    private function donationEnAttente(KernelBrowser $client, string $sessionId): void
    {
        $manager = $this->manager($client);
        $manager->persist(new Donation($sessionId, 2500, 'EUR'));
        $manager->flush();
        $manager->clear();
    }

    private function don(KernelBrowser $client, string $sessionId): Donation
    {
        $depot = $client->getContainer()->get(DonationRepository::class);
        self::assertInstanceOf(DonationRepository::class, $depot);
        $this->manager($client)->clear();
        $don = $depot->findOneByStripeSessionId($sessionId);
        self::assertInstanceOf(Donation::class, $don);

        return $don;
    }

    /**
     * @return list<StripeEvent>
     */
    private function evenements(KernelBrowser $client): array
    {
        return array_values($this->manager($client)->getRepository(StripeEvent::class)->findAll());
    }

    private function fileAsync(KernelBrowser $client): InMemoryTransport
    {
        $transport = $client->getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    private function manager(KernelBrowser $client): EntityManagerInterface
    {
        $manager = $client->getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    private function purge(KernelBrowser $client): void
    {
        $manager = $this->manager($client);
        $manager->createQuery('DELETE FROM '.Donation::class.' d')->execute();
        $manager->createQuery('DELETE FROM '.StripeEvent::class.' e')->execute();
    }
}
