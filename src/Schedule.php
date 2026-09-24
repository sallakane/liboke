<?php

declare(strict_types=1);

namespace App;

use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Tâches planifiées du site.
 *
 * Exécutées par le worker Messenger qui consomme le transport
 * `scheduler_default` (`make worker`). Pas de crontab système : la
 * planification est versionnée avec le code et testée.
 */
#[AsSchedule]
final readonly class Schedule implements ScheduleProviderInterface
{
    /**
     * Chaque nuit à 3 h 17 : heure creuse, hors minute ronde. Un intervalle
     * suffit ici ; une expression cron aurait demandé une dépendance de plus.
     */
    public const string PURGE_CONTACT_HEURE = '03:17';

    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            // Une purge manquée pendant un arrêt du worker est rattrapée au
            // redémarrage, une seule fois.
            ->stateful($this->cache)
            ->processOnlyLastMissedRun(true)

            // Purge RGPD des messages de contact de plus de douze mois
            // (CLAUDE.md §11).
            ->add(RecurringMessage::every(
                '1 day',
                new RunCommandMessage('app:purge-contact-messages'),
                from: self::PURGE_CONTACT_HEURE,
            ));
    }
}
