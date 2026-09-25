<?php

declare(strict_types=1);

namespace App\Tests\Image;

use App\Image\ImageVariants;
use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ImageVariantsTest extends KernelTestCase
{
    /**
     * Garde-fou pour l'arrivée du contenu : une photo ajoutée ou remplacée
     * dans assets/images sans relancer « make images » fait échouer ce test.
     */
    public function testEveryImageHasUpToDateVariants(): void
    {
        $images = $this->images();
        $manifeste = $images->manifeste();
        $racineAssets = \dirname($images->dossierSources());

        $sources = [];
        $iterateur = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($images->dossierSources(), FilesystemIterator::SKIP_DOTS));

        foreach ($iterateur as $fichier) {
            \assert($fichier instanceof SplFileInfo);
            $relatif = substr($fichier->getPathname(), \strlen($images->dossierSources()) + 1);

            if (!str_starts_with($relatif, 'variantes/') && \in_array(strtolower($fichier->getExtension()), ['jpg', 'jpeg', 'png'], true)) {
                $sources[$relatif] = $fichier->getPathname();
            }
        }

        self::assertNotEmpty($sources);
        self::assertSame([], array_values(array_diff(array_keys($manifeste), array_keys($sources))), 'Le manifeste référence des images supprimées : lancer « make images ».');

        foreach ($sources as $relatif => $chemin) {
            self::assertArrayHasKey($relatif, $manifeste, \sprintf('« %s » n\'a pas de variantes : lancer « make images ».', $relatif));
            self::assertSame(sha1_file($chemin), $manifeste[$relatif]['empreinte'], \sprintf('« %s » a changé depuis la génération : lancer « make images ».', $relatif));

            foreach ($manifeste[$relatif]['variantes'] as $parFormat) {
                foreach ($parFormat as $variante) {
                    self::assertFileExists($racineAssets.'/'.$variante);
                }
            }
        }
    }

    public function testAPictureOffersWebpAndSizesWithIntrinsicDimensions(): void
    {
        $html = $this->images()->picture('accueil.jpg', 'Élèves en classe', '(min-width: 900px) 70vw, 100vw');

        self::assertStringStartsWith('<picture><source type="image/webp" srcset="', $html);
        self::assertMatchesRegularExpression('#/assets/images/variantes/accueil-480-[\w-]+\.webp 480w#', $html);
        self::assertMatchesRegularExpression('#src="/assets/images/variantes/accueil-800-[\w-]+\.jpg"#', $html, 'Repli sur la variante 800 px.');
        self::assertStringContainsString('width="1600" height="1080"', $html);
        self::assertStringContainsString('alt="Élèves en classe"', $html);
        self::assertStringContainsString('sizes="(min-width: 900px) 70vw, 100vw"', $html);
        self::assertStringContainsString('loading="lazy"', $html);
        self::assertStringNotContainsString('fetchpriority', $html);
    }

    public function testThePrincipalImageIsFetchedEagerlyWithHighPriority(): void
    {
        $html = $this->images()->picture('accueil.jpg', '', prioritaire: true);

        self::assertStringContainsString('fetchpriority="high"', $html);
        self::assertStringNotContainsString('loading=', $html);
        self::assertStringContainsString('alt=""', $html, 'Une image décorative garde un alt vide, jamais absent.');
    }

    public function testTheAlternativeTextIsEscaped(): void
    {
        $html = $this->images()->picture('accueil.jpg', '"><script>alert(1)</script>');

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringContainsString('alt="&quot;&gt;&lt;script&gt;', $html);
    }

    public function testAnImageWithoutVariantsIsAnExplicitError(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('make images');

        $this->images()->picture('inexistante.jpg', '');
    }

    private function images(): ImageVariants
    {
        self::bootKernel();
        $images = static::getContainer()->get(ImageVariants::class);
        self::assertInstanceOf(ImageVariants::class, $images);

        return $images;
    }
}
