<?php

declare(strict_types=1);

namespace App\Service\Aws;

use Aws\Sdk;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class AwsSdkFactory
{
    public function __construct(
        #[Autowire(service: 'monolog.logger.aws')]
        private LoggerInterface $awsLogger,
        #[Autowire(param: 'aws.region')]
        private string $region,
    ) {
    }

    public function create(): Sdk
    {
        $awsLogger = $this->awsLogger;

        return new Sdk([
            'version' => 'latest',
            'region' => $this->region,
            'debug' => [
                'logfn' => static function (string $msg) use ($awsLogger): void {
                    $awsLogger->debug($msg);
                },
            ],
        ]);
    }
}
