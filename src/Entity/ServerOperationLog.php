<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ServerOperationLogRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ServerOperationLogRepository::class)]
#[ORM\Table(name: 'server_operation_log')]
class ServerOperationLog
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Server::class, inversedBy: 'operationLogs')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Server $server;

    #[ORM\Column(type: Types::STRING, length: 16)]
    private string $level;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $status;

    #[ORM\Column(type: Types::STRING, length: 32, nullable: true)]
    private ?string $step;

    #[ORM\Column(type: Types::TEXT)]
    private string $message;

    /**
     * @var array<string, scalar|null>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $contextData;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, scalar|null> $contextData
     */
    public function __construct(
        Server $server,
        string $level,
        string $message,
        ?string $status,
        ?string $step,
        array $contextData = [],
    ) {
        $this->server = $server;
        $this->level = $level;
        $this->message = $message;
        $this->status = $status;
        $this->step = $step;
        $this->contextData = $contextData;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return sprintf('[%s] %s', strtoupper($this->level), $this->message);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getServer(): Server
    {
        return $this->server;
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function getStep(): ?string
    {
        return $this->step;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function getContextData(): array
    {
        return $this->contextData;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
