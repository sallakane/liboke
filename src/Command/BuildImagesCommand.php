<?php

declare(strict_types=1);

namespace App\Command;

use App\Image\ImageVariants;
use GdImage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Génère les variantes responsives de assets/images (voir ImageVariants).
 *
 * Idempotente : une image dont l'empreinte n'a pas changé n'est pas
 * recalculée. Les variantes d'une image supprimée sont effacées.
 *
 * Nécessite GD, installé dans l'image Docker de développement seulement.
 */
#[AsCommand(
    name: 'app:images',
    description: 'Génère les variantes WebP et responsives des images de assets/images.',
)]
final class BuildImagesCommand extends Command
{
    private const array EXTENSIONS = ['jpg', 'jpeg', 'png'];

    private const int QUALITE_JPEG = 82;

    private const int QUALITE_WEBP = 75;

    public function __construct(
        private readonly ImageVariants $images,
        private readonly Filesystem $fichiers = new Filesystem(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Régénère toutes les variantes, même inchangées.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!\extension_loaded('gd')) {
            $io->error('L\'extension GD est absente : lancer « make images » (conteneur de développement).');

            return Command::FAILURE;
        }

        $ancien = $this->images->manifeste();
        $manifeste = [];
        $generees = 0;

        foreach ($this->sources() as $relatif => $chemin) {
            $empreinte = sha1_file($chemin) ?: throw new RuntimeException(\sprintf('Lecture impossible : %s', $chemin));
            $precedent = $ancien[$relatif] ?? null;

            if (!$input->getOption('force') && null !== $precedent && $precedent['empreinte'] === $empreinte && $this->variantesPresentes($precedent['variantes'])) {
                $manifeste[$relatif] = $precedent;

                continue;
            }

            $manifeste[$relatif] = $this->generer($relatif, $chemin, $empreinte);
            ++$generees;
            $io->writeln(\sprintf('  <info>✓</info> %s', $relatif));
        }

        ksort($manifeste);
        $supprimees = $this->nettoyer($manifeste);

        $this->fichiers->dumpFile(
            $this->images->cheminManifeste(),
            json_encode($manifeste, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n",
        );

        $io->success(\sprintf(
            '%d image(s) traitée(s), %d inchangée(s), %d variante(s) obsolète(s) supprimée(s).',
            $generees,
            \count($manifeste) - $generees,
            $supprimees,
        ));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, string> chemin relatif à assets/images => chemin absolu
     */
    private function sources(): array
    {
        $dossier = $this->images->dossierSources();
        $sources = [];

        /** @var SplFileInfo $fichier */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dossier, RecursiveDirectoryIterator::SKIP_DOTS)) as $fichier) {
            $relatif = substr($fichier->getPathname(), \strlen($dossier) + 1);

            if (str_starts_with($relatif, 'variantes/') || !\in_array(strtolower($fichier->getExtension()), self::EXTENSIONS, true)) {
                continue;
            }

            $sources[$relatif] = $fichier->getPathname();
        }

        ksort($sources);

        return $sources;
    }

    /**
     * @return array{largeur: int, hauteur: int, empreinte: string, variantes: array<string, array<int, string>>}
     */
    private function generer(string $relatif, string $chemin, string $empreinte): array
    {
        $format = 'png' === strtolower(pathinfo($chemin, \PATHINFO_EXTENSION)) ? 'png' : 'jpg';
        $image = ('png' === $format ? imagecreatefrompng($chemin) : imagecreatefromjpeg($chemin))
            ?: throw new RuntimeException(\sprintf('Image illisible : %s', $chemin));

        $largeur = imagesx($image);
        $hauteur = imagesy($image);

        $largeurs = array_values(array_filter(ImageVariants::LARGEURS, static fn (int $l): bool => $l < $largeur));
        $largeurs[] = min($largeur, max(ImageVariants::LARGEURS));

        $base = substr($relatif, 0, -\strlen(pathinfo($relatif, \PATHINFO_EXTENSION)) - 1);
        $variantes = ['webp' => [], $format => []];

        foreach (array_unique($largeurs) as $cible) {
            $redim = $this->redimensionner($image, $cible, (int) round($hauteur * $cible / $largeur));

            foreach ([$format, 'webp'] as $sortie) {
                $nom = \sprintf('%s-%d.%s', $base, $cible, $sortie);
                $destination = $this->images->dossierVariantes().'/'.$nom;
                $this->fichiers->mkdir(\dirname($destination));
                $this->ecrire($redim, $destination, $sortie);
                $variantes[$sortie][$cible] = 'images/variantes/'.$nom;
            }
        }

        return ['largeur' => $largeur, 'hauteur' => $hauteur, 'empreinte' => $empreinte, 'variantes' => $variantes];
    }

    private function redimensionner(GdImage $image, int $largeur, int $hauteur): GdImage
    {
        if (imagesx($image) === $largeur) {
            return $image;
        }

        $cible = imagecreatetruecolor(max(1, $largeur), max(1, $hauteur)) ?: throw new RuntimeException('Mémoire insuffisante.');
        // Conserve la transparence des PNG.
        imagealphablending($cible, false);
        imagesavealpha($cible, true);
        imagecopyresampled($cible, $image, 0, 0, 0, 0, $largeur, $hauteur, imagesx($image), imagesy($image));

        return $cible;
    }

    private function ecrire(GdImage $image, string $destination, string $format): void
    {
        imagesavealpha($image, true);

        $ok = match ($format) {
            'webp' => imagewebp($image, $destination, self::QUALITE_WEBP),
            'png' => imagepng($image, $destination, 9),
            default => imagejpeg($image, $destination, self::QUALITE_JPEG),
        };

        if (!$ok) {
            throw new RuntimeException(\sprintf('Écriture impossible : %s', $destination));
        }
    }

    /**
     * @param array<string, array<int, string>> $variantes
     */
    private function variantesPresentes(array $variantes): bool
    {
        foreach ($variantes as $parFormat) {
            foreach ($parFormat as $chemin) {
                if (!is_file(\dirname($this->images->dossierSources()).'/'.$chemin)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Supprime les fichiers de variantes que le manifeste ne référence plus.
     *
     * @param array<string, array{largeur: int, hauteur: int, empreinte: string, variantes: array<string, array<int, string>>}> $manifeste
     */
    private function nettoyer(array $manifeste): int
    {
        $attendus = [];

        foreach ($manifeste as $entree) {
            foreach ($entree['variantes'] as $parFormat) {
                foreach ($parFormat as $chemin) {
                    $attendus[\dirname($this->images->dossierSources()).'/'.$chemin] = true;
                }
            }
        }

        $supprimees = 0;
        $dossier = $this->images->dossierVariantes();

        if (!is_dir($dossier)) {
            return 0;
        }

        /** @var SplFileInfo $fichier */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dossier, RecursiveDirectoryIterator::SKIP_DOTS)) as $fichier) {
            if ('manifest.json' !== $fichier->getFilename() && !isset($attendus[$fichier->getPathname()])) {
                $this->fichiers->remove($fichier->getPathname());
                ++$supprimees;
            }
        }

        return $supprimees;
    }
}
