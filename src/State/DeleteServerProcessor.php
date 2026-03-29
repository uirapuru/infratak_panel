<?php

declare(strict_types=1);

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Server;
use App\Service\Server\ServerDeletionService;

/**
 * @implements ProcessorInterface<Server, void>
 */
final readonly class DeleteServerProcessor implements ProcessorInterface
{
    public function __construct(
        private ServerDeletionService $serverDeletionService,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): void
    {
        if (!$data instanceof Server) {
            throw new \InvalidArgumentException('Invalid server resource for deletion.');
        }

        $this->serverDeletionService->queueCleanup($data);
    }
}
