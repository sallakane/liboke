<?php

declare(strict_types=1);

namespace App\Stripe;

/**
 * Session de paiement hébergée par Stripe.
 */
final readonly class CheckoutSession
{
    public function __construct(
        public string $id,
        public string $url,
    ) {
    }
}
