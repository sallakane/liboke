<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ContactMessage;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Tools\Pagination\Paginator;
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

    /**
     * Page de la liste d'administration, du plus récent au plus ancien.
     *
     * @return Paginator<ContactMessage>
     */
    public function paginate(int $page, int $perPage): Paginator
    {
        $query = $this->createQueryBuilder('m')
            ->orderBy('m.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery();

        /** @var Paginator<ContactMessage> $page */
        $page = new Paginator($query, fetchJoinCollection: false);

        return $page;
    }
}
