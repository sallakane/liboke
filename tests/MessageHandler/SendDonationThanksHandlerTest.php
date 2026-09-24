<?php

declare(strict_types=1);

namespace App\Tests\MessageHandler;

use App\Entity\Donation;
use App\Message\SendDonationThanks;
use App\MessageHandler\SendDonationThanksHandler;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;

final class SendDonationThanksHandlerTest extends KernelTestCase
{
    public function testAPaidDonationIsAcknowledged(): void
    {
        self::bootKernel();
        $don = $this->donPaye('cs_test_merci', 'awa@example.org');

        $this->handler()(new SendDonationThanks((string) $don->getId()));

        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('Merci pour votre don', $email->getSubject());
        self::assertStringContainsString('awa@example.org', $email->getTo()[0]->getAddress());
        self::assertStringContainsString('25 EUR', (string) $email->getTextBody());
    }

    /**
     * Tant que la génération du CERFA n'est pas implémentée, rien ne doit
     * laisser croire au donateur qu'il recevra un reçu (CLAUDE.md §10).
     */
    public function testTheAcknowledgementPromisesNoTaxReceipt(): void
    {
        self::bootKernel();
        $don = $this->donPaye('cs_test_sans_recu', 'awa@example.org');

        $this->handler()(new SendDonationThanks((string) $don->getId()));

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);

        $corps = mb_strtolower((string) $email->getTextBody().(string) $email->getHtmlBody());
        foreach (['reçu fiscal', 'cerfa', 'déduction', 'réduction d\'impôt'] as $interdit) {
            self::assertStringNotContainsString($interdit, $corps, \sprintf('Le courriel ne doit pas mentionner « %s ».', $interdit));
        }
    }

    public function testAPendingDonationIsNotAcknowledged(): void
    {
        self::bootKernel();
        $manager = $this->manager();
        $don = new Donation('cs_test_attente', 2500, 'EUR');
        $manager->persist($don);
        $manager->flush();

        $this->handler()(new SendDonationThanks((string) $don->getId()));

        self::assertQueuedEmailCount(0);
    }

    public function testADonationWithoutEmailIsSkipped(): void
    {
        self::bootKernel();
        $don = $this->donPaye('cs_test_sans_email', null);

        $this->handler()(new SendDonationThanks((string) $don->getId()));

        self::assertQueuedEmailCount(0);
    }

    public function testAnUnknownIdentifierIsHarmless(): void
    {
        self::bootKernel();

        $this->handler()(new SendDonationThanks('pas-un-uuid'));
        $this->handler()(new SendDonationThanks('0192f000-0000-7000-8000-000000000000'));

        self::assertQueuedEmailCount(0);
    }

    private function donPaye(string $sessionId, ?string $email): Donation
    {
        $manager = $this->manager();
        $manager->createQuery('DELETE FROM '.Donation::class.' d')->execute();

        $don = new Donation($sessionId, 2500, 'EUR');
        $don->markPaid('pi_test', 'cus_test');
        $don->setDonor('Awa Ndiaye', $email, '12 rue des Lilas', '75011', 'Paris', 'FR');
        $manager->persist($don);
        $manager->flush();

        return $don;
    }

    private function handler(): SendDonationThanksHandler
    {
        $handler = static::getContainer()->get(SendDonationThanksHandler::class);
        self::assertInstanceOf(SendDonationThanksHandler::class, $handler);

        return $handler;
    }

    private function manager(): EntityManagerInterface
    {
        $manager = static::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }
}
