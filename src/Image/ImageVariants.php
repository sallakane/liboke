<?php

declare(strict_types=1);

namespace App\Image;

use InvalidArgumentException;
use Symfony\Component\Asset\Packages;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Variantes responsives des images du site (CLAUDE.md §8).
 *
 * `make images` (App\Command\BuildImagesCommand) produit, pour chaque image
 * de assets/images, une version WebP et une version dans le format d'origine
 * à plusieurs largeurs, rangées dans assets/images/variantes/ avec un
 * manifeste. Ce service lit le manifeste et écrit le <picture> complet :
 * srcset, sizes, width/height (pas de décalage de mise en page), chargement
 * différé sauf pour l'image principale de la page.
 *
 * Les variantes sont versionnées : la production n'a pas besoin de GD.
 *
 * @phpstan-type Entree array{largeur: int, hauteur: int, empreinte: string, variantes: array<string, array<int, string>>}
 */
final class ImageVariants
{
    /** Largeurs produites, bornées par la largeur de l'image source. */
    public const array LARGEURS = [480, 800, 1200, 1600];

    /** Largeur visée pour le `src` de repli des navigateurs sans srcset. */
    private const int LARGEUR_REPLI = 800;

    /** @var array<string, Entree>|null */
    private ?array $manifeste = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%/assets/images')]
        private readonly string $dossier,
        private readonly Packages $packages,
    ) {
    }

    public function dossierSources(): string
    {
        return $this->dossier;
    }

    public function dossierVariantes(): string
    {
        return $this->dossier.'/variantes';
    }

    public function cheminManifeste(): string
    {
        return $this->dossierVariantes().'/manifest.json';
    }

    /**
     * @return array<string, Entree> indexé par chemin relatif à assets/images
     */
    public function manifeste(): array
    {
        if (null !== $this->manifeste) {
            return $this->manifeste;
        }

        $contenu = is_file($this->cheminManifeste()) ? file_get_contents($this->cheminManifeste()) : false;

        /** @var array<string, Entree> $donnees */
        $donnees = false === $contenu ? [] : json_decode($contenu, true, flags: \JSON_THROW_ON_ERROR);

        return $this->manifeste = $donnees;
    }

    public function has(string $source): bool
    {
        return isset($this->manifeste()[$source]);
    }

    /**
     * @param string $source      chemin relatif à assets/images, ex. « accueil.jpg »
     * @param string $alt         texte alternatif ; chaîne vide pour une image décorative
     * @param string $sizes       largeur d'affichage selon l'écran, ex. « (min-width: 900px) 50vw, 100vw »
     * @param bool   $prioritaire image principale de la page (LCP) : chargée tout de suite
     */
    public function picture(
        string $source,
        string $alt,
        string $sizes = '100vw',
        bool $prioritaire = false,
        ?string $classe = null,
    ): string {
        $entree = $this->manifeste()[$source] ?? throw new InvalidArgumentException(\sprintf('Image « %s » absente de assets/images/variantes/manifest.json : lancer « make images ».', $source));

        $formatOrigine = array_key_first(array_diff_key($entree['variantes'], ['webp' => true])) ?? 'webp';
        $origine = $entree['variantes'][$formatOrigine];

        $html = '<picture>';

        if ('webp' !== $formatOrigine && isset($entree['variantes']['webp'])) {
            $html .= \sprintf(
                '<source type="image/webp" srcset="%s" sizes="%s">',
                $this->srcset($entree['variantes']['webp']),
                self::e($sizes),
            );
        }

        $attributs = [
            'src' => $this->url($this->repli($origine)),
            'srcset' => $this->srcset($origine),
            'sizes' => $sizes,
            'width' => (string) $entree['largeur'],
            'height' => (string) $entree['hauteur'],
            'alt' => $alt,
            'class' => $classe,
            'decoding' => 'async',
        ];
        $attributs += $prioritaire ? ['fetchpriority' => 'high'] : ['loading' => 'lazy'];

        $html .= '<img';

        foreach ($attributs as $nom => $valeur) {
            if (null !== $valeur) {
                $html .= \sprintf(' %s="%s"', $nom, 'srcset' === $nom || 'src' === $nom ? $valeur : self::e($valeur));
            }
        }

        return $html.'></picture>';
    }

    /**
     * @param array<int, string> $variantes largeur => chemin d'asset
     */
    private function srcset(array $variantes): string
    {
        $parties = [];

        foreach ($variantes as $largeur => $chemin) {
            $parties[] = $this->url($chemin).' '.$largeur.'w';
        }

        return implode(', ', $parties);
    }

    /**
     * @param array<int, string> $variantes
     */
    private function repli(array $variantes): string
    {
        $choix = null;

        foreach ($variantes as $largeur => $chemin) {
            if (null === $choix || $largeur <= self::LARGEUR_REPLI) {
                $choix = $chemin;
            }
        }

        return $choix ?? throw new InvalidArgumentException('Aucune variante.');
    }

    private function url(string $chemin): string
    {
        return self::e($this->packages->getUrl($chemin));
    }

    private static function e(string $valeur): string
    {
        return htmlspecialchars($valeur, \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }
}
