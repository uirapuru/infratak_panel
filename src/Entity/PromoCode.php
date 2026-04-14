<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\PromoCodeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PromoCodeRepository::class)]
#[ORM\Table(name: 'promo_code')]
#[ORM\Index(columns: ['code'], name: 'IDX_promo_code_code')]
class PromoCode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    /** Uppercase unique code, e.g. "ALPHA2026". */
    #[ORM\Column(type: Types::STRING, length: 64, unique: true)]
    private string $code;

    /** How many days of server runtime the code grants. */
    #[ORM\Column(type: Types::INTEGER)]
    private int $durationDays = 1;

    /** Maximum total uses. NULL = unlimited. */
    #[ORM\Column(type: Types::INTEGER, nullable: true)]
    private ?int $maxUses = null;

    #[ORM\Column(type: Types::INTEGER)]
    private int $usedCount = 0;

    /** When the code stops working. NULL = never expires. */
    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column(type: Types::BOOLEAN)]
    private bool $isActive = true;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(string $code, int $durationDays = 1)
    {
        $this->code = strtoupper(trim($code));
        $this->durationDays = $durationDays;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): self
    {
        $this->code = strtoupper(trim($code));

        return $this;
    }

    public function getDurationDays(): int
    {
        return $this->durationDays;
    }

    public function setDurationDays(int $durationDays): self
    {
        $this->durationDays = $durationDays;

        return $this;
    }

    public function getMaxUses(): ?int
    {
        return $this->maxUses;
    }

    public function setMaxUses(?int $maxUses): self
    {
        $this->maxUses = $maxUses;

        return $this;
    }

    public function getUsedCount(): int
    {
        return $this->usedCount;
    }

    public function incrementUsedCount(): self
    {
        ++$this->usedCount;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(?\DateTimeImmutable $expiresAt): self
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): self
    {
        $this->isActive = $isActive;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * Returns true when the code can still be redeemed right now.
     */
    public function isValid(): bool
    {
        if (!$this->isActive) {
            return false;
        }

        if ($this->expiresAt !== null && $this->expiresAt <= new \DateTimeImmutable()) {
            return false;
        }

        if ($this->maxUses !== null && $this->usedCount >= $this->maxUses) {
            return false;
        }

        return true;
    }
}
