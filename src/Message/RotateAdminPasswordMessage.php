<?php

declare(strict_types=1);

namespace App\Message;

final readonly class RotateAdminPasswordMessage
{
    public function __construct(
        public string $serverId,
        public string $oldPassword,
        public string $newPassword,
        public string $origin,
    ) {
    }
}
