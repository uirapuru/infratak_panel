<?php

declare(strict_types=1);

namespace App\Service\Provisioning;

use Aws\Ec2\Ec2Client;
use Aws\Exception\AwsException;
use Aws\Route53\Route53Client;
use Aws\Ssm\SsmClient;

final readonly class AwsProvisioningClient implements AwsProvisioningClientInterface
{
    private const int SSM_POLL_SLEEP_SECONDS = 5;
    private const int SSM_MAX_POLLS = 60;

    public function __construct(
        private Ec2Client $ec2,
        private Route53Client $route53,
        private SsmClient $ssm,
        private SubmoduleProvisioningAssets $submoduleProvisioningAssets,
        private string $amiId,
        private string $instanceType,
        private string $hostedZoneId,
        private ?string $instanceProfileName,
    ) {
    }

    public function createEc2Instance(string $serverName): string
    {
        $runInstances = [
            'ImageId' => $this->amiId,
            'InstanceType' => $this->instanceType,
            'MinCount' => 1,
            'MaxCount' => 1,
            'TagSpecifications' => [[
                'ResourceType' => 'instance',
                'Tags' => [
                    ['Key' => 'Name', 'Value' => $serverName],
                ],
            ]],
        ];

        if ($this->instanceProfileName !== null && $this->instanceProfileName !== '') {
            $runInstances['IamInstanceProfile'] = ['Name' => $this->instanceProfileName];
        }

        $result = $this->ec2->runInstances($runInstances);
        $instances = $result->get('Instances');

        if (!is_array($instances) || !isset($instances[0]['InstanceId'])) {
            throw new \RuntimeException('EC2 instance was not created.');
        }

        return (string) $instances[0]['InstanceId'];
    }

    public function getInstancePublicIp(string $instanceId): ?string
    {
        $result = $this->ec2->describeInstances([
            'InstanceIds' => [$instanceId],
        ]);

        $reservations = $result->get('Reservations');
        $instance = $reservations[0]['Instances'][0] ?? null;

        if (!is_array($instance)) {
            return null;
        }

        $ip = $instance['PublicIpAddress'] ?? null;

        return is_string($ip) ? $ip : null;
    }

    public function createDnsRecords(string $domain, string $portalDomain, string $ip): void
    {
        $changes = [
            [
                'Action' => 'UPSERT',
                'ResourceRecordSet' => [
                    'Name' => $domain,
                    'Type' => 'A',
                    'TTL' => 60,
                    'ResourceRecords' => [['Value' => $ip]],
                ],
            ],
            [
                'Action' => 'UPSERT',
                'ResourceRecordSet' => [
                    'Name' => $portalDomain,
                    'Type' => 'A',
                    'TTL' => 60,
                    'ResourceRecords' => [['Value' => $ip]],
                ],
            ],
        ];

        $this->route53->changeResourceRecordSets([
            'HostedZoneId' => $this->hostedZoneId,
            'ChangeBatch' => [
                'Changes' => $changes,
            ],
        ]);
    }

    public function sendProvisioningCommand(string $instanceId, string $domain, string $portalDomain): string
    {
        $serverName = explode('.', $domain, 2)[0];
        $commands = $this->submoduleProvisioningAssets->buildProvisioningCommands($serverName, $domain, $portalDomain);

        return $this->sendSsmCommandAndWait($instanceId, $commands);
    }

    public function sendCertbotCommand(string $instanceId, string $domain, string $portalDomain): string
    {
        $commands = $this->submoduleProvisioningAssets->buildCertCommands($domain, $portalDomain);

        return $this->sendSsmCommandAndWait($instanceId, $commands);
    }

    public function terminateInstance(string $instanceId): void
    {
        $this->ec2->terminateInstances([
            'InstanceIds' => [$instanceId],
        ]);
    }

    /**
     * @param list<string> $commands
     */
    private function sendSsmCommandAndWait(string $instanceId, array $commands): string
    {
        $result = $this->ssm->sendCommand([
            'DocumentName' => 'AWS-RunShellScript',
            'InstanceIds' => [$instanceId],
            'Parameters' => [
                'commands' => $commands,
            ],
            'CloudWatchOutputConfig' => [
                'CloudWatchOutputEnabled' => true,
            ],
        ]);

        $command = $result->get('Command');
        $commandId = $command['CommandId'] ?? null;

        if (!is_string($commandId) || $commandId === '') {
            throw new \RuntimeException('SSM command was not accepted.');
        }

        return $this->waitForSsmCommand($instanceId, $commandId);
    }

    private function waitForSsmCommand(string $instanceId, string $commandId): string
    {
        for ($poll = 0; $poll < self::SSM_MAX_POLLS; ++$poll) {
            try {
                $invocation = $this->ssm->getCommandInvocation([
                    'CommandId' => $commandId,
                    'InstanceId' => $instanceId,
                ]);
            } catch (AwsException $exception) {
                sleep(self::SSM_POLL_SLEEP_SECONDS);

                continue;
            }

            $status = (string) ($invocation->get('Status') ?? 'Unknown');

            if ($status === 'Success') {
                return $commandId;
            }

            if (in_array($status, ['Pending', 'InProgress', 'Delayed'], true)) {
                sleep(self::SSM_POLL_SLEEP_SECONDS);

                continue;
            }

            $stderr = trim((string) ($invocation->get('StandardErrorContent') ?? ''));
            $stdout = trim((string) ($invocation->get('StandardOutputContent') ?? ''));
            $details = $stderr !== '' ? $stderr : $stdout;

            throw new \RuntimeException(sprintf('SSM command %s failed with status %s. %s', $commandId, $status, $details));
        }

        throw new \RuntimeException(sprintf('Timed out while waiting for SSM command %s.', $commandId));
    }
}
