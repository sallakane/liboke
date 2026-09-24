<?php

declare(strict_types=1);

namespace App\Tests\Admin;

use App\Admin\DonationCsvExporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DonationCsvExporterTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null, string}>
     */
    public static function cellules(): iterable
    {
        yield 'texte ordinaire' => ['Awa Diop', 'Awa Diop'];
        yield 'vide' => [null, ''];
        yield 'formule' => ['=1+1', "'=1+1"];
        yield 'plus' => ['+33 6 00 00 00 00', "'+33 6 00 00 00 00"];
        yield 'moins' => ['-2', "'-2"];
        yield 'arobase' => ['@SUM(A1)', "'@SUM(A1)"];
        yield 'tabulation' => ["\t=1", "'\t=1"];
        yield 'signe au milieu' => ['Jean-Pierre = trésorier', 'Jean-Pierre = trésorier'];
    }

    #[DataProvider('cellules')]
    public function testCellsThatASpreadsheetWouldEvaluateAreDefused(?string $valeur, string $attendu): void
    {
        self::assertSame($attendu, DonationCsvExporter::cell($valeur));
    }
}
