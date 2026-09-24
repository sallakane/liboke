<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Form\SubmissionTimer;
use PHPUnit\Framework\TestCase;

final class SubmissionTimerTest extends TestCase
{
    private const string SECRET = 'un-secret-de-test';

    public function testAFreshTokenIsTooRecent(): void
    {
        $timer = new SubmissionTimer(self::SECRET);
        ['ts' => $ts, 'signature' => $signature] = $timer->issue();

        self::assertFalse($timer->elapsedEnough($ts, $signature), 'Zéro seconde écoulée : trop rapide.');
    }

    public function testATokenOlderThanTheMinimumIsAccepted(): void
    {
        $timer = new SubmissionTimer(self::SECRET);
        $ts = (string) (time() - 30);

        self::assertTrue($timer->elapsedEnough($ts, hash_hmac('sha256', $ts, self::SECRET)));
    }

    public function testATamperedTimestampIsRejected(): void
    {
        $timer = new SubmissionTimer(self::SECRET);
        ['signature' => $signature] = $timer->issue();

        // Le robot antidate l'horodatage mais ne peut pas le resigner.
        self::assertFalse($timer->elapsedEnough((string) (time() - 3600), $signature));
    }

    public function testASignatureFromAnotherSecretIsRejected(): void
    {
        $timer = new SubmissionTimer(self::SECRET);
        $ts = (string) (time() - 30);

        self::assertFalse($timer->elapsedEnough($ts, hash_hmac('sha256', $ts, 'autre-secret')));
    }

    public function testAnExpiredTokenIsRejected(): void
    {
        $timer = new SubmissionTimer(self::SECRET);
        $ts = (string) (time() - 90000);

        self::assertFalse($timer->elapsedEnough($ts, hash_hmac('sha256', $ts, self::SECRET)));
    }

    public function testMissingValuesAreRejected(): void
    {
        $timer = new SubmissionTimer(self::SECRET);

        self::assertFalse($timer->elapsedEnough(null, null));
        self::assertFalse($timer->elapsedEnough('', ''));
    }
}
