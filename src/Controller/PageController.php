<?php

declare(strict_types=1);

namespace App\Controller;

use App\Content\PageRepository;
use App\Http\ContentCache;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class PageController extends AbstractController
{
    public function __construct(private readonly PageRepository $pages)
    {
    }

    /**
     * Priorité négative : cette route est un attrape-tout, elle ne doit jamais
     * masquer une route applicative (dons, webhook, admin…).
     */
    #[Route(
        '/{slug}',
        name: 'app_page',
        requirements: ['slug' => '[a-z0-9]+(?:-[a-z0-9]+)*'],
        methods: ['GET'],
        priority: -10,
    )]
    public function show(string $slug): Response
    {
        $page = $this->pages->find($slug);

        // La page d'accueil n'est servie que sur « / » : l'exposer aussi sous
        // son slug créerait du contenu dupliqué (CLAUDE.md §7).
        if (null === $page || $page->isHome()) {
            throw $this->createNotFoundException(\sprintf('Aucune page pour le slug « %s ».', $slug));
        }

        return ContentCache::apply($this->render(\sprintf('page/%s.html.twig', $page->template), ['page' => $page]));
    }
}
