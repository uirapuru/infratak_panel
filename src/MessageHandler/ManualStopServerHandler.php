<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Enum\ServerStatus;
use App\Enum\ServerStep;
use App\Message\ManualStopServerMessage;
use App\Message\ServerProjectionMessage;
use App\Repository\ServerRepository;
use App\Service\Provisioning\AwsProvisioningClientInterface;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
#[WithMonologChannel('worker_provisioning')]
final readonly class ManualStopServerHandler
{
    public function __construct(
        private ServerRepository $serverRepository,
        private AwsProvisioningClientInterface $aws,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ManualStopServerMessage $message): void
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
                lastError: 'Stop requested, but EC2 instance id is not available.',
                clearLastError: false,
                startedAt: $server->getStartedAt(),
                endedAt: $server->getEndedAt(),
                clearEndedAt: false,
                logLevel: 'error',
                logMessage: 'Manual AWS stop failed.',
                logContext: [
                    'target' => 'manual',
                    'error' => 'Stop requested, but EC2 instance id is not available.',
                ],
            );

            return;
        }

        try {
            $this->aws->stopInstance($instanceId);

            $this->dispatchProjection(
                serverId: $server->getId(),
                status: ServerStatus::STOPPED->value,
                step: ServerStep::NONE->value,
                awsInstanceId: $instanceId,
                clearAwsInstanceId: false,
                publicIp: $server->getPublicIp(),
                clearPublicIp: false,
                lastError: null,
                clearLastError: true,
                startedAt: $server->getStartedAt(),
                endedAt: new \DateTimeImmutable(),
                clearEndedAt: false,
                clearSleepAt: true,
                logLevel: 'info',
                logMessage: 'Manual AWS stop completed.',
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
                logMessage: 'Manual AWS stop failed.',
                logContext: [
                    'target' => 'manual',
                    'instanceId' => $instanceId,
                    'error' => $exception->getMessage(),
                ],
            );

            $this->logger->error('Manual AWS stop failed.', [
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
        bool $clearSleepAt = false,
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
            clearSleepAt: $clearSleepAt,
            logLevel: $logLevel,
            logMessage: $logMessage,
            logContext: $logContext,
        ));
    }
}
