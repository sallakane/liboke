<?php

declare(strict_types=1);

namespace App\Entity;

enum DonationStatus: string
{
    /** Session Checkout créée, paiement non confirmé. */
    case Pending = 'pending';

    /** Confirmé par le webhook — seule source de vérité (CLAUDE.md §10). */
    case Paid = 'paid';

    case Refunded = 'refunded';

    case Failed = 'failed';
}
