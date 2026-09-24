<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\ContactMessage;
use App\Repository\ContactMessageRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class PurgeContactMessagesCommandTest extends KernelTestCase
{
    public function testMessagesOlderThanTwelveMonthsAreDeleted(): void
    {
        $tester = $this->preparer();

        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        self::assertStringContainsString('1 message(s)', $tester->getDisplay());

        $restants = $this->depot()->findAll();
        self::assertCount(1, $restants, 'Seul le message récent doit subsister.');
        self::assertSame('Message récent', $restants[0]->getSubject());
    }

    public function testDryRunCountsWithoutDeleting(): void
    {
        $tester = $this->preparer();

        $tester->execute(['--dry-run' => true]);
        $tester->assertCommandIsSuccessful();

        self::assertStringContainsString('seraient supprimés', $tester->getDisplay());
        self::assertCount(2, $this->depot()->findAll(), 'Le mode simulation ne doit rien supprimer.');
    }

    private function preparer(): CommandTester
    {
        $noyau = self::bootKernel();
        $manager = $this->manager();
        $manager->createQuery('DELETE FROM '.ContactMessage::class.' m')->execute();

        $manager->persist(new ContactMessage(
            'Ancien contact',
            'ancien@example.org',
            'Message ancien',
            'Message de plus de douze mois, à purger.',
            true,
            new DateTimeImmutable('-13 months'),
        ));
        $manager->persist(new ContactMessage(
            'Contact récent',
            'recent@example.org',
            'Message récent',
            'Message de moins de douze mois, à conserver.',
            true,
            new DateTimeImmutable('-1 month'),
        ));
        $manager->flush();
        $manager->clear();

        $application = new Application($noyau);

        return new CommandTester($application->find('app:purge-contact-messages'));
    }

    private function manager(): EntityManagerInterface
    {
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    private function depot(): ContactMessageRepository
    {
        $depot = static::getContainer()->get(ContactMessageRepository::class);
        self::assertInstanceOf(ContactMessageRepository::class, $depot);

        return $depot;
    }
}
