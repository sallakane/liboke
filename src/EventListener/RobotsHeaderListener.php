<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Ajoute « X-Robots-Tag: noindex » tant que le site n'est pas indexable
 * (développement et recette, CLAUDE.md §7).
 *
 * Le pilotage passe par SITE_INDEXABLE plutôt que par APP_ENV : une recette
 * tourne en environnement « prod » sans devoir être indexée pour autant.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final readonly class RobotsHeaderListener
{
    public function __construct(private bool $indexable)
    {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if ($this->indexable || !$event->isMainRequest()) {
            return;
        }

        // Le webhook Stripe n'est pas une page : on ne touche pas à sa réponse
        // (CLAUDE.md §10, règle 5).
        if (str_starts_with($event->getRequest()->getPathInfo(), '/stripe/')) {
            return;
        }

        $event->getResponse()->headers->set('X-Robots-Tag', 'noindex, nofollow');
    }
}
