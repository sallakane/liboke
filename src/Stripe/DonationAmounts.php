<?php

declare(strict_types=1);

namespace App\Stripe;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Montants autorisés pour un don (config/donations.yaml).
 *
 * Point de contrôle unique : aucun montant n'atteint Stripe sans être passé
 * par isAllowed(). Le montant posté par le navigateur n'est qu'une
 * suggestion, jamais une autorité (CLAUDE.md §10, règle 1).
 */
final readonly class DonationAmounts
{
    /**
     * @param list<int> $suggestions
     */
    private function __construct(
        public string $currency,
        public int $minimum,
        public int $maximum,
        public array $suggestions,
    ) {
    }

    public static function fromFile(string $path): self
    {
        if (!is_file($path)) {
            throw new RuntimeException(\sprintf('Configuration des dons introuvable : %s.', $path));
        }

        $raw = Yaml::parseFile($path);

        if (!\is_array($raw)) {
            throw new RuntimeException(\sprintf('%s doit contenir un mapping YAML.', $path));
        }

        $currency = $raw['currency'] ?? 'EUR';
        $minimum = $raw['minimum'] ?? 500;
        $maximum = $raw['maximum'] ?? 500000;
        $suggestions = $raw['suggestions'] ?? [];

        if (!\is_string($currency) || !\is_int($minimum) || !\is_int($maximum) || !\is_array($suggestions)) {
            throw new RuntimeException(\sprintf('Configuration des dons invalide dans %s.', $path));
        }

        if ($minimum < 50 || $maximum <= $minimum) {
            throw new RuntimeException('Bornes de don incohérentes : le minimum doit valoir au moins 50 centimes et rester sous le maximum.');
        }

        $propres = [];
        foreach ($suggestions as $montant) {
            if (\is_int($montant) && $montant >= $minimum && $montant <= $maximum) {
                $propres[] = $montant;
            }
        }

        return new self(strtoupper($currency), $minimum, $maximum, $propres);
    }

    public function isAllowed(int $centimes): bool
    {
        return $centimes >= $this->minimum && $centimes <= $this->maximum;
    }

    /**
     * Montant en euros, pour l'affichage. Jamais utilisé pour un calcul.
     */
    public function toEuros(int $centimes): string
    {
        return number_format($centimes / 100, 0 === $centimes % 100 ? 0 : 2, ',', ' ');
    }
}
