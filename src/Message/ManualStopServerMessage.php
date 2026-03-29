<?php

declare(strict_types=1);

namespace App\Message;

final readonly class ManualStopServerMessage
{
    public function __construct(
        public string $serverId,
    ) {
    }
}
