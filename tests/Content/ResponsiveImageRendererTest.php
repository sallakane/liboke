<?php

declare(strict_types=1);

namespace App\Tests\Content;

use App\Content\MarkdownRenderer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ResponsiveImageRendererTest extends KernelTestCase
{
    public function testAnImageOfTheSiteBecomesAResponsivePicture(): void
    {
        $html = $this->rendre('![Une classe *en plein air*](images/accueil.jpg)');

        self::assertStringContainsString('<span class="image-contenu"><picture>', $html);
        self::assertStringContainsString('type="image/webp"', $html);
        self::assertStringContainsString('alt="Une classe en plein air"', $html);
        self::assertStringContainsString('loading="lazy"', $html);
        self::assertStringContainsString('width="1600" height="1080"', $html);
    }

    public function testAnImageWithoutVariantsKeepsTheStandardRendering(): void
    {
        $html = $this->rendre('![Logo partenaire](https://example.org/logo.png)');

        self::assertStringContainsString('<img src="https://example.org/logo.png" alt="Logo partenaire"', $html);
        self::assertStringNotContainsString('<picture>', $html);
    }

    private function rendre(string $markdown): string
    {
        self::bootKernel();
        $renderer = static::getContainer()->get(MarkdownRenderer::class);
        self::assertInstanceOf(MarkdownRenderer::class, $renderer);

        return $renderer->render($markdown)['html'];
    }
}
