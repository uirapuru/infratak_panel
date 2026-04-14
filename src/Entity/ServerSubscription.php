<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ServerSubscriptionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ServerSubscriptionRepository::class)]
#[ORM\Table(name: 'server_subscription')]
#[ORM\Index(columns: ['server_id'], name: 'IDX_server_subscription_server')]
#[ORM\Index(columns: ['user_id'], name: 'IDX_server_subscription_user')]
class ServerSubscription
{
    public const int PRICE_PER_DAY_CENTS = 5000;
    public const string CURRENCY = 'PLN';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Server::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Server $server;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(type: Types::INTEGER)]
    private int $days;

    #[ORM\Column(type: Types::INTEGER)]
    private int $amountGrossCents;

    #[ORM\Column(type: Types::STRING, length: 3)]
    private string $currency = self::CURRENCY;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $startsAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(Server $server, User $user, int $days, \DateTimeImmutable $startsAt, \DateTimeImmutable $expiresAt)
    {
        if ($days < 1) {
            throw new \InvalidArgumentException('Subscription must be purchased for at least 1 day.');
        }

        $this->server = $server;
        $this->user = $user;
        $this->days = $days;
        $this->amountGrossCents = $days * self::PRICE_PER_DAY_CENTS;
        $this->startsAt = $startsAt;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getServer(): Server
    {
        return $this->server;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getDays(): int
    {
        return $this->days;
    }

    public function getAmountGrossCents(): int
    {
        return $this->amountGrossCents;
    }

    public function getAmountGrossPln(): string
    {
        return number_format($this->amountGrossCents / 100, 2, '.', '');
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function getStartsAt(): \DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
