<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\CreateServerInput;
use App\Entity\Server;
use App\Message\CreateServerMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @implements ProcessorInterface<CreateServerInput, Server>
 */
final readonly class CreateServerProcessor implements ProcessorInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Server
    {
        if (!$data instanceof CreateServerInput) {
            throw new \InvalidArgumentException('Invalid input for server creation.');
        }

        $name = strtolower(trim($data->name));

        $server = (new Server())
            ->setName($name)
            ->setDomain(sprintf('%s.calbal.net', $name))
            ->setPortalDomain(sprintf('portal.%s.calbal.net', $name));

        $this->entityManager->persist($server);
        $this->entityManager->flush();

        $this->messageBus->dispatch(new CreateServerMessage($server->getId()));

        return $server;
    }
}
