<?php

declare(strict_types=1);

namespace App\Repository;

use App\Admin\DonationFilter;
use App\Entity\Donation;
use App\Entity\DonationStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
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

    /**
     * Page de la liste d'administration, du plus récent au plus ancien.
     *
     * @return Paginator<Donation>
     */
    public function search(DonationFilter $filter, int $page, int $perPage): Paginator
    {
        $query = $this->filtered($filter)
            ->orderBy('d.createdAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery();

        /** @var Paginator<Donation> $page */
        $page = new Paginator($query, fetchJoinCollection: false);

        return $page;
    }

    /**
     * Nombre de dons et montant cumulé par statut, pour les filtres donnés.
     *
     * @return array<string, array{count: int, amountCents: int}> indexé par valeur de statut
     */
    public function totalsByStatus(DonationFilter $filter): array
    {
        /** @var list<array{status: DonationStatus, total: int|string, amount: int|string|null}> $lignes */
        $lignes = $this->filtered($filter)
            ->select('d.status AS status, COUNT(d.id) AS total, SUM(d.amountCents) AS amount')
            ->groupBy('d.status')
            ->getQuery()
            ->getResult();

        $totaux = [];

        foreach ($lignes as $ligne) {
            $totaux[$ligne['status']->value] = [
                'count' => (int) $ligne['total'],
                'amountCents' => (int) ($ligne['amount'] ?? 0),
            ];
        }

        return $totaux;
    }

    /**
     * Tous les dons filtrés, lus un par un : l'export CSV ne charge jamais
     * la table entière en mémoire.
     *
     * @return iterable<Donation>
     */
    public function iterate(DonationFilter $filter): iterable
    {
        /** @var iterable<Donation> $dons */
        $dons = $this->filtered($filter)
            ->orderBy('d.createdAt', 'ASC')
            ->getQuery()
            ->toIterable();

        return $dons;
    }

    private function filtered(DonationFilter $filter): QueryBuilder
    {
        $builder = $this->createQueryBuilder('d');

        if (null !== $filter->from) {
            $builder->andWhere('d.createdAt >= :du')->setParameter('du', $filter->from);
        }

        if (null !== $filter->toExclusive()) {
            $builder->andWhere('d.createdAt < :au')->setParameter('au', $filter->toExclusive());
        }

        if (null !== $filter->status) {
            $builder->andWhere('d.status = :statut')->setParameter('statut', $filter->status);
        }

        return $builder;
    }
}
