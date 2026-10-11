<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\NotifyDonationReceived;
use App\Repository\DonationRepository;
use App\Stripe\DonationAmounts;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class NotifyDonationReceivedHandler
{
    public function __construct(
        private DonationRepository $donations,
        private MailerInterface $mailer,
        private DonationAmounts $amounts,
        private UrlGeneratorInterface $urls,
        private LoggerInterface $logger,
        /** @var list<string> mêmes destinataires que le formulaire de contact */
        #[Autowire('%env(csv:CONTACT_TO)%')]
        private array $destinataires,
        #[Autowire('%env(MAILER_FROM)%')]
        private string $expediteur,
        // Le worker n'a pas de requête HTTP : le lien vers l'administration
        // est construit sur l'hôte canonique, pas sur DEFAULT_URI.
        #[Autowire('%app.canonical_url%')]
        private string $canonicalUrl,
    ) {
    }

    public function __invoke(NotifyDonationReceived $message): void
    {
        if (!Uuid::isValid($message->donationId)) {
            return;
        }

        $donation = $this->donations->findOneById(Uuid::fromString($message->donationId));

        if (null === $donation || !$donation->isPaid()) {
            $this->logger->warning('Avis de don sans don payé correspondant.', ['don' => $message->donationId]);

            return;
        }

        $montant = $this->amounts->toEuros($donation->getAmountCents());
        $email = (new TemplatedEmail())
            ->from(new Address($this->expediteur, 'Site association LIBOKÉ'))
            ->to(...array_map(trim(...), $this->destinataires))
            ->subject(\sprintf('[LIBOKE DON] %s %s de %s', $montant, $donation->getCurrency(), $donation->getDonorName() ?? 'donateur anonyme'))
            ->htmlTemplate('emails/don_recu.html.twig')
            ->textTemplate('emails/don_recu.txt.twig')
            ->context([
                'donation' => $donation,
                'montant' => $montant,
                'lien_admin' => rtrim($this->canonicalUrl, '/').$this->urls->generate('app_admin_donations'),
            ]);

        if (null !== $donation->getDonorEmail()) {
            $email->replyTo(new Address($donation->getDonorEmail(), $donation->getDonorName() ?? ''));
        }

        $this->mailer->send($email);
    }
}
