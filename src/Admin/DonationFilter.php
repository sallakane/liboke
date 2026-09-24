<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\DonationStatus;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * Filtres de la liste des dons : période de création et statut.
 *
 * Construit depuis la chaîne de requête. Une valeur invalide est ignorée
 * plutôt que de provoquer une erreur : ce n'est qu'un filtre d'affichage.
 */
final readonly class DonationFilter
{
    private const string FORMAT = 'Y-m-d';

    public function __construct(
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
        public ?DonationStatus $status = null,
    ) {
    }

    /**
     * @param InputBag<string> $query
     */
    public static function fromQuery(InputBag $query): self
    {
        return new self(
            self::date($query->getString('du')),
            self::date($query->getString('au')),
            DonationStatus::tryFrom($query->getString('statut')),
        );
    }

    /**
     * Borne haute exclusive : le lendemain de la date « au », pour que le
     * dernier jour soit inclus en entier.
     */
    public function toExclusive(): ?DateTimeImmutable
    {
        return $this->to?->modify('+1 day');
    }

    /**
     * Paramètres à reporter sur les liens (pagination, export CSV).
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'du' => $this->from?->format(self::FORMAT),
            'au' => $this->to?->format(self::FORMAT),
            'statut' => $this->status?->value,
        ], static fn (?string $valeur): bool => null !== $valeur);
    }

    private static function date(string $valeur): ?DateTimeImmutable
    {
        // « ! » remet l'heure à minuit : sans lui, l'heure courante s'invite
        // dans la borne et décale le filtre.
        $date = DateTimeImmutable::createFromFormat('!'.self::FORMAT, $valeur);

        // createFromFormat accepte « 2026-02-31 » en le reportant au 3 mars :
        // on n'accepte que les dates qui se relisent à l'identique.
        if (false === $date || $date->format(self::FORMAT) !== $valeur) {
            return null;
        }

        return $date;
    }
}
