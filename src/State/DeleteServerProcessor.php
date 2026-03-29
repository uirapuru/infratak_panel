<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Server;
use App\Message\DeleteServerMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @implements ProcessorInterface<Server, void>
 */
final readonly class DeleteServerProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        if (!$data instanceof Server) {
            throw new \InvalidArgumentException('Invalid server resource for deletion.');
        }

        $this->messageBus->dispatch(new DeleteServerMessage($data->getId(), $data->getAwsInstanceId()));

        $this->entityManager->remove($data);
        $this->entityManager->flush();
    }
}
