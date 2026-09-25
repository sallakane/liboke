<?php

declare(strict_types=1);

namespace App\Controller;

use App\Content\PageRepository;
use App\Http\ContentCache;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class HomeController extends AbstractController
{
    public function __construct(private readonly PageRepository $pages)
    {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function index(): Response
    {
        return ContentCache::apply($this->render('home/index.html.twig', ['page' => $this->pages->home()]));
    }
}
