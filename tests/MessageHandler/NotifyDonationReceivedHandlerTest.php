<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Donation;
use App\Message\NotifyDonationReceived;
use App\MessageHandler\NotifyDonationReceivedHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;

final class NotifyDonationReceivedHandlerTest extends KernelTestCase
{
    public function testAPaidDonationIsReportedToTheAssociation(): void
    {
        self::bootKernel();
        $don = $this->donPaye('cs_test_avis', 'awa@example.org');

        $this->handler()(new NotifyDonationReceived((string) $don->getId()));

        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('contact@association-liboke.org', $email->getTo()[0]->getAddress());
        self::assertSame('awa@example.org', $email->getReplyTo()[0]->getAddress());
        self::assertStringContainsString('[LIBOKE DON] 25 EUR de Awa Ndiaye', (string) $email->getSubject());
        self::assertStringContainsString('/admin/dons', (string) $email->getTextBody());
    }

    public function testAPendingDonationIsNotReported(): void
    {
        self::bootKernel();
        $manager = $this->manager();
        $don = new Donation('cs_test_avis_attente', 2500, 'EUR');
        $manager->persist($don);
        $manager->flush();

        $this->handler()(new NotifyDonationReceived((string) $don->getId()));

        self::assertQueuedEmailCount(0);
    }

    public function testADonationWithoutEmailIsStillReported(): void
    {
        self::bootKernel();
        $don = $this->donPaye('cs_test_avis_sans_email', null);

        $this->handler()(new NotifyDonationReceived((string) $don->getId()));

        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame([], $email->getReplyTo());
    }

    public function testAnUnknownIdentifierIsHarmless(): void
    {
        self::bootKernel();

        $this->handler()(new NotifyDonationReceived('pas-un-uuid'));
        $this->handler()(new NotifyDonationReceived('0192f000-0000-7000-8000-000000000000'));

        self::assertQueuedEmailCount(0);
    }

    private function donPaye(string $sessionId, ?string $email): Donation
    {
        $manager = $this->manager();
        $manager->createQuery('DELETE FROM '.Donation::class.' d')->execute();

        $don = new Donation($sessionId, 2500, 'EUR');
        $don->markPaid('pi_test', 'cus_test');
        $don->setDonor('Awa Ndiaye', $email);
        $manager->persist($don);
        $manager->flush();

        return $don;
    }

    private function handler(): NotifyDonationReceivedHandler
    {
        $handler = static::getContainer()->get(NotifyDonationReceivedHandler::class);
        self::assertInstanceOf(NotifyDonationReceivedHandler::class, $handler);

        return $handler;
    }

    private function manager(): EntityManagerInterface
    {
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }
}
