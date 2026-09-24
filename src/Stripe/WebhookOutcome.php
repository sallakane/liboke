<?php

declare(strict_types=1);

namespace App\Stripe;

enum WebhookOutcome
{
    case Processed;

    /** Événement déjà traité : Stripe l'a rejoué. */
    case Duplicate;

    /** Type d'événement non géré, ou don introuvable. */
    case Ignored;
}
