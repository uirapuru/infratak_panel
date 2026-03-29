<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\ServerOperationLog;
use App\Enum\ServerStatus;
use App\Enum\ServerStep;
use App\Message\ServerProjectionMessage;
use App\Repository\ServerRepository;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
#[WithMonologChannel('worker_projection')]
final readonly class ServerProjectionHandler
{
    public function __construct(
        private ServerRepository $serverRepository,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ServerProjectionMessage $message): void
    {
        $server = $this->serverRepository->find($message->serverId);
        if ($server === null) {
            $this->logger->warning('Projection message skipped because server was not found.', [
                'serverId' => $message->serverId,
            ]);

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

        if ($message->clearAwsInstanceId) {
            $server->setAwsInstanceId(null);
        }

        if ($message->publicIp !== null) {
            $server->setPublicIp($message->publicIp);
        }

        if ($message->clearPublicIp) {
            $server->setPublicIp(null);
        }

        if ($message->clearLastError) {
            $server->setLastError(null);
        }

        if ($message->lastError !== null) {
            $server->setLastError($message->lastError);
        }

        if ($message->startedAt !== null) {
            $server->setStartedAt($message->startedAt);
        }

        if ($message->endedAt !== null) {
            $server->setEndedAt($message->endedAt);
        }

        if ($message->clearEndedAt) {
            $server->setEndedAt(null);
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

        $this->logger->info('Projection message applied.', [
            'serverId' => $server->getId(),
            'status' => $message->status,
            'step' => $message->step,
            'logLevel' => $message->logLevel,
            'logMessage' => $message->logMessage,
        ]);
    }
}
