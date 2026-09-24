<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Seo\CanonicalUrl;
use App\Seo\RedirectMap;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Normalise l'URL d'entrée en 301 (CLAUDE.md §7) :
 * anciennes URLs Drupal, slash final, puis hôte canonique.
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 512)]
final readonly class CanonicalRedirectListener
{
    /**
     * Chemins jamais redirigés.
     *
     * `/stripe/` est vital : Stripe ne rejoue pas le corps de la requête
     * après un 301, la vérification de signature du webhook échouerait
     * (CLAUDE.md §7 et §10). `/_` couvre le profiler et les routes internes.
     */
    private const array PREFIXES_EXCLUS = ['/stripe/', '/_'];

    public function __construct(
        private CanonicalUrl $canonical,
        private RedirectMap $redirects,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $chemin = $request->getPathInfo();

        foreach (self::PREFIXES_EXCLUS as $prefixe) {
            if (str_starts_with($chemin, $prefixe)) {
                return;
            }
        }

        $cible = $this->redirects->resolve($chemin);

        if (null === $cible && '/' !== $chemin && str_ends_with($chemin, '/')) {
            $cible = rtrim($chemin, '/');
        }

        $base = $this->canonical->schemeAndHost();
        $horsHoteCanonique = $request->getSchemeAndHttpHost() !== $base;

        if (null === $cible && !$horsHoteCanonique) {
            return;
        }

        $cible ??= $chemin;
        $requete = $request->getQueryString();

        $event->setResponse(new RedirectResponse(
            $base.$cible.(null !== $requete ? '?'.$requete : ''),
            RedirectResponse::HTTP_MOVED_PERMANENTLY,
        ));
    }
}
