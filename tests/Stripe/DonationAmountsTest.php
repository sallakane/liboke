<?php

declare(strict_types=1);

namespace App\Tests\Stripe;

use App\Stripe\DonationAmounts;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DonationAmountsTest extends TestCase
{
    public function testTheProjectConfigurationIsCoherent(): void
    {
        $montants = $this->charger();

        self::assertSame('EUR', $montants->currency);
        self::assertGreaterThanOrEqual(50, $montants->minimum, 'Le plancher Stripe est de 0,50 €.');
        self::assertGreaterThan($montants->minimum, $montants->maximum);
        self::assertNotSame([], $montants->suggestions);
    }

    public function testBoundsAreEnforced(): void
    {
        $montants = $this->charger();

        self::assertFalse($montants->isAllowed($montants->minimum - 1));
        self::assertTrue($montants->isAllowed($montants->minimum));
        self::assertTrue($montants->isAllowed($montants->maximum));
        self::assertFalse($montants->isAllowed($montants->maximum + 1));
        self::assertFalse($montants->isAllowed(0));
        self::assertFalse($montants->isAllowed(-1000));
    }

    public function testEverySuggestionIsItselfAllowed(): void
    {
        $montants = $this->charger();

        foreach ($montants->suggestions as $centimes) {
            self::assertTrue($montants->isAllowed($centimes), \sprintf('La suggestion %d doit être acceptée.', $centimes));
        }
    }

    public function testAmountsAreFormattedInFrench(): void
    {
        $montants = $this->charger();

        self::assertSame('10', $montants->toEuros(1000));
        self::assertSame('12,50', $montants->toEuros(1250));
        self::assertSame('5 000', $montants->toEuros(500000));
    }

    public function testIncoherentBoundsAreRefused(): void
    {
        $fichier = tempnam(sys_get_temp_dir(), 'dons');
        self::assertIsString($fichier);
        file_put_contents($fichier, "currency: EUR\nminimum: 10\nmaximum: 5\nsuggestions: []\n");

        $this->expectException(RuntimeException::class);

        try {
            DonationAmounts::fromFile($fichier);
        } finally {
            unlink($fichier);
        }
    }

    private function charger(): DonationAmounts
    {
        return DonationAmounts::fromFile(\dirname(__DIR__, 2).'/config/donations.yaml');
    }
}
