<?php

declare(strict_types=1);

namespace App\Stripe;

use RuntimeException;
use Stripe\StripeClient;

/**
 * Implémentation réelle : Stripe Checkout intégré à la page (`embedded_page`).
 *
 * Le formulaire de paiement s'affiche dans un iframe servi par Stripe : aucune
 * donnée bancaire ne transite par nos serveurs (CLAUDE.md §2).
 * Formulaire réduit au minimum : e-mail, carte et nom du titulaire. L'adresse
 * postale n'est pas demandée tant que le reçu fiscal n'existe pas (CLAUDE.md
 * §10) ; il suffira alors de repasser `billing_address_collection` à
 * `required`, le webhook sait déjà l'enregistrer.
 */
final readonly class StripeCheckoutSessionFactory implements CheckoutSessionFactory
{
    public function __construct(
        private StripeClient $stripe,
        private string $libelleProduit,
    ) {
    }

    public function create(int $amountCents, string $currency, string $returnUrl): CheckoutSession
    {
        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'payment',
            'ui_mode' => 'embedded_page',
            'locale' => 'fr',
            // Pas de cancel_url en mode intégré : le donateur qui renonce
            // reste simplement sur notre page.
            'return_url' => $returnUrl,
            'billing_address_collection' => 'auto',
            // Carte seule (Apple Pay et Google Pay inclus) : pas de Link, ni
            // de moyens de paiement locaux qui alourdissent le formulaire.
            'payment_method_types' => ['card'],
            // Pas de conversion dans la devise du donateur : euros uniquement.
            'adaptive_pricing' => ['enabled' => false],
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

        $secret = $session->client_secret;

        if (!\is_string($secret) || '' === $secret) {
            throw new RuntimeException('Stripe n\'a pas renvoyé de secret client.');
        }

        return new CheckoutSession($session->id, $secret);
    }
}
