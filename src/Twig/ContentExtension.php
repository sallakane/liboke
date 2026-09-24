<?php

declare(strict_types=1);

namespace App\Twig;

use App\Content\Page;
use App\Content\PageRepository;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ContentExtension extends AbstractExtension
{
    public function __construct(private readonly PageRepository $pages)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('menu_principal', $this->menu(...)),
        ];
    }

    /**
     * @return list<Page>
     */
    public function menu(): array
    {
        return $this->pages->menu();
    }
}
