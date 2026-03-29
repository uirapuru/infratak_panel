<?php

declare(strict_types=1);

namespace App\Service\Server;

use App\Entity\Server;
use App\Enum\ServerStatus;
use App\Enum\ServerStep;
use App\Message\CreateServerMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class ServerCreationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function createFromName(string $rawName): Server
    {
        $server = new Server();
        $this->initializeForProvisioning($server, $rawName);

        $this->entityManager->persist($server);
        $this->entityManager->flush();

        $this->messageBus->dispatch(new CreateServerMessage($server->getId()));

        return $server;
    }

    public function queueProvisioningForExisting(Server $server): void
    {
        $this->initializeForProvisioning($server, $server->getName());

        $this->entityManager->persist($server);
        $this->entityManager->flush();

        $this->messageBus->dispatch(new CreateServerMessage($server->getId()));
    }

    private function initializeForProvisioning(Server $server, string $rawName): void
    {
        $name = strtolower(trim($rawName));

        $server
            ->setName($name)
            ->setDomain(sprintf('%s.calbal.net', $name))
            ->setPortalDomain(sprintf('portal.%s.calbal.net', $name))
            ->setStatus(ServerStatus::CREATING)
            ->setStep(ServerStep::EC2)
            ->setAwsInstanceId(null)
            ->setPublicIp(null)
            ->setLastError(null);
    }
}
