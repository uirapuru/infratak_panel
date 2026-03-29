<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Enum\ServerStatus;
use App\Message\CreateServerMessage;
use App\Message\ServerProjectionMessage;
use App\Repository\ServerRepository;
use App\Service\Provisioning\ProvisioningOrchestrator;
use App\Service\Provisioning\RetryableProvisioningException;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]
#[WithMonologChannel('worker_provisioning')]
final readonly class CreateServerHandler
{
    private const int MAX_ATTEMPTS = 5;

    public function __construct(
        private ServerRepository $serverRepository,
        private ProvisioningOrchestrator $orchestrator,
        private MessageBusInterface $messageBus,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CreateServerMessage $message): void
    {
        $server = $this->serverRepository->find($message->serverId);
        if ($server === null) {
            return;
        }

        try {
            $this->logger->info('Provisioning message received', [
                'serverId' => $message->serverId,
                'attempt' => $message->attempt,
            ]);

            $finished = $this->orchestrator->advance($server);
            $this->dispatchProjection(
                serverId: $server->getId(),
                status: $server->getStatus()->value,
                step: $server->getStep()->value,
                awsInstanceId: $server->getAwsInstanceId(),
                clearAwsInstanceId: false,
                publicIp: $server->getPublicIp(),
                clearPublicIp: false,
                lastError: null,
                clearLastError: true,
                startedAt: $server->getStartedAt(),
                endedAt: $server->getEndedAt(),
                logLevel: 'info',
                logMessage: 'Provisioning step processed.',
                logContext: [
                    'attempt' => $message->attempt,
                    'finished' => $finished ? 1 : 0,
                ],
            );

            if (!$finished && $server->getStatus() !== ServerStatus::READY) {
                $this->redispatch($message->serverId, 0, 2_000);
            }
        } catch (RetryableProvisioningException $exception) {
            $this->handleRetry($message, $exception);
        } catch (\Throwable $exception) {
            $this->handleRetry($message, $exception);
        }
    }

    private function handleRetry(CreateServerMessage $message, \Throwable $exception): void
    {
        $server = $this->serverRepository->find($message->serverId);
        if ($server === null) {
            return;
        }

        $attempt = $message->attempt + 1;

        if ($attempt >= self::MAX_ATTEMPTS) {
            $this->dispatchProjection(
                serverId: $server->getId(),
                status: ServerStatus::FAILED->value,
                step: $server->getStep()->value,
                awsInstanceId: $server->getAwsInstanceId(),
                clearAwsInstanceId: false,
                publicIp: $server->getPublicIp(),
                clearPublicIp: false,
                lastError: $exception->getMessage(),
                clearLastError: false,
                startedAt: $server->getStartedAt(),
                endedAt: $server->getEndedAt(),
                logLevel: 'error',
                logMessage: 'Provisioning failed permanently.',
                logContext: [
                    'attempt' => $attempt,
                ],
            );

            $this->logger->error('Provisioning failed permanently', [
                'serverId' => $message->serverId,
                'error' => $exception->getMessage(),
                'attempt' => $attempt,
            ]);

            return;
        }

        $this->logger->warning('Provisioning retry scheduled', [
            'serverId' => $message->serverId,
            'attempt' => $attempt,
            'error' => $exception->getMessage(),
        ]);

        $this->dispatchProjection(
            serverId: $server->getId(),
            status: $server->getStatus()->value,
            step: $server->getStep()->value,
            awsInstanceId: $server->getAwsInstanceId(),
            clearAwsInstanceId: false,
            publicIp: $server->getPublicIp(),
            clearPublicIp: false,
            lastError: $exception->getMessage(),
            clearLastError: false,
            startedAt: $server->getStartedAt(),
            endedAt: $server->getEndedAt(),
            logLevel: 'warning',
            logMessage: 'Provisioning retry scheduled.',
            logContext: [
                'attempt' => $attempt,
            ],
        );

        $this->redispatch($message->serverId, $attempt, random_int(10_000, 30_000));
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

    private function redispatch(string $serverId, int $attempt, int $delayMs): void
    {
        $envelope = new Envelope(
            new CreateServerMessage($serverId, $attempt),
            [new DelayStamp($delayMs)],
        );

        $this->messageBus->dispatch($envelope);
    }
}
