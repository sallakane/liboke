<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContactMessage;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContactMessage>
 */
final class ContactMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactMessage::class);
    }

    public function save(ContactMessage $message): void
    {
        $manager = $this->getEntityManager();
        $manager->persist($message);
        $manager->flush();
    }

    /**
     * Supprime les messages antérieurs à la date donnée et renvoie leur nombre.
     */
    public function deleteOlderThan(DateTimeImmutable $limite): int
    {
        $supprimes = $this->createQueryBuilder('m')
            ->delete()
            ->where('m.createdAt < :limite')
            ->setParameter('limite', $limite)
            ->getQuery()
            ->execute();

        return is_numeric($supprimes) ? (int) $supprimes : 0;
    }

    public function countOlderThan(DateTimeImmutable $limite): int
    {
        $total = $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.createdAt < :limite')
            ->setParameter('limite', $limite)
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($total) ? (int) $total : 0;
    }
}
