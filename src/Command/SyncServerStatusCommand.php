<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\ServerStatus;
use App\Enum\ServerStep;
use App\Message\ServerProjectionMessage;
use App\Repository\ServerRepository;
use App\Service\Provisioning\AwsProvisioningClientInterface;
use Aws\Exception\AwsException;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:servers:sync-status',
    description: 'Sync server status in DB with actual EC2 instance state on AWS.',
)]
#[WithMonologChannel('worker_provisioning')]
final class SyncServerStatusCommand extends Command
{
    public function __construct(
        private readonly ServerRepository $serverRepository,
        private readonly AwsProvisioningClientInterface $aws,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $servers = $this->serverRepository->findSyncable();

        if ($servers === []) {
            $output->writeln('No syncable servers found.');

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Syncing %d server(s) with AWS...', count($servers)));

        foreach ($servers as $server) {
            $instanceId = $server->getAwsInstanceId();

            try {
                $awsState = $this->aws->getInstanceState($instanceId);
            } catch (AwsException $e) {
                if (in_array($e->getAwsErrorCode(), ['InvalidInstanceID.NotFound', 'InvalidInstanceID.Malformed'], true)) {
                    // Instance no longer exists on AWS — treat as terminated
                    $awsState = 'terminated';
                } else {
                    $this->logger->warning('AWS state check failed during sync.', [
                        'serverId' => $server->getId(),
                        'instanceId' => $instanceId,
                        'error' => $e->getMessage(),
                    ]);
                    $output->writeln(sprintf('  [SKIP] %s — AWS error: %s', $server->getName(), $e->getAwsErrorCode()));
                    continue;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('AWS state check failed during sync.', [
                    'serverId' => $server->getId(),
                    'instanceId' => $instanceId,
                    'error' => $e->getMessage(),
                ]);
                $output->writeln(sprintf('  [SKIP] %s — error: %s', $server->getName(), $e->getMessage()));
                continue;
            }

            $targetStatus = $this->mapAwsState($awsState);

            if ($targetStatus === null) {
                // Transient AWS state (pending, stopping) — skip, check next time
                $output->writeln(sprintf('  [SKIP] %s — AWS state "%s" is transient', $server->getName(), $awsState));
                continue;
            }

            if ($targetStatus === $server->getStatus()) {
                $output->writeln(sprintf('  [OK]   %s — status "%s" matches AWS', $server->getName(), $server->getStatus()->value));
                continue;
            }

            $this->dispatchStatusCorrection($server->getId(), $server->getStatus(), $targetStatus, $awsState);

            $this->logger->warning('Server status corrected from AWS sync.', [
                'serverId' => $server->getId(),
                'instanceId' => $instanceId,
                'dbStatus' => $server->getStatus()->value,
                'awsState' => $awsState,
                'newStatus' => $targetStatus->value,
            ]);

            $output->writeln(sprintf(
                '  [FIX]  %s — "%s" → "%s" (AWS: %s)',
                $server->getName(),
                $server->getStatus()->value,
                $targetStatus->value,
                $awsState,
            ));
        }

        return Command::SUCCESS;
    }

    private function mapAwsState(string $awsState): ?ServerStatus
    {
        return match ($awsState) {
            'running'                    => ServerStatus::READY,
            'stopped'                    => ServerStatus::STOPPED,
            'terminated', 'shutting-down' => ServerStatus::DELETED,
            default                      => null, // transient (pending, stopping) and unknown — skip
        };
    }

    private function dispatchStatusCorrection(
        string $serverId,
        ServerStatus $currentStatus,
        ServerStatus $targetStatus,
        string $awsState,
    ): void {
        $isDeleted = $targetStatus === ServerStatus::DELETED;
        $isReady   = $targetStatus === ServerStatus::READY;
        $now = new \DateTimeImmutable();

        $this->messageBus->dispatch(new ServerProjectionMessage(
            serverId: $serverId,
            status: $targetStatus->value,
            step: ServerStep::NONE->value,
            awsInstanceId: null,
            clearAwsInstanceId: $isDeleted,
            publicIp: null,
            clearPublicIp: $isDeleted,
            lastError: null,
            clearLastError: false,
            // When the instance is back running, reset the timestamps:
            // startedAt = now (AWS reported it running, we don't know exact start time),
            // clearEndedAt = true (remove the stale endedAt from the previous stop).
            startedAt: $isReady ? $now : null,
            endedAt: in_array($targetStatus, [ServerStatus::STOPPED, ServerStatus::DELETED], true) ? $now : null,
            clearEndedAt: $isReady,
            logLevel: 'warning',
            logMessage: sprintf(
                'Status corrected by AWS sync: DB had "%s", AWS reports "%s".',
                $currentStatus->value,
                $awsState,
            ),
            logContext: [
                'awsState' => $awsState,
                'previousDbStatus' => $currentStatus->value,
            ],
        ));
    }
}
