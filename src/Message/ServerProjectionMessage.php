<?php

declare(strict_types=1);

namespace App\Message;

final class ServerProjectionMessage
{
    /**
     * @param array<string, scalar|null> $logContext
     */
    public function __construct(
        public readonly string $serverId,
        public readonly ?string $status,
        public readonly ?string $step,
        public readonly ?string $awsInstanceId,
        public readonly bool $clearAwsInstanceId,
        public readonly ?string $publicIp,
        public readonly bool $clearPublicIp,
        public readonly ?string $lastError,
        public readonly bool $clearLastError,
        public readonly string $logLevel,
        public readonly string $logMessage,
        public readonly ?\DateTimeImmutable $startedAt = null,
        public readonly ?\DateTimeImmutable $endedAt = null,
        public readonly bool $clearEndedAt = false,
        public readonly bool $clearSleepAt = false,
        public readonly array $logContext = [],
    ) {
    }
}
