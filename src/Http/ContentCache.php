<?php

declare(strict_types=1);

namespace App\Http;

use Symfony\Component\HttpFoundation\Response;

/**
 * Durées de cache HTTP des pages de contenu (CLAUDE.md §8).
 *
 * Le contenu ne change qu'au déploiement : un cache partagé (proxy, CDN)
 * peut garder la page une heure, le navigateur dix minutes. Ne s'applique
 * qu'aux pages identiques pour tous les visiteurs : jamais aux pages à
 * formulaire (jetons, messages flash), ni à /admin.
 *
 * Appliqué explicitement à la réponse réussie, et non par l'attribut
 * #[Cache] : celui-ci s'appliquerait aussi à la page 404 rendue pour le même
 * contrôleur, et une page publiée plus tard resterait introuvable dans les
 * caches partagés.
 */
final class ContentCache
{
    public const int MAX_AGE = 600;

    public const int S_MAXAGE = 3600;

    public static function apply(Response $response): Response
    {
        return $response
            ->setPublic()
            ->setMaxAge(self::MAX_AGE)
            ->setSharedMaxAge(self::S_MAXAGE);
    }
}
