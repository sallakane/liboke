<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Avis de don adressé à l'association. Message distinct du remerciement :
 * si l'un des deux envois échoue et est rejoué, l'autre n'est pas renvoyé.
 */
final readonly class NotifyDonationReceived
{
    public function __construct(public string $donationId)
    {
    }
}
