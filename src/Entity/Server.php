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
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: ServerRepository::class)]
#[ORM\Table(name: 'server')]
#[ORM\UniqueConstraint(name: 'uniq_server_name', columns: ['name'])]
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

    #[ORM\Column(type: Types::STRING, length: 64, unique: true)]
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

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['server:read'])]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    #[Groups(['server:read'])]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->id = Uuid::v7()->toRfc4122();
        $now = new \DateTimeImmutable();
        $this->createdAt = $now;
        $this->updatedAt = $now;
        $this->status = ServerStatus::CREATING;
        $this->step = ServerStep::EC2;
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

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
