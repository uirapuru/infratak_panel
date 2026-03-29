<?php

declare(strict_types=1);

namespace App\Service\Server;

use App\Entity\Server;
use App\Enum\ServerStep;
use App\Message\DeleteServerMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class ServerDeletionService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function queueCleanup(Server $server): void
    {
        $server
            ->setStep(ServerStep::CLEANUP)
            ->setLastError(null);

        $this->entityManager->persist($server);
        $this->entityManager->flush();

        $this->messageBus->dispatch(new DeleteServerMessage($server->getId()));
    }
}