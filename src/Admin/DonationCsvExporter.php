<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\Donation;
use DateTimeImmutable;

/**
 * Export CSV du registre des dons, pour la comptabilité de l'association.
 *
 * Réglé pour une ouverture directe dans un tableur français : séparateur
 * « ; », virgule décimale, BOM UTF-8 (sans lui, Excel lit les accents de
 * travers).
 */
final class DonationCsvExporter
{
    private const string SEPARATEUR = ';';

    private const array ENTETES = [
        'Créé le',
        'Payé le',
        'Statut',
        'Type',
        'Montant',
        'Devise',
        'Nom',
        'E-mail',
        'Adresse',
        'Code postal',
        'Ville',
        'Pays',
        'Entreprise',
        'Session Stripe',
        'Paiement Stripe',
    ];

    /**
     * @param resource           $flux
     * @param iterable<Donation> $dons
     */
    public function write($flux, iterable $dons): void
    {
        fwrite($flux, "\u{FEFF}");
        $this->ligne($flux, self::ENTETES);

        foreach ($dons as $don) {
            $this->ligne($flux, [
                $this->date($don->getCreatedAt()),
                $this->date($don->getPaidAt()),
                $don->getStatus()->label(),
                $don->getType()->value,
                number_format($don->getAmountCents() / 100, 2, ',', ''),
                $don->getCurrency(),
                $don->getDonorName(),
                $don->getDonorEmail(),
                $don->getDonorAddressLine(),
                $don->getDonorPostalCode(),
                $don->getDonorCity(),
                $don->getDonorCountry(),
                $don->isCompany() ? 'oui' : 'non',
                $don->getStripeSessionId(),
                $don->getStripePaymentIntentId(),
            ]);
        }
    }

    /**
     * Neutralise une cellule qu'un tableur interpréterait comme une formule.
     *
     * Le nom et l'adresse du donateur sont saisis chez Stripe par un inconnu :
     * « =HYPERLINK(…) » ouvert dans Excel s'exécuterait chez le trésorier.
     */
    public static function cell(?string $valeur): string
    {
        if (null === $valeur || '' === $valeur) {
            return '';
        }

        return \in_array($valeur[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$valeur : $valeur;
    }

    /**
     * @param resource          $flux
     * @param list<string|null> $valeurs
     */
    private function ligne($flux, array $valeurs): void
    {
        fputcsv($flux, array_map(self::cell(...), $valeurs), self::SEPARATEUR, '"', '');
    }

    private function date(?DateTimeImmutable $date): string
    {
        return $date?->format('Y-m-d H:i:s') ?? '';
    }
}
