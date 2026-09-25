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
     * @param int    $amountCents montant déjà validé côté serveur
     * @param string $returnUrl   page où Stripe renvoie le donateur après le paiement
     */
    public function create(int $amountCents, string $currency, string $returnUrl): CheckoutSession;
}
