<?php

declare(strict_types=1);

namespace App\Enum;

enum ServerStatus: string
{
    case CREATING = 'creating';
    case PROVISIONING = 'provisioning';
    case CERT_PENDING = 'cert_pending';
    case READY = 'ready';
    case FAILED = 'failed';
    case DELETED = 'deleted';
    case STOPPED = 'stopped';
}
