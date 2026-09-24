<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Réponses de l'espace d'administration : jamais indexées, jamais mises en
 * cache (CLAUDE.md §10).
 *
 * Le `noindex` est posé même quand le site est indexable, contrairement à
 * RobotsHeaderListener. `no-store` évite qu'une liste de donateurs reste
 * dans le cache d'un navigateur partagé ou d'un proxy.
 *
 * Priorité négative : passe après la gestion de session de Symfony, qui
 * réécrit elle aussi Cache-Control.
 */
#[AsEventListener(event: KernelEvents::RESPONSE, priority: -1024)]
final readonly class AdminResponseListener
{
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !preg_match('#^/admin(?:/|$)#', $event->getRequest()->getPathInfo())) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('X-Robots-Tag', 'noindex, nofollow');
        $headers->set('Cache-Control', 'no-store, private');
    }
}
