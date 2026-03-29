<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\ServerOperationLog;
use App\Enum\ServerStatus;
use App\Enum\ServerStep;
use App\Message\ServerProjectionMessage;
use App\Repository\ServerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ServerProjectionHandler
{
    public function __construct(
        private ServerRepository $serverRepository,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(ServerProjectionMessage $message): void
    {
        $server = $this->serverRepository->find($message->serverId);
        if ($server === null) {
            return;
        }

        if ($message->status !== null) {
            $server->setStatus(ServerStatus::from($message->status));
        }

        if ($message->step !== null) {
            $server->setStep(ServerStep::from($message->step));
        }

        if ($message->awsInstanceId !== null) {
            $server->setAwsInstanceId($message->awsInstanceId);
        }

        if ($message->publicIp !== null) {
            $server->setPublicIp($message->publicIp);
        }

        if ($message->clearLastError) {
            $server->setLastError(null);
        }

        if ($message->lastError !== null) {
            $server->setLastError($message->lastError);
        }

        $log = new ServerOperationLog(
            $server,
            $message->logLevel,
            $message->logMessage,
            $message->status,
            $message->step,
            $message->logContext,
        );

        $this->entityManager->persist($log);
        $this->entityManager->flush();
    }
}
