<?php

declare(strict_types=1);

namespace App\Service\Server;

use App\Entity\Server;
use App\Enum\ServerStatus;
use App\Enum\ServerStep;
use App\Message\CreateServerMessage;
use App\Message\StopServerMessage;
use App\Repository\ServerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

final readonly class ServerCreationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private ServerRepository $serverRepository,
        private string $baseDomain,
    ) {
    }

    public function createFromName(string $rawName, ?\DateTimeImmutable $sleepAt = null): Server
    {
        $server = new Server();
        $server->setSleepAt($sleepAt);
        $this->initializeForProvisioning($server, $rawName);

        $this->entityManager->persist($server);
        $this->entityManager->flush();

        $this->messageBus->dispatch(new CreateServerMessage($server->getId()));
        $this->scheduleSleepIfNeeded($server);

        return $server;
    }

    public function queueProvisioningForExisting(Server $server): void
    {
        $this->initializeForProvisioning($server, $server->getName());

        $this->entityManager->persist($server);
        $this->entityManager->flush();

        $this->messageBus->dispatch(new CreateServerMessage($server->getId()));
        $this->scheduleSleepIfNeeded($server);
    }

    private function initializeForProvisioning(Server $server, string $rawName): void
    {
        $name = strtolower(trim($rawName));

        if ($this->serverRepository->hasActiveServerWithName($name, $server->getId())) {
            throw new \InvalidArgumentException(sprintf('Active server with name "%s" already exists.', $name));
        }

        $server
            ->setName($name)
            ->setDomain(sprintf('%s.%s', $name, $this->normalizedBaseDomain()))
            ->setPortalDomain(sprintf('portal.%s.%s', $name, $this->normalizedBaseDomain()))
            ->setStatus(ServerStatus::CREATING)
            ->setStep(ServerStep::EC2)
            ->setAwsInstanceId(null)
            ->setPublicIp(null)
            ->setStartedAt(null)
            ->setEndedAt(null)
            ->setOtsAdminPasswordPrevious($server->getOtsAdminPasswordCurrent())
            ->setOtsAdminPasswordCurrent('password')
            ->setOtsAdminPasswordPendingReveal(null)
            ->setOtsAdminPasswordRotatedAt(null)
            ->setLastError(null);
    }

    private function normalizedBaseDomain(): string
    {
        return trim(strtolower($this->baseDomain), " \t\n\r\0\x0B.");
    }

    private function scheduleSleepIfNeeded(Server $server): void
    {
        $sleepAt = $server->getSleepAt();
        if ($sleepAt === null) {
            return;
        }

        $delayMs = max(0, ($sleepAt->getTimestamp() - time()) * 1000);
        $envelope = new Envelope(
            new StopServerMessage($server->getId(), $sleepAt),
            [new DelayStamp($delayMs)],
        );

        $this->messageBus->dispatch($envelope);
    }
}
