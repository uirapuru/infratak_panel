<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Enum\ServerStatus;
use App\Enum\ServerStep;
use App\Message\DeleteServerMessage;
use App\Message\ServerProjectionMessage;
use App\Repository\ServerRepository;
use App\Service\Provisioning\AwsProvisioningClientInterface;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
#[WithMonologChannel('worker_provisioning')]
final readonly class DeleteServerHandler
{
    public function __construct(
        private ServerRepository $serverRepository,
        private AwsProvisioningClientInterface $aws,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DeleteServerMessage $message): void
    {
        $server = $this->serverRepository->find($message->serverId);
        if ($server === null) {
            $this->logger->warning('Cleanup skipped because server was not found.', [
                'serverId' => $message->serverId,
            ]);

            return;
        }

        try {
            $this->logger->info('Cleanup message received', [
                'serverId' => $message->serverId,
                'target' => 'cleanup',
            ]);

            $this->aws->cleanupServer(
                $server->getName(),
                $server->getAwsInstanceId(),
                $server->getDomain(),
                $server->getPortalDomain(),
            );

            $this->dispatchProjection(
                serverId: $server->getId(),
                status: ServerStatus::DELETED->value,
                step: ServerStep::CLEANUP->value,
                awsInstanceId: null,
                clearAwsInstanceId: true,
                publicIp: null,
                clearPublicIp: true,
                lastError: null,
                clearLastError: true,
                startedAt: $server->getStartedAt(),
                endedAt: new \DateTimeImmutable(),
                logLevel: 'info',
                logMessage: 'AWS cleanup finished.',
                logContext: [
                    'target' => 'cleanup',
                ],
            );
        } catch (\Throwable $exception) {
            // Do not dispatch a projection on each failed attempt — the handler re-throws so
            // RabbitMQ retries up to the configured max_retries. Dispatching a FAILED projection
            // here would create one ServerOperationLog entry per retry attempt (up to 5), filling
            // the log with duplicate entries. The error is recorded via the logger instead.
            // A FAILED projection is dispatched only after the final retry is exhausted by the
            // Messenger failure transport (see TODO #15 in docs/todo.md).
            $this->logger->error('AWS cleanup failed', [
                'serverId' => $message->serverId,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $this->logger->info('Server cleanup requested', [
            'serverId' => $message->serverId,
            'instanceId' => $server->getAwsInstanceId(),
        ]);
    }

    /**
     * @param array<string, scalar|null> $logContext
     */
    private function dispatchProjection(
        string $serverId,
        ?string $status,
        ?string $step,
        ?string $awsInstanceId,
        bool $clearAwsInstanceId,
        ?string $publicIp,
        bool $clearPublicIp,
        ?string $lastError,
        bool $clearLastError,
        ?\DateTimeImmutable $startedAt,
        ?\DateTimeImmutable $endedAt,
        string $logLevel,
        string $logMessage,
        array $logContext,
    ): void {
        $this->messageBus->dispatch(new ServerProjectionMessage(
            serverId: $serverId,
            status: $status,
            step: $step,
            awsInstanceId: $awsInstanceId,
            clearAwsInstanceId: $clearAwsInstanceId,
            publicIp: $publicIp,
            clearPublicIp: $clearPublicIp,
            lastError: $lastError,
            clearLastError: $clearLastError,
            startedAt: $startedAt,
            endedAt: $endedAt,
            logLevel: $logLevel,
            logMessage: $logMessage,
            logContext: $logContext,
        ));
    }
}
