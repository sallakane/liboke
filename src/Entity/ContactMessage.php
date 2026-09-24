<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ContactMessageRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Message reçu par le formulaire de contact.
 *
 * Persisté en plus de l'envoi par e-mail : un courriel perdu ou classé en
 * spam ne doit pas faire perdre un contact (CLAUDE.md §9).
 *
 * Aucune adresse IP n'est stockée : la limitation de débit travaille en
 * cache, pas en base (CLAUDE.md §11).
 */
#[ORM\Entity(repositoryClass: ContactMessageRepository::class)]
#[ORM\Table(name: 'contact_message')]
#[ORM\Index(name: 'idx_contact_message_created_at', columns: ['created_at'])]
class ContactMessage
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(length: 180)]
    private string $email;

    #[ORM\Column(length: 180)]
    private string $subject;

    #[ORM\Column(type: Types::TEXT)]
    private string $message;

    #[ORM\Column]
    private bool $consent;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    public function __construct(
        string $name,
        string $email,
        string $subject,
        string $message,
        bool $consent,
        ?DateTimeImmutable $createdAt = null,
    ) {
        $this->id = Uuid::v7();
        $this->name = $name;
        $this->email = $email;
        $this->subject = $subject;
        $this->message = $message;
        $this->consent = $consent;
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getSubject(): string
    {
        return $this->subject;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function hasConsent(): bool
    {
        return $this->consent;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
