<?php

declare(strict_types=1);

namespace App\Stripe;

/**
 * Session Stripe Checkout intégrée à la page (ui_mode `embedded_page`).
 *
 * Le secret client n'est pas une clé d'API : il ne permet que d'afficher le
 * formulaire de paiement de cette session-là. Il est fait pour être transmis
 * au navigateur, jamais journalisé ni stocké.
 */
final readonly class CheckoutSession
{
    public function __construct(
        public string $id,
        public string $clientSecret,
    ) {
    }
}
