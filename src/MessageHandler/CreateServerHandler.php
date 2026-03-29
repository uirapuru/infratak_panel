<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Enum\ServerStatus;
use App\Message\CreateServerMessage;
use App\Repository\ServerRepository;
use App\Service\Provisioning\ProvisioningOrchestrator;
use App\Service\Provisioning\RetryableProvisioningException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

#[AsMessageHandler]
final readonly class CreateServerHandler
{
    private const int MAX_ATTEMPTS = 5;

    public function __construct(
        private ServerRepository $serverRepository,
        private EntityManagerInterface $entityManager,
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
            $server->setLastError(null);
            $this->entityManager->flush();

            if (!$finished && $server->getStatus() !== ServerStatus::READY) {
                $this->redispatch($message->serverId, 0, 500);
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
        $server->setLastError($exception->getMessage());

        if ($attempt >= self::MAX_ATTEMPTS) {
            $server->setStatus(ServerStatus::FAILED);
            $this->entityManager->flush();

            $this->logger->error('Provisioning failed permanently', [
                'serverId' => $message->serverId,
                'error' => $exception->getMessage(),
                'attempt' => $attempt,
            ]);

            return;
        }

        $this->entityManager->flush();
        $this->logger->warning('Provisioning retry scheduled', [
            'serverId' => $message->serverId,
            'attempt' => $attempt,
            'error' => $exception->getMessage(),
        ]);

        $this->redispatch($message->serverId, $attempt, random_int(10_000, 30_000));
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
