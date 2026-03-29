<?php

declare(strict_types=1);

namespace App\Service\Server;

use App\Entity\Server;
use App\Enum\ServerStep;
use App\Message\DeleteServerMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class ServerDeletionService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    public function queueCleanup(Server $server): void
    {
        $server
            ->setStep(ServerStep::CLEANUP)
            ->setLastError(null);

        $this->entityManager->persist($server);
        $this->entityManager->flush();

        $queuedAt = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Warsaw'));
        $this->messageBus->dispatch(new DeleteServerMessage($server->getId()));

        $this->logger->info('Server cleanup queued for dispatch', [
            'serverId' => $server->getId(),
            'queuedAt' => $queuedAt->format(\DateTimeInterface::ATOM),
            'queuedAtUnixMs' => (int) $queuedAt->format('Uv'),
        ]);
    }
}