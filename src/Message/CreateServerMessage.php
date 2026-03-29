<?php

declare(strict_types=1);

namespace App\Message;

final class CreateServerMessage
{
    public function __construct(
        public readonly string $serverId,
        public readonly int $attempt = 0,
    ) {
    }
}
