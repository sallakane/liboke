<?php

declare(strict_types=1);

namespace App\Content;

use App\Image\ImageVariants;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\FrontMatter\Output\RenderedContentWithFrontMatter;
use League\CommonMark\MarkdownConverter;

/**
 * Convertit un fichier Markdown à front matter en HTML sûr.
 *
 * `html_input: strip` retire tout HTML brut du contenu : le contenu est écrit
 * par l'association, pas par des développeurs (CLAUDE.md §6).
 */
final class MarkdownRenderer
{
    private readonly MarkdownConverter $converter;

    /**
     * @param ImageVariants|null $images variantes responsives ; absent dans
     *                                   les tests unitaires du rendu seul
     */
    public function __construct(?ImageVariants $images = null)
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new FrontMatterExtension());

        if (null !== $images) {
            // Priorité supérieure au rendu d'image du cœur CommonMark (0).
            $environment->addRenderer(Image::class, new ResponsiveImageRenderer($images), 10);
        }

        $this->converter = new MarkdownConverter($environment);
    }

    /**
     * @return array{html: string, frontMatter: array<string, mixed>}
     */
    public function render(string $markdown): array
    {
        $rendered = $this->converter->convert($markdown);

        $frontMatter = [];
        if ($rendered instanceof RenderedContentWithFrontMatter) {
            $raw = $rendered->getFrontMatter();
            if (\is_array($raw)) {
                foreach ($raw as $key => $value) {
                    if (\is_string($key)) {
                        $frontMatter[$key] = $value;
                    }
                }
            }
        }

        return ['html' => $rendered->getContent(), 'frontMatter' => $frontMatter];
    }
}
