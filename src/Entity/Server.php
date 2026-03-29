<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\ApiResource;
use App\Dto\CreateServerInput;
use App\Enum\ServerStatus;
use App\Enum\ServerStep;
use App\Repository\ServerRepository;
use App\State\CreateServerProcessor;
use App\State\DeleteServerProcessor;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ServerRepository::class)]
#[ORM\Table(name: 'server')]
#[ORM\HasLifecycleCallbacks]
#[ApiResource(
    operations: [
        new Get(),
        new GetCollection(),
        new Post(
            input: CreateServerInput::class,
            processor: CreateServerProcessor::class,
        ),
        new Delete(processor: DeleteServerProcessor::class),
    ],
    normalizationContext: ['groups' => ['server:read']],
)]
class Server
{
    #[ORM\Id]
    #[ORM\Column(type: Types::STRING, length: 36)]
    #[Groups(['server:read'])]
    private string $id;

    #[ORM\Column(type: Types::STRING, length: 64)]
    #[Groups(['server:read'])]
    private string $name;

    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Groups(['server:read'])]
    private string $domain;

    #[ORM\Column(type: Types::STRING, length: 255)]
    #[Groups(['server:read'])]
    private string $portalDomain;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: ServerStatus::class)]
    #[Groups(['server:read'])]
    private ServerStatus $status;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: ServerStep::class)]
    #[Groups(['server:read'])]
    private ServerStep $step;

    #[ORM\Column(type: Types::STRING, length: 64, nullable: true)]
    #[Groups(['server:read'])]
    private ?string $awsInstanceId = null;

    #[ORM\Column(type: Types::STRING, length: 45, nullable: true)]
    #[Groups(['server:read'])]
    private ?string $publicIp = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['server:read'])]
    private ?string $lastError = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['server:read'])]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['server:read'])]
    private ?\DateTimeImmutable $endedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['server:read'])]
    private ?\DateTimeImmutable $lastRetryAt = null;

    #[ORM\Column(type: Types::STRING, length: 16, nullable: true)]
    #[Groups(['server:read'])]
    private ?string $lastDiagnoseStatus = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Groups(['server:read'])]
    private ?string $lastDiagnoseLog = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    #[Groups(['server:read'])]
    private ?\DateTimeImmutable $lastDiagnosedAt = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['server:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['server:read'])]
    private \DateTimeImmutable $updatedAt;

    /**
     * @var Collection<int, ServerOperationLog>
     */
    #[ORM\OneToMany(mappedBy: 'server', targetEntity: ServerOperationLog::class, orphanRemoval: true)]
    private Collection $operationLogs;

    public function __construct()
    {
        $this->id = Uuid::v7()->toRfc4122();
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->status = ServerStatus::CREATING;
        $this->step = ServerStep::EC2;
        $this->operationLogs = new ArrayCollection();
    }

    #[ORM\PreUpdate]
    public function touch(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function markUpdated(): void
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function __toString(): string
    {
        return sprintf('%s (%s)', $this->name, $this->id);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function getDomain(): string
    {
        return $this->domain;
    }

    public function setDomain(string $domain): self
    {
        $this->domain = $domain;

        return $this;
    }

    public function getPortalDomain(): string
    {
        return $this->portalDomain;
    }

    public function setPortalDomain(string $portalDomain): self
    {
        $this->portalDomain = $portalDomain;

        return $this;
    }

    public function getStatus(): ServerStatus
    {
        return $this->status;
    }

    public function setStatus(ServerStatus $status): self
    {
        $this->status = $status;
        $this->markUpdated();

        return $this;
    }

    public function getStep(): ServerStep
    {
        return $this->step;
    }

    public function setStep(ServerStep $step): self
    {
        $this->step = $step;
        $this->markUpdated();

        return $this;
    }

    public function getAwsInstanceId(): ?string
    {
        return $this->awsInstanceId;
    }

    public function setAwsInstanceId(?string $awsInstanceId): self
    {
        $this->awsInstanceId = $awsInstanceId;
        $this->markUpdated();

        return $this;
    }

    public function getPublicIp(): ?string
    {
        return $this->publicIp;
    }

    public function setPublicIp(?string $publicIp): self
    {
        $this->publicIp = $publicIp;
        $this->markUpdated();

        return $this;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $lastError): self
    {
        $this->lastError = $lastError;
        $this->markUpdated();

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): self
    {
        $this->startedAt = $startedAt;
        $this->markUpdated();

        return $this;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    public function setEndedAt(?\DateTimeImmutable $endedAt): self
    {
        $this->endedAt = $endedAt;
        $this->markUpdated();

        return $this;
    }

    public function getLastRetryAt(): ?\DateTimeImmutable
    {
        return $this->lastRetryAt;
    }

    public function setLastRetryAt(?\DateTimeImmutable $lastRetryAt): self
    {
        $this->lastRetryAt = $lastRetryAt;
        $this->markUpdated();

        return $this;
    }

    public function getLastDiagnoseStatus(): ?string
    {
        return $this->lastDiagnoseStatus;
    }

    public function setLastDiagnoseStatus(?string $lastDiagnoseStatus): self
    {
        $this->lastDiagnoseStatus = $lastDiagnoseStatus;
        $this->markUpdated();

        return $this;
    }

    public function getLastDiagnoseLog(): ?string
    {
        return $this->lastDiagnoseLog;
    }

    public function setLastDiagnoseLog(?string $lastDiagnoseLog): self
    {
        $this->lastDiagnoseLog = $lastDiagnoseLog;
        $this->markUpdated();

        return $this;
    }

    public function getLastDiagnosedAt(): ?\DateTimeImmutable
    {
        return $this->lastDiagnosedAt;
    }

    public function setLastDiagnosedAt(?\DateTimeImmutable $lastDiagnosedAt): self
    {
        $this->lastDiagnosedAt = $lastDiagnosedAt;
        $this->markUpdated();

        return $this;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    #[Groups(['server:read'])]
    public function getRuntimeHours(): ?float
    {
        if ($this->startedAt === null) {
            return null;
        }

        $end = $this->endedAt ?? new \DateTimeImmutable();
        $seconds = max(0, $end->getTimestamp() - $this->startedAt->getTimestamp());

        return round($seconds / 3600, 2);
    }

    /**
     * @return Collection<int, ServerOperationLog>
     */
    public function getOperationLogs(): Collection
    {
        return $this->operationLogs;
    }
}
