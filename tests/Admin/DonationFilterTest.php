<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\DonationFilter;
use App\Entity\DonationStatus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\InputBag;

final class DonationFilterTest extends TestCase
{
    public function testValidParametersAreRead(): void
    {
        $filtre = DonationFilter::fromQuery(self::requete(['du' => '2026-01-01', 'au' => '2026-01-31', 'statut' => 'paid']));

        self::assertSame('2026-01-01 00:00:00', $filtre->from?->format('Y-m-d H:i:s'));
        self::assertSame('2026-02-01 00:00:00', $filtre->toExclusive()?->format('Y-m-d H:i:s'), 'Le dernier jour est inclus en entier.');
        self::assertSame(DonationStatus::Paid, $filtre->status);
        self::assertSame(['du' => '2026-01-01', 'au' => '2026-01-31', 'statut' => 'paid'], $filtre->toQuery());
    }

    public function testInvalidParametersAreIgnored(): void
    {
        $filtre = DonationFilter::fromQuery(self::requete(['du' => '2026-02-31', 'au' => 'hier', 'statut' => 'rembourse']));

        self::assertNull($filtre->from, 'Une date impossible ne doit pas glisser au mois suivant.');
        self::assertNull($filtre->to);
        self::assertNull($filtre->status);
        self::assertSame([], $filtre->toQuery());
    }

    /**
     * @param array<string, string> $parametres
     *
     * @return InputBag<string>
     */
    private static function requete(array $parametres): InputBag
    {
        /** @var InputBag<string> $bag */
        $bag = new InputBag($parametres);

        return $bag;
    }
}
