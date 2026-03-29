<?php

declare(strict_types=1);

namespace App\Message;

final readonly class StopServerMessage
{
    public function __construct(
        public string $serverId,
        public \DateTimeImmutable $targetSleepAt,
        public int $attempt = 0,
    ) {
    }
}
