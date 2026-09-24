<?php

declare(strict_types=1);

namespace App\Stripe;

/**
 * Crée une session Stripe Checkout.
 *
 * Interface plutôt qu'appel direct au SDK : les tests fonctionnels doivent
 * pouvoir parcourir le tunnel de don sans joindre Stripe.
 */
interface CheckoutSessionFactory
{
    /**
     * @param int $amountCents montant déjà validé côté serveur
     */
    public function create(int $amountCents, string $currency, string $successUrl, string $cancelUrl): CheckoutSession;
}
