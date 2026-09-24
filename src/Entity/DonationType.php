<?php

declare(strict_types=1);

namespace App\Entity;

enum DonationType: string
{
    case OneTime = 'one_time';

    /** Prévu dans le modèle, non implémenté au lancement (CLAUDE.md §10). */
    case Monthly = 'monthly';
}
