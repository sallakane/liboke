<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Remerciement d'un don, envoyé hors du cycle de la requête : le webhook
 * Stripe doit répondre vite (CLAUDE.md §10, règle 4).
 */
final readonly class SendDonationThanks
{
    public function __construct(public string $donationId)
    {
    }
}
