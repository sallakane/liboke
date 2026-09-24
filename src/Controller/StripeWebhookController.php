<?php

declare(strict_types=1);

namespace App\Controller;

use App\Stripe\WebhookHandler;
use Psr\Log\LoggerInterface;
use Stripe\Exception\SignatureVerificationException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use UnexpectedValueException;

/**
 * Point d'entrée des webhooks Stripe.
 *
 * Cette route est exclue de la redirection 301 vers l'hôte canonique
 * (CanonicalRedirectListener) : Stripe ne rejoue pas le corps de la requête
 * après une redirection, la vérification de signature échouerait.
 */
final class StripeWebhookController extends AbstractController
{
    public function __construct(
        private readonly WebhookHandler $handler,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('/stripe/webhook', name: 'app_stripe_webhook', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        try {
            $resultat = $this->handler->handle(
                // Corps BRUT : toute normalisation invaliderait la signature.
                $request->getContent(),
                $request->headers->get('Stripe-Signature'),
            );
        } catch (SignatureVerificationException $erreur) {
            $this->logger->warning('Webhook Stripe à signature invalide.', ['message' => $erreur->getMessage()]);

            return new Response('Signature invalide.', Response::HTTP_BAD_REQUEST);
        } catch (UnexpectedValueException $erreur) {
            $this->logger->warning('Webhook Stripe illisible.', ['message' => $erreur->getMessage()]);

            return new Response('Charge utile illisible.', Response::HTTP_BAD_REQUEST);
        }

        // Toute autre exception remonte volontairement en 500 : Stripe
        // réessaiera, et l'idempotence rend le rejeu sans danger.
        return new Response($resultat->name, Response::HTTP_OK);
    }
}
