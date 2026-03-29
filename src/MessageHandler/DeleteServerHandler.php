<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Message\DeleteServerMessage;
use App\Service\Provisioning\AwsProvisioningClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class DeleteServerHandler
{
    public function __construct(
        private AwsProvisioningClientInterface $aws,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(DeleteServerMessage $message): void
    {
        if ($message->awsInstanceId === null || $message->awsInstanceId === '') {
            return;
        }

        $this->aws->terminateInstance($message->awsInstanceId);

        $this->logger->info('Server instance termination requested', [
            'serverId' => $message->serverId,
            'instanceId' => $message->awsInstanceId,
        ]);
    }
}
