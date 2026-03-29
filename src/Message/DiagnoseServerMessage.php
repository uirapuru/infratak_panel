<?php

declare(strict_types=1);

namespace App\Message;

final readonly class DiagnoseServerMessage
{
    public function __construct(
        public string $serverId,
    ) {
    }
}
