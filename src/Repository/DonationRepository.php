<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Donation;
use App\Entity\DonationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Donation>
 */
final class DonationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Donation::class);
    }

    public function save(Donation $donation): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($donation);
        $manager->flush();
    }

    public function findOneByStripeSessionId(string $sessionId): ?Donation
    {
        return $this->findOneBy(['stripeSessionId' => $sessionId]);
    }

    public function findOneByStripePaymentIntentId(string $paymentIntentId): ?Donation
    {
        return $this->findOneBy(['stripePaymentIntentId' => $paymentIntentId]);
    }

    public function findOneById(Uuid $id): ?Donation
    {
        return $this->find($id);
    }

    /**
     * @return list<Donation>
     */
    public function findPaid(): array
    {
        return array_values($this->findBy(['status' => DonationStatus::Paid], ['paidAt' => 'DESC']));
    }
}
