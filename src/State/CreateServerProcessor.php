<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\CreateServerInput;
use App\Entity\Server;
use App\Entity\User;
use App\Service\Server\ServerCreationService;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProcessorInterface<CreateServerInput, Server>
 */
final readonly class CreateServerProcessor implements ProcessorInterface
{
    public function __construct(
        private ServerCreationService $serverCreationService,
        private Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Server
    {
        if (!$data instanceof CreateServerInput) {
            throw new \InvalidArgumentException('Invalid input for server creation.');
        }

        $user = $this->security->getUser();

        return $this->serverCreationService->createFromName(
            $data->name,
            $data->sleepAt,
            $user instanceof User ? $user : null,
        );
    }
}
