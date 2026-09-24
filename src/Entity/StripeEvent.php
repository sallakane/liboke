<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\StripeEventRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

/**
 * Journal des événements Stripe déjà traités.
 *
 * Stripe réémet ses événements : sans cette table, un même don pourrait être
 * compté et remercié deux fois. L'insertion de l'identifiant, protégée par un
 * index unique, EST le verrou d'idempotence (CLAUDE.md §10, règle 3).
 */
#[ORM\Entity(repositoryClass: StripeEventRepository::class)]
#[ORM\Table(name: 'stripe_event')]
class StripeEvent
{
    #[ORM\Id]
    #[ORM\Column(length: 255, unique: true)]
    private string $eventId;

    #[ORM\Column(length: 100)]
    private string $type;

    #[ORM\Column]
    private DateTimeImmutable $processedAt;

    public function __construct(string $eventId, string $type)
    {
        $this->eventId = $eventId;
        $this->type = $type;
        $this->processedAt = new DateTimeImmutable();
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getProcessedAt(): DateTimeImmutable
    {
        return $this->processedAt;
    }
}
