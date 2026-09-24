<?php

declare(strict_types=1);

namespace App\Stripe;

use App\Entity\Donation;
use App\Entity\StripeEvent;
use App\Message\SendDonationThanks;
use App\Repository\DonationRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\Event;
use Stripe\Webhook;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Traitement des webhooks Stripe (CLAUDE.md §10).
 *
 * Deux garanties :
 *   - la signature est vérifiée sur le CORPS BRUT de la requête ;
 *   - l'insertion de l'identifiant d'événement, protégée par un index unique,
 *     sert de verrou d'idempotence ; elle partage la transaction du traitement,
 *     donc soit les deux réussissent, soit aucun des deux.
 */
final readonly class WebhookHandler
{
    public function __construct(
        private EntityManagerInterface $manager,
        private DonationRepository $donations,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
        private string $webhookSecret,
    ) {
    }

    /**
     * @throws \Stripe\Exception\SignatureVerificationException si la signature est invalide
     */
    public function handle(string $payload, ?string $signature): WebhookOutcome
    {
        $event = Webhook::constructEvent($payload, $signature ?? '', $this->webhookSecret);

        try {
            $resultat = $this->manager->wrapInTransaction(
                function () use ($event): WebhookOutcome {
                    // Le verrou d'abord : si Stripe rejoue l'événement, le flush
                    // échoue ici et rien n'est traité deux fois.
                    $this->manager->persist(new StripeEvent($event->id, $event->type));
                    $this->manager->flush();

                    return $this->process($event);
                },
            );
        } catch (UniqueConstraintViolationException) {
            $this->logger->info('Webhook Stripe déjà traité, ignoré.', ['event' => $event->id]);

            return WebhookOutcome::Duplicate;
        }

        return $resultat instanceof WebhookOutcome ? $resultat : WebhookOutcome::Ignored;
    }

    private function process(Event $event): WebhookOutcome
    {
        $objet = $this->objectAsArray($event);

        return match ($event->type) {
            'checkout.session.completed' => $this->onCheckoutCompleted($objet),
            'charge.refunded' => $this->onChargeRefunded($objet),
            default => $this->ignorer($event->type),
        };
    }

    /**
     * @param array<string, mixed> $session
     */
    private function onCheckoutCompleted(array $session): WebhookOutcome
    {
        $sessionId = self::str($session, 'id');

        if (null === $sessionId) {
            return WebhookOutcome::Ignored;
        }

        // Un paiement différé (virement, prélèvement) revient « unpaid » :
        // le don reste en attente jusqu'à checkout.session.async_payment_succeeded,
        // non géré au lancement.
        if ('paid' !== self::str($session, 'payment_status')) {
            $this->logger->info('Session Checkout non réglée, don laissé en attente.', ['session' => $sessionId]);

            return WebhookOutcome::Ignored;
        }

        $donation = $this->donations->findOneByStripeSessionId($sessionId);

        if (null === $donation) {
            $this->logger->warning('Session Checkout sans don correspondant.', ['session' => $sessionId]);

            return WebhookOutcome::Ignored;
        }

        if ($donation->isPaid()) {
            return WebhookOutcome::Duplicate;
        }

        $donation->markPaid(self::str($session, 'payment_intent'), self::str($session, 'customer'));
        $this->remplirDonateur($donation, $session);
        $this->manager->flush();

        // Aucun envoi synchrone : le webhook doit répondre vite (règle 4).
        $this->bus->dispatch(new SendDonationThanks((string) $donation->getId()));

        return WebhookOutcome::Processed;
    }

    /**
     * @param array<string, mixed> $charge
     */
    private function onChargeRefunded(array $charge): WebhookOutcome
    {
        $paymentIntent = self::str($charge, 'payment_intent');

        if (null === $paymentIntent) {
            return WebhookOutcome::Ignored;
        }

        $donation = $this->donations->findOneByStripePaymentIntentId($paymentIntent);

        if (null === $donation) {
            $this->logger->warning('Remboursement sans don correspondant.', ['payment_intent' => $paymentIntent]);

            return WebhookOutcome::Ignored;
        }

        $donation->markRefunded();
        $this->manager->flush();

        return WebhookOutcome::Processed;
    }

    /**
     * @param array<string, mixed> $session
     */
    private function remplirDonateur(Donation $donation, array $session): void
    {
        $details = self::arr($session, 'customer_details');
        $adresse = self::arr($details, 'address');

        $donation->setDonor(
            self::str($details, 'name'),
            self::str($details, 'email'),
            self::str($adresse, 'line1'),
            self::str($adresse, 'postal_code'),
            self::str($adresse, 'city'),
            self::str($adresse, 'country'),
        );
    }

    private function ignorer(string $type): WebhookOutcome
    {
        $this->logger->info('Type d\'événement Stripe non géré.', ['type' => $type]);

        return WebhookOutcome::Ignored;
    }

    /**
     * Les objets Stripe exposent leurs champs par méthodes magiques, ce qui
     * n'est pas analysable statiquement. On repasse par un tableau.
     *
     * @return array<string, mixed>
     */
    private function objectAsArray(Event $event): array
    {
        $objet = $event->data->object ?? null;

        if (null === $objet) {
            return [];
        }

        $tableau = $objet->toArray();
        $propre = [];
        foreach ($tableau as $cle => $valeur) {
            if (\is_string($cle)) {
                $propre[$cle] = $valeur;
            }
        }

        return $propre;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function str(array $data, string $key): ?string
    {
        $valeur = $data[$key] ?? null;

        return \is_string($valeur) && '' !== $valeur ? $valeur : null;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function arr(array $data, string $key): array
    {
        $valeur = $data[$key] ?? null;

        if (!\is_array($valeur)) {
            return [];
        }

        $propre = [];
        foreach ($valeur as $cle => $v) {
            if (\is_string($cle)) {
                $propre[$cle] = $v;
            }
        }

        return $propre;
    }
}
