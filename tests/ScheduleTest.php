<?php

declare(strict_types=1);

namespace App\Tests;

use App\Schedule;
use DateTimeImmutable;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Scheduler\Generator\MessageContext;

final class ScheduleTest extends KernelTestCase
{
    /**
     * CLAUDE.md §11 : la purge RGPD des messages de contact est planifiée.
     */
    public function testTheContactPurgeRunsEveryNight(): void
    {
        self::bootKernel();
        $schedule = static::getContainer()->get(Schedule::class);
        self::assertInstanceOf(Schedule::class, $schedule);

        $commandes = [];

        foreach ($schedule->getSchedule()->getRecurringMessages() as $recurrent) {
            foreach ($recurrent->getMessages(new MessageContext(
                'default',
                $recurrent->getId(),
                $recurrent->getTrigger(),
                new DateTimeImmutable(),
            )) as $message) {
                if ($message instanceof RunCommandMessage) {
                    $commandes[$message->input] = (string) $recurrent->getTrigger();
                }
            }
        }

        self::assertArrayHasKey('app:purge-contact-messages', $commandes);
        self::assertSame('every 1 day', $commandes['app:purge-contact-messages']);
    }
}
