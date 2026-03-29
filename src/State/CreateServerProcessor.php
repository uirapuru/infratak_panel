<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Dto\CreateServerInput;
use App\Entity\Server;
use App\Service\Server\ServerCreationService;

/**
 * @implements ProcessorInterface<CreateServerInput, Server>
 */
final readonly class CreateServerProcessor implements ProcessorInterface
{
    public function __construct(
        private ServerCreationService $serverCreationService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Server
    {
        if (!$data instanceof CreateServerInput) {
            throw new \InvalidArgumentException('Invalid input for server creation.');
        }

        return $this->serverCreationService->createFromName($data->name);
    }
}
