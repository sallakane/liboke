<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Stripe\CheckoutSession;
use App\Stripe\CheckoutSessionFactory;
use RuntimeException;

/**
 * Remplace Stripe en test : le tunnel de don doit être parcourable sans
 * joindre le réseau. Enregistre les paramètres reçus pour qu'un test puisse
 * vérifier que le montant transmis est bien celui validé côté serveur.
 */
final class FakeCheckoutSessionFactory implements CheckoutSessionFactory
{
    public ?int $dernierMontant = null;
    public ?string $derniereDevise = null;
    public ?string $derniereUrlSucces = null;
    public bool $echoue = false;

    public function create(int $amountCents, string $currency, string $successUrl, string $cancelUrl): CheckoutSession
    {
        if ($this->echoue) {
            throw new RuntimeException('Stripe indisponible.');
        }

        $this->dernierMontant = $amountCents;
        $this->derniereDevise = $currency;
        $this->derniereUrlSucces = $successUrl;

        // Identifiant aléatoire, et non un compteur d'instance : le client de
        // test redémarre le noyau avant chaque requête, un compteur repartirait
        // donc de zéro et violerait l'unicité de stripe_session_id.
        $id = 'cs_test_'.bin2hex(random_bytes(8));

        return new CheckoutSession($id, 'https://checkout.stripe.test/'.$id);
    }
}
