<?php

declare(strict_types=1);

namespace App\Tests\Content;

use App\Content\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

final class MarkdownRendererTest extends TestCase
{
    public function testFrontMatterIsSeparatedFromTheBody(): void
    {
        $result = (new MarkdownRenderer())->render(<<<'MD'
            ---
            title: "Une page"
            menu: { label: "Une page", weight: 10 }
            ---

            Du **gras** et un [lien](https://example.org).
            MD);

        self::assertSame('Une page', $result['frontMatter']['title']);
        self::assertStringContainsString('<strong>gras</strong>', $result['html']);
        self::assertStringNotContainsString('title:', $result['html']);
    }

    public function testRawHtmlIsStripped(): void
    {
        $result = (new MarkdownRenderer())->render(<<<'MD'
            ---
            title: "Test"
            ---

            Bonjour <script>alert(1)</script> et <div>une div</div>.
            MD);

        self::assertStringNotContainsString('<script>', $result['html']);
        self::assertStringNotContainsString('<div>', $result['html']);
    }

    public function testUnsafeLinksAreNeutralised(): void
    {
        $result = (new MarkdownRenderer())->render(<<<'MD'
            ---
            title: "Test"
            ---

            [Cliquez](javascript:alert(1))
            MD);

        self::assertStringNotContainsString('javascript:', $result['html']);
    }
}
