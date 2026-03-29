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
        private string $securityGroupId,
        private string $subnetId,
        private string $hostedZoneId,
        private ?string $instanceProfileName,
    ) {
    }

    public function createEc2Instance(string $serverName): string
    {
        if (empty($this->instanceProfileName)) {
            throw new FinalException('AWS_INSTANCE_PROFILE_NAME is required for SSM');
        }

        $runInstances = [
            'ImageId' => $this->amiId,
            'InstanceType' => $this->instanceType,
            'MinCount' => 1,
            'MaxCount' => 1,
            'SecurityGroupIds' => [$this->securityGroupId],
            'SubnetId' => $this->subnetId,
            'TagSpecifications' => [[
                'ResourceType' => 'instance',
                'Tags' => [
                    ['Key' => 'Name', 'Value' => $serverName],
                ],
            ]],
        ];

        $runInstances['IamInstanceProfile'] = ['Name' => $this->instanceProfileName];

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

    public function isSsmReady(string $instanceId): bool
    {
        $diagnostics = $this->getSsmDiagnostics($instanceId);

        return $diagnostics['ssmManaged'];
    }

    /**
     * @return array{hasIamProfile: bool, ssmManaged: bool}
     */
    public function getSsmDiagnostics(string $instanceId): array
    {
        $hasProfile = false;
        $isManaged = false;

        $ec2Result = $this->ec2->describeInstances([
            'InstanceIds' => [$instanceId],
        ]);

        $reservations = $ec2Result->get('Reservations');
        $instance = is_array($reservations) ? ($reservations[0]['Instances'][0] ?? null) : null;
        if (is_array($instance)) {
            $profileArn = $instance['IamInstanceProfile']['Arn'] ?? null;
            $hasProfile = is_string($profileArn) && $profileArn !== '';
        }

        try {
            $result = $this->ssm->describeInstanceInformation([
                'Filters' => [[
                    'Key' => 'InstanceIds',
                    'Values' => [$instanceId],
                ]],
                'MaxResults' => 5,
            ]);
        } catch (AwsException $exception) {
            if ($exception->getAwsErrorCode() === 'AccessDeniedException' || $exception->getStatusCode() === 403) {
                throw new FinalException('Missing SSM read permissions (ssm:DescribeInstanceInformation).');
            }

            throw $exception;
        }

        $items = $result->get('InstanceInformationList');
        $info = is_array($items) ? ($items[0] ?? null) : null;

        if (is_array($info)) {
            $isManaged = ($info['PingStatus'] ?? null) === 'Online';
        }

        return [
            'hasIamProfile' => $hasProfile,
            'ssmManaged' => $isManaged,
        ];
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

    public function cleanupServer(string $serverName, ?string $instanceId, string $domain, string $portalDomain): void
    {
        $this->submoduleProvisioningAssets->assertCleanupTargetAvailable();
        $this->deleteDnsRecords($domain, $portalDomain);

        $cleanupInstanceId = $instanceId;
        if ($cleanupInstanceId === null || $cleanupInstanceId === '') {
            $cleanupInstanceId = $this->findActiveInstanceIdByName($serverName);
        }

        if ($cleanupInstanceId === null) {
            return;
        }

        $this->terminateInstance($cleanupInstanceId);
    }

    public function terminateInstance(string $instanceId): void
    {
        try {
            $this->ec2->terminateInstances([
                'InstanceIds' => [$instanceId],
            ]);
        } catch (AwsException $exception) {
            if (!$this->isIgnorableTerminateException($exception)) {
                throw $exception;
            }
        }
    }

    /**
     * @param list<string> $commands
     */
    private function sendSsmCommandAndWait(string $instanceId, array $commands): string
    {
        try {
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
        } catch (AwsException $exception) {
            $errorCode = (string) ($exception->getAwsErrorCode() ?? '');
            if (str_contains($errorCode, 'InvalidInstanceId')) {
                throw new RetryableProvisioningException('SSM not ready yet');
            }

            throw new \RuntimeException(
                'SSM SendCommand failed: instance not registered in SSM (likely missing IAM role or agent not ready)',
                previous: $exception,
            );
        }

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

    private function deleteDnsRecords(string $domain, string $portalDomain): void
    {
        $changes = [];

        foreach ([$domain, $portalDomain] as $recordName) {
            $recordSet = $this->findARecordSet($recordName);
            if ($recordSet === null) {
                continue;
            }

            $changes[] = [
                'Action' => 'DELETE',
                'ResourceRecordSet' => $recordSet,
            ];
        }

        if ($changes === []) {
            return;
        }

        $this->route53->changeResourceRecordSets([
            'HostedZoneId' => $this->hostedZoneId,
            'ChangeBatch' => [
                'Changes' => $changes,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findARecordSet(string $recordName): ?array
    {
        $fqdn = sprintf('%s.', rtrim($recordName, '.'));

        $result = $this->route53->listResourceRecordSets([
            'HostedZoneId' => $this->hostedZoneId,
            'StartRecordName' => $fqdn,
            'StartRecordType' => 'A',
            'MaxItems' => '1',
        ]);

        $recordSets = $result->get('ResourceRecordSets');
        $recordSet = is_array($recordSets) ? ($recordSets[0] ?? null) : null;

        if (!is_array($recordSet)) {
            return null;
        }

        if (($recordSet['Name'] ?? null) !== $fqdn || ($recordSet['Type'] ?? null) !== 'A') {
            return null;
        }

        return $recordSet;
    }

    private function findActiveInstanceIdByName(string $serverName): ?string
    {
        $result = $this->ec2->describeInstances([
            'Filters' => [
                ['Name' => 'tag:Name', 'Values' => [$serverName]],
                ['Name' => 'instance-state-name', 'Values' => ['pending', 'running', 'stopping', 'stopped']],
            ],
        ]);

        $reservations = $result->get('Reservations');
        if (!is_array($reservations)) {
            return null;
        }

        foreach ($reservations as $reservation) {
            if (!is_array($reservation) || !isset($reservation['Instances']) || !is_array($reservation['Instances'])) {
                continue;
            }

            foreach ($reservation['Instances'] as $instance) {
                if (!is_array($instance)) {
                    continue;
                }

                $instanceId = $instance['InstanceId'] ?? null;
                if (is_string($instanceId) && $instanceId !== '') {
                    return $instanceId;
                }
            }
        }

        return null;
    }

    private function isIgnorableTerminateException(AwsException $exception): bool
    {
        return in_array($exception->getAwsErrorCode(), ['InvalidInstanceID.NotFound', 'IncorrectInstanceState'], true);
    }
}
