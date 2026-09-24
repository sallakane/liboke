<?php

declare(strict_types=1);

namespace App\Stripe;

use RuntimeException;
use Stripe\StripeClient;

/**
 * Implémentation réelle : Stripe Checkout, page hébergée.
 *
 * Aucune donnée bancaire ne transite par nos serveurs (CLAUDE.md §2).
 * Stripe collecte aussi l'identité et l'adresse du donateur, ce qui évite de
 * les redemander dans notre formulaire et prépare le reçu fiscal.
 */
final readonly class StripeCheckoutSessionFactory implements CheckoutSessionFactory
{
    public function __construct(
        private StripeClient $stripe,
        private string $libelleProduit,
    ) {
    }

    public function create(int $amountCents, string $currency, string $successUrl, string $cancelUrl): CheckoutSession
    {
        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'payment',
            'locale' => 'fr',
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'billing_address_collection' => 'required',
            'submit_type' => 'donate',
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'currency' => strtolower($currency),
                    'unit_amount' => $amountCents,
                    'product_data' => ['name' => $this->libelleProduit],
                ],
            ]],
        ]);

        $url = $session->url;

        if (!\is_string($url) || '' === $url) {
            throw new RuntimeException('Stripe n\'a pas renvoyé d\'URL de paiement.');
        }

        return new CheckoutSession($session->id, $url);
    }
}
