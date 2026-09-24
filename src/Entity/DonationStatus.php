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

    /** Libellé affiché dans l'espace d'administration et l'export CSV. */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente',
            self::Paid => 'Payé',
            self::Refunded => 'Remboursé',
            self::Failed => 'Échoué',
        };
    }
}
