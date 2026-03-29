<?php

declare(strict_types=1);

namespace App\MessageHandler;

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

        try {
            $this->aws->rotateOtsAdminPassword($instanceId, $message->oldPassword, $message->newPassword);

            $pendingReveal = $message->origin === 'manual-reset' ? null : $message->newPassword;

            $server
                ->setOtsAdminPasswordPrevious($message->oldPassword)
                ->setOtsAdminPasswordCurrent($message->newPassword)
                ->setOtsAdminPasswordPendingReveal($pendingReveal)
                ->setOtsAdminPasswordRotatedAt(new \DateTimeImmutable())
                ->setLastError(null);

            $this->entityManager->flush();

            $this->logger->info('OTS admin password rotated.', [
                'serverId' => $server->getId(),
                'origin' => $message->origin,
            ]);
        } catch (\Throwable $exception) {
            $server->setLastError($exception->getMessage());
            $this->entityManager->flush();

            $this->logger->error('OTS admin password rotation failed.', [
                'serverId' => $server->getId(),
                'origin' => $message->origin,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
