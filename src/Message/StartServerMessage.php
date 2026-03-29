<?php

declare(strict_types=1);

namespace App\Message;

final readonly class StartServerMessage
{
    public function __construct(
        public string $serverId,
    ) {
    }
}
