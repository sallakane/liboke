<?php

declare(strict_types=1);

namespace App\Twig;

use App\Image\ImageVariants;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `image('accueil.jpg', 'Texte alternatif', {sizes: '…', prioritaire: true})`
 * produit un <picture> responsive complet (voir ImageVariants).
 */
final class ImageExtension extends AbstractExtension
{
    public function __construct(private readonly ImageVariants $images)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('image', $this->image(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * @param array{sizes?: string, prioritaire?: bool, classe?: string} $options
     */
    public function image(string $source, string $alt, array $options = []): string
    {
        return $this->images->picture(
            $source,
            $alt,
            $options['sizes'] ?? '100vw',
            $options['prioritaire'] ?? false,
            $options['classe'] ?? null,
        );
    }
}
