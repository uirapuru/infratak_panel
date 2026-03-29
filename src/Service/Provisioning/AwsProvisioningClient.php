<?php

declare(strict_types=1);

namespace App\Service\Provisioning;

use Aws\Ec2\Ec2Client;
use Aws\Route53\Route53Client;
use Aws\Ssm\SsmClient;

final readonly class AwsProvisioningClient implements AwsProvisioningClientInterface
{
    public function __construct(
        private Ec2Client $ec2,
        private Route53Client $route53,
        private SsmClient $ssm,
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
                    ['Key' => 'Name', 'Value' => sprintf('infratak-%s', $serverName)],
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
        $commands = [
            'set -euxo pipefail',
            'apt-get update',
            'DEBIAN_FRONTEND=noninteractive apt-get install -y nginx',
            sprintf("cat > /etc/nginx/sites-available/infratak.conf <<'EOF'\nserver {\n  listen 80;\n  server_name %s %s;\n  location / { return 200 'infratak'; add_header Content-Type text/plain; }\n}\nEOF", $domain, $portalDomain),
            'ln -sf /etc/nginx/sites-available/infratak.conf /etc/nginx/sites-enabled/infratak.conf',
            'rm -f /etc/nginx/sites-enabled/default',
            'nginx -t',
            'systemctl enable nginx',
            'systemctl restart nginx',
        ];

        return $this->sendSsmCommand($instanceId, $commands);
    }

    public function sendCertbotCommand(string $instanceId, string $domain, string $portalDomain): string
    {
        $commands = [
            'set -euxo pipefail',
            'apt-get update',
            'DEBIAN_FRONTEND=noninteractive apt-get install -y certbot python3-certbot-nginx',
            sprintf('certbot --nginx -d %s -d %s --non-interactive --agree-tos -m admin@calbal.net', $domain, $portalDomain),
            'systemctl reload nginx',
        ];

        return $this->sendSsmCommand($instanceId, $commands);
    }

    public function terminateInstance(string $instanceId): void
    {
        $this->ec2->terminateInstances([
            'InstanceIds' => [$instanceId],
        ]);
    }

    private function sendSsmCommand(string $instanceId, array $commands): string
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

        return $commandId;
    }
}
