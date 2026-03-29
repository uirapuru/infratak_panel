<?php

declare(strict_types=1);

namespace App\Enum;

enum ServerStep: string
{
    case EC2 = 'ec2';
    case WAIT_IP = 'wait_ip';
    case DNS = 'dns';
    case WAIT_DNS = 'wait_dns';
    case PROVISION = 'provision';
    case CERT = 'cert';
}
