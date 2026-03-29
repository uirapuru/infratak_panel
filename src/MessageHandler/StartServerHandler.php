<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Enum\ServerStatus;
use App\Enum\ServerStep;
use App\Message\ServerProjectionMessage;
use App\Message\StartServerMessage;
use App\Repository\ServerRepository;
use App\Service\Provisioning\AwsProvisioningClientInterface;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
#[WithMonologChannel('worker_provisioning')]
final readonly class StartServerHandler
{
    public function __construct(
        private ServerRepository $serverRepository,
        private AwsProvisioningClientInterface $aws,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(StartServerMessage $message): void
    {
        $server = $this->serverRepository->find($message->serverId);
        if ($server === null) {
            return;
        }

        $instanceId = $server->getAwsInstanceId();
        if ($instanceId === null || $instanceId === '') {
            $this->dispatchProjection(
                serverId: $server->getId(),
                status: $server->getStatus()->value,
                step: $server->getStep()->value,
                awsInstanceId: null,
                clearAwsInstanceId: false,
                publicIp: $server->getPublicIp(),
                clearPublicIp: false,
                lastError: 'Start requested, but EC2 instance id is not available.',
                clearLastError: false,
                startedAt: $server->getStartedAt(),
                endedAt: $server->getEndedAt(),
                clearEndedAt: false,
                logLevel: 'error',
                logMessage: 'Manual AWS start failed.',
                logContext: [
                    'target' => 'manual',
                ],
            );

            return;
        }

        try {
            $this->aws->startInstance($instanceId);
            $publicIp = $this->aws->getInstancePublicIp($instanceId);

            $this->dispatchProjection(
                serverId: $server->getId(),
                status: ServerStatus::READY->value,
                step: ServerStep::NONE->value,
                awsInstanceId: $instanceId,
                clearAwsInstanceId: false,
                publicIp: $publicIp,
                clearPublicIp: false,
                lastError: null,
                clearLastError: true,
                startedAt: new \DateTimeImmutable(),
                endedAt: null,
                clearEndedAt: true,
                logLevel: 'info',
                logMessage: 'Manual AWS start completed.',
                logContext: [
                    'target' => 'manual',
                    'instanceId' => $instanceId,
                ],
            );
        } catch (\Throwable $exception) {
            $this->dispatchProjection(
                serverId: $server->getId(),
                status: $server->getStatus()->value,
                step: $server->getStep()->value,
                awsInstanceId: $instanceId,
                clearAwsInstanceId: false,
                publicIp: $server->getPublicIp(),
                clearPublicIp: false,
                lastError: $exception->getMessage(),
                clearLastError: false,
                startedAt: $server->getStartedAt(),
                endedAt: $server->getEndedAt(),
                clearEndedAt: false,
                logLevel: 'error',
                logMessage: 'Manual AWS start failed.',
                logContext: [
                    'target' => 'manual',
                    'instanceId' => $instanceId,
                ],
            );

            $this->logger->error('Manual AWS start failed.', [
                'serverId' => $message->serverId,
                'instanceId' => $instanceId,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
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
        bool $clearEndedAt,
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
            clearEndedAt: $clearEndedAt,
            logLevel: $logLevel,
            logMessage: $logMessage,
            logContext: $logContext,
        ));
    }
}
