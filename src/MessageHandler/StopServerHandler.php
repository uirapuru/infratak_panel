<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Enum\ServerStatus;
use App\Enum\ServerStep;
use App\Message\ServerProjectionMessage;
use App\Message\StopServerMessage;
use App\Repository\ServerRepository;
use App\Service\Provisioning\AwsProvisioningClientInterface;
use App\Service\Provisioning\RetryableProvisioningException;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]
#[WithMonologChannel('worker_provisioning')]
final readonly class StopServerHandler
{
    private const int MAX_ATTEMPTS = 10;
    private const int RETRY_DELAY_MS = 30_000;

    public function __construct(
        private ServerRepository $serverRepository,
        private AwsProvisioningClientInterface $aws,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(StopServerMessage $message): void
    {
        $server = $this->serverRepository->find($message->serverId);
        if ($server === null) {
            return;
        }

        $sleepAt = $server->getSleepAt();
        if ($sleepAt === null || $sleepAt->getTimestamp() !== $message->targetSleepAt->getTimestamp()) {
            $this->logger->info('Stop message skipped because sleep schedule changed.', [
                'serverId' => $message->serverId,
            ]);

            return;
        }

        $now = new \DateTimeImmutable();
        if ($sleepAt > $now) {
            $this->redispatch($message->serverId, $sleepAt, $message->attempt);

            return;
        }

        $instanceId = $server->getAwsInstanceId();
        if ($instanceId === null || $instanceId === '') {
            $this->handleRetry($message, 'Stop requested, but EC2 instance id is not available yet.');

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
                logLevel: 'info',
                logMessage: 'Scheduled AWS stop completed.',
                logContext: [
                    'target' => 'sleep',
                    'sleepAt' => $sleepAt->format(DATE_ATOM),
                    'instanceId' => $instanceId,
                ],
            );
        } catch (RetryableProvisioningException $exception) {
            $this->handleRetry($message, $exception->getMessage());
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
                logLevel: 'error',
                logMessage: 'Scheduled AWS stop failed.',
                logContext: [
                    'target' => 'sleep',
                    'sleepAt' => $sleepAt->format(DATE_ATOM),
                    'instanceId' => $instanceId,
                ],
            );

            $this->logger->error('Scheduled AWS stop failed.', [
                'serverId' => $message->serverId,
                'instanceId' => $instanceId,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }

    private function handleRetry(StopServerMessage $message, string $reason): void
    {
        $server = $this->serverRepository->find($message->serverId);
        if ($server === null) {
            return;
        }

        $attempt = $message->attempt + 1;
        if ($attempt >= self::MAX_ATTEMPTS) {
            $this->dispatchProjection(
                serverId: $server->getId(),
                status: $server->getStatus()->value,
                step: $server->getStep()->value,
                awsInstanceId: $server->getAwsInstanceId(),
                clearAwsInstanceId: false,
                publicIp: $server->getPublicIp(),
                clearPublicIp: false,
                lastError: $reason,
                clearLastError: false,
                startedAt: $server->getStartedAt(),
                endedAt: $server->getEndedAt(),
                logLevel: 'error',
                logMessage: 'Scheduled AWS stop failed permanently.',
                logContext: [
                    'target' => 'sleep',
                    'attempt' => $attempt,
                    'sleepAt' => $message->targetSleepAt->format(DATE_ATOM),
                ],
            );

            return;
        }

        $this->dispatchProjection(
            serverId: $server->getId(),
            status: $server->getStatus()->value,
            step: $server->getStep()->value,
            awsInstanceId: $server->getAwsInstanceId(),
            clearAwsInstanceId: false,
            publicIp: $server->getPublicIp(),
            clearPublicIp: false,
            lastError: $reason,
            clearLastError: false,
            startedAt: $server->getStartedAt(),
            endedAt: $server->getEndedAt(),
            logLevel: 'warning',
            logMessage: 'Scheduled AWS stop retry queued.',
            logContext: [
                'target' => 'sleep',
                'attempt' => $attempt,
                'sleepAt' => $message->targetSleepAt->format(DATE_ATOM),
            ],
        );

        $this->redispatch($message->serverId, $message->targetSleepAt, $attempt, self::RETRY_DELAY_MS);
    }

    private function redispatch(string $serverId, \DateTimeImmutable $targetSleepAt, int $attempt, ?int $delayMs = null): void
    {
        $delay = $delayMs ?? max(0, ($targetSleepAt->getTimestamp() - time()) * 1000);
        $this->messageBus->dispatch(new Envelope(
            new StopServerMessage($serverId, $targetSleepAt, $attempt),
            [new DelayStamp($delay)],
        ));
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
