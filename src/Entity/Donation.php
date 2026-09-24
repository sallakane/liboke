<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\DonationRepository;
use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

/**
 * Registre local des dons (CLAUDE.md §10).
 *
 * Stripe reste le système de référence du paiement ; cette table est un
 * registre comptable et le support des futurs reçus fiscaux. Aucune donnée
 * bancaire n'y figure : le numéro de carte ne touche jamais nos serveurs.
 */
#[ORM\Entity(repositoryClass: DonationRepository::class)]
#[ORM\Table(name: 'donation')]
#[ORM\Index(name: 'idx_donation_created_at', columns: ['created_at'])]
#[ORM\Index(name: 'idx_donation_status', columns: ['status'])]
class Donation
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(length: 255, unique: true)]
    private string $stripeSessionId;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripePaymentIntentId = null;

    /** Nécessaire au Customer Portal, pour le don mensuel à venir. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeCustomerId = null;

    /** Réservé au don mensuel. Non alimenté au lancement. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeSubscriptionId = null;

    #[ORM\Column(enumType: DonationType::class)]
    private DonationType $type;

    /** En centimes. Jamais un flottant : il s'agit d'argent. */
    #[ORM\Column]
    private int $amountCents;

    #[ORM\Column(length: 3)]
    private string $currency;

    #[ORM\Column(enumType: DonationStatus::class)]
    private DonationStatus $status = DonationStatus::Pending;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $donorName = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $donorEmail = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $donorAddressLine = null;

    #[ORM\Column(length: 32, nullable: true)]
    private ?string $donorPostalCode = null;

    #[ORM\Column(length: 120, nullable: true)]
    private ?string $donorCity = null;

    #[ORM\Column(length: 2, nullable: true)]
    private ?string $donorCountry = null;

    #[ORM\Column]
    private bool $isCompany = false;

    /**
     * Réservés au reçu fiscal CERFA 11580. La numérotation séquentielle et la
     * génération du PDF ne sont PAS implémentées (CLAUDE.md §10) : tant que
     * c'est le cas, aucun e-mail ni aucune page ne doit promettre de reçu.
     */
    #[ORM\Column(length: 40, nullable: true, unique: true)]
    private ?string $receiptNumber = null;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $receiptIssuedAt = null;

    #[ORM\Column]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?DateTimeImmutable $paidAt = null;

    public function __construct(
        string $stripeSessionId,
        int $amountCents,
        string $currency,
        DonationType $type = DonationType::OneTime,
    ) {
        $this->id = Uuid::v7();
        $this->stripeSessionId = $stripeSessionId;
        $this->amountCents = $amountCents;
        $this->currency = $currency;
        $this->type = $type;
        $this->createdAt = new DateTimeImmutable();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getStripeSessionId(): string
    {
        return $this->stripeSessionId;
    }

    public function getStripePaymentIntentId(): ?string
    {
        return $this->stripePaymentIntentId;
    }

    public function getStripeCustomerId(): ?string
    {
        return $this->stripeCustomerId;
    }

    public function getStripeSubscriptionId(): ?string
    {
        return $this->stripeSubscriptionId;
    }

    public function getDonorAddressLine(): ?string
    {
        return $this->donorAddressLine;
    }

    public function getDonorPostalCode(): ?string
    {
        return $this->donorPostalCode;
    }

    public function getDonorCity(): ?string
    {
        return $this->donorCity;
    }

    public function getDonorCountry(): ?string
    {
        return $this->donorCountry;
    }

    public function isCompany(): bool
    {
        return $this->isCompany;
    }

    public function getReceiptIssuedAt(): ?DateTimeImmutable
    {
        return $this->receiptIssuedAt;
    }

    public function getType(): DonationType
    {
        return $this->type;
    }

    public function getAmountCents(): int
    {
        return $this->amountCents;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getStatus(): DonationStatus
    {
        return $this->status;
    }

    public function getDonorName(): ?string
    {
        return $this->donorName;
    }

    public function getDonorEmail(): ?string
    {
        return $this->donorEmail;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPaidAt(): ?DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function getReceiptNumber(): ?string
    {
        return $this->receiptNumber;
    }

    public function isPaid(): bool
    {
        return DonationStatus::Paid === $this->status;
    }

    /**
     * Confirmation par le webhook : seule transition qui fait foi.
     */
    public function markPaid(?string $paymentIntentId, ?string $customerId): void
    {
        $this->status = DonationStatus::Paid;
        $this->stripePaymentIntentId = $paymentIntentId;
        $this->stripeCustomerId = $customerId;
        $this->paidAt = new DateTimeImmutable();
    }

    public function markRefunded(): void
    {
        $this->status = DonationStatus::Refunded;
    }

    public function markFailed(): void
    {
        $this->status = DonationStatus::Failed;
    }

    /**
     * Coordonnées du donateur, telles que Stripe Checkout les a collectées.
     */
    public function setDonor(
        ?string $name,
        ?string $email,
        ?string $addressLine = null,
        ?string $postalCode = null,
        ?string $city = null,
        ?string $country = null,
    ): void {
        $this->donorName = $name;
        $this->donorEmail = $email;
        $this->donorAddressLine = $addressLine;
        $this->donorPostalCode = $postalCode;
        $this->donorCity = $city;
        $this->donorCountry = $country;
    }
}
