<?php

declare(strict_types=1);

namespace App\Form;

/**
 * Délai minimum de soumission (CLAUDE.md §9).
 *
 * L'horodatage d'affichage du formulaire voyage dans un champ caché, signé
 * par HMAC : un robot ne peut ni l'antidater ni le forger. Approche sans
 * état, contrairement à un stockage en session qui se comporterait mal
 * lorsque plusieurs onglets sont ouverts.
 */
final readonly class SubmissionTimer
{
    private const int DUREE_VALIDITE = 86400;

    public function __construct(
        private string $secret,
        private int $delaiMinimum = 3,
    ) {
    }

    /**
     * @return array{ts: string, signature: string}
     */
    public function issue(): array
    {
        $ts = (string) time();

        return ['ts' => $ts, 'signature' => $this->sign($ts)];
    }

    public function elapsedEnough(?string $ts, ?string $signature): bool
    {
        if (null === $ts || null === $signature || '' === $ts) {
            return false;
        }

        if (!hash_equals($this->sign($ts), $signature)) {
            return false;
        }

        $ecoule = time() - (int) $ts;

        return $ecoule >= $this->delaiMinimum && $ecoule <= self::DUREE_VALIDITE;
    }

    private function sign(string $ts): string
    {
        return hash_hmac('sha256', $ts, $this->secret);
    }
}
