<?php

declare(strict_types=1);

namespace App\Controller;

use App\Content\PageRepository;
use App\Http\ContentCache;
use App\Seo\CanonicalUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class SeoController extends AbstractController
{
    public function __construct(
        private readonly PageRepository $pages,
        private readonly CanonicalUrl $canonical,
        private readonly bool $indexable,
    ) {
    }

    #[Route('/sitemap.xml', name: 'app_sitemap', methods: ['GET'])]
    public function sitemap(): Response
    {
        $response = $this->render('seo/sitemap.xml.twig', [
            'pages' => $this->pages->indexable(),
        ]);

        $response->headers->set('Content-Type', 'application/xml; charset=UTF-8');

        return ContentCache::apply($response);
    }

    #[Route('/robots.txt', name: 'app_robots', methods: ['GET'])]
    public function robots(): Response
    {
        $response = $this->render('seo/robots.txt.twig', [
            'indexable' => $this->indexable,
            'sitemap' => $this->canonical->forPath('/sitemap.xml'),
        ]);

        $response->headers->set('Content-Type', 'text/plain; charset=UTF-8');

        return ContentCache::apply($response);
    }
}
