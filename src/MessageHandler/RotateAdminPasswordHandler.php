<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\ServerOperationLog;
use App\Message\RotateAdminPasswordMessage;
use App\Repository\ServerRepository;
use App\Service\Provisioning\AwsProvisioningClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
#[WithMonologChannel('worker_provisioning')]
final readonly class RotateAdminPasswordHandler
{
    public function __construct(
        private ServerRepository $serverRepository,
        private AwsProvisioningClientInterface $aws,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RotateAdminPasswordMessage $message): void
    {
        $server = $this->serverRepository->find($message->serverId);
        if ($server === null) {
            return;
        }

        $instanceId = $server->getAwsInstanceId();
        if ($instanceId === null || $instanceId === '') {
            throw new \RuntimeException('Cannot rotate OTS admin password without AWS instance id.');
        }

        $this->logger->info('OTS admin password rotation attempt started.', [
            'serverId' => $server->getId(),
            'instanceId' => $instanceId,
            'origin' => $message->origin,
        ]);

        $passwordBeforeAttempt = $server->getOtsAdminPasswordCurrent() ?? $message->oldPassword;

        try {
            $this->aws->rotateOtsAdminPassword($instanceId, $server->getDomain(), $server->getPortalDomain() ?? '', $message->oldPassword, $message->newPassword);

            $server
                ->setOtsAdminPasswordPrevious($passwordBeforeAttempt)
                ->setOtsAdminPasswordCurrent($message->newPassword)
                ->setOtsAdminPasswordPendingReveal(null)
                ->setOtsAdminPasswordRotatedAt(new \DateTimeImmutable())
                ->setLastError(null);

            $this->entityManager->persist(new ServerOperationLog(
                $server,
                'info',
                'OTS admin password rotated successfully.',
                $server->getStatus()->value,
                $server->getStep()->value,
                ['origin' => $message->origin, 'instanceId' => $instanceId],
            ));
            $this->entityManager->flush();

            $this->logger->info('OTS admin password rotation attempt succeeded.', [
                'serverId' => $server->getId(),
                'instanceId' => $instanceId,
                'origin' => $message->origin,
            ]);
        } catch (\Throwable $exception) {
            $server->setLastError($exception->getMessage());

            $this->entityManager->persist(new ServerOperationLog(
                $server,
                'error',
                'OTS admin password rotation failed.',
                $server->getStatus()->value,
                $server->getStep()->value,
                ['origin' => $message->origin, 'instanceId' => $instanceId, 'error' => $exception->getMessage()],
            ));
            $this->entityManager->flush();

            $this->logger->error('OTS admin password rotation attempt failed.', [
                'serverId' => $server->getId(),
                'instanceId' => $instanceId,
                'origin' => $message->origin,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
