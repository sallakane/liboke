<?php

declare(strict_types=1);

namespace App\Form;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Choix du donateur. Le montant retenu est recalculé côté serveur à partir
 * de ces valeurs : rien de ce qui est posté n'est repris tel quel.
 */
final class DonationInput
{
    public const string LIBRE = 'libre';

    #[Assert\NotBlank(message: 'Merci de choisir un montant.')]
    public ?string $preset = null;

    /** Montant libre, en euros. Converti en centimes côté serveur. */
    public ?int $custom = null;
}
