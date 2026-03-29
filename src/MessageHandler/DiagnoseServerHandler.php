<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\ServerOperationLog;
use App\Enum\ServerStatus;
use App\Enum\ServerStep;
use App\Message\DiagnoseServerMessage;
use App\Repository\ServerRepository;
use App\Service\Provisioning\AwsProvisioningClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
#[WithMonologChannel('worker_provisioning')]
final readonly class DiagnoseServerHandler
{
    public function __construct(
        private ServerRepository $serverRepository,
        private AwsProvisioningClientInterface $awsProvisioningClient,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DiagnoseServerMessage $message): void
    {
        $server = $this->serverRepository->find($message->serverId);
        if ($server === null) {
            return;
        }

        $server->setStatus(ServerStatus::DIAGNOSING);
        $server->setStep(ServerStep::WAIT_SSM);
        $server->setLastDiagnoseStatus('running');
        $this->entityManager->flush();

        $instanceId = $server->getAwsInstanceId();
        if ($instanceId === null || $instanceId === '') {
            $logMessage = 'Diagnose failed: missing EC2 instance id.';
            $server->setStatus(ServerStatus::FAILED);
            $server->setLastDiagnoseStatus('failed');
            $server->setLastDiagnosedAt(new \DateTimeImmutable());
            $server->setLastDiagnoseLog($logMessage);
            $server->setLastError($logMessage);

            $this->entityManager->persist(new ServerOperationLog(
                $server,
                'error',
                $logMessage,
                $server->getStatus()->value,
                $server->getStep()->value,
                ['diagnoseStatus' => 'failed', 'fullLog' => $logMessage],
            ));
            $this->entityManager->flush();

            return;
        }

        try {
            $server->setStep(ServerStep::PROVISION);
            $this->entityManager->flush();

            $result = $this->awsProvisioningClient->sendDiagnoseCommand($instanceId, $server->getDomain(), $server->getPortalDomain());

            $isSuccess = $result['status'] === 'Success';
            $diagnoseStatus = $isSuccess ? 'success' : 'failed';
            $fullLog = $result['output'];
            $summary = sprintf('Diagnose %s (SSM status: %s)', $diagnoseStatus, $result['status']);

            $server->setStatus($isSuccess ? ServerStatus::READY : ServerStatus::FAILED);
            if ($isSuccess) {
                $server->setStep(ServerStep::NONE);
            }
            $server->setLastDiagnoseStatus($diagnoseStatus);
            $server->setLastDiagnosedAt(new \DateTimeImmutable());
            $server->setLastDiagnoseLog($fullLog);
            if (!$isSuccess) {
                $server->setLastError($summary);
            }

            $this->entityManager->persist(new ServerOperationLog(
                $server,
                $isSuccess ? 'info' : 'error',
                $summary,
                $server->getStatus()->value,
                $server->getStep()->value,
                [
                    'diagnoseStatus' => $diagnoseStatus,
                    'ssmCommandId' => $result['commandId'],
                    'ssmStatus' => $result['status'],
                    'fullLog' => $fullLog,
                ],
            ));

            $this->entityManager->flush();
        } catch (\Throwable $exception) {
            $fullLog = $exception->getMessage();
            $server->setStatus(ServerStatus::FAILED);
            $server->setLastDiagnoseStatus('failed');
            $server->setLastDiagnosedAt(new \DateTimeImmutable());
            $server->setLastDiagnoseLog($fullLog);
            $server->setLastError($fullLog);

            $this->entityManager->persist(new ServerOperationLog(
                $server,
                'error',
                'Diagnose failed with exception.',
                $server->getStatus()->value,
                $server->getStep()->value,
                ['diagnoseStatus' => 'failed', 'fullLog' => $fullLog],
            ));

            $this->entityManager->flush();

            $this->logger->error('Diagnose command failed.', [
                'serverId' => $message->serverId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
