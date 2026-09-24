<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\SendDonationThanks;
use App\Repository\DonationRepository;
use App\Stripe\DonationAmounts;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Uid\Uuid;

#[AsMessageHandler]
final readonly class SendDonationThanksHandler
{
    public function __construct(
        private DonationRepository $donations,
        private MailerInterface $mailer,
        private DonationAmounts $amounts,
        private LoggerInterface $logger,
        #[Autowire('%env(MAILER_FROM)%')]
        private string $expediteur,
    ) {
    }

    public function __invoke(SendDonationThanks $message): void
    {
        if (!Uuid::isValid($message->donationId)) {
            return;
        }

        $donation = $this->donations->findOneById(Uuid::fromString($message->donationId));

        if (null === $donation || !$donation->isPaid()) {
            $this->logger->warning('Remerciement sans don payé correspondant.', ['don' => $message->donationId]);

            return;
        }

        $destinataire = $donation->getDonorEmail();

        if (null === $destinataire) {
            $this->logger->warning('Don payé sans adresse e-mail : pas de remerciement.', ['don' => $message->donationId]);

            return;
        }

        $this->mailer->send(
            (new TemplatedEmail())
                ->from(new Address($this->expediteur, 'Association LIBOKÉ'))
                ->to(new Address($destinataire, $donation->getDonorName() ?? ''))
                ->subject('Merci pour votre don')
                ->htmlTemplate('emails/don_merci.html.twig')
                ->textTemplate('emails/don_merci.txt.twig')
                ->context([
                    'donation' => $donation,
                    'montant' => $this->amounts->toEuros($donation->getAmountCents()),
                ])
        );
    }
}
