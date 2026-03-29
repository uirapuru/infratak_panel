<?php

declare(strict_types=1);

namespace App\Service\Provisioning;

use App\Entity\Server;
use App\Enum\ServerStatus;
use App\Enum\ServerStep;
use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;

#[WithMonologChannel('worker_provisioning')]
final readonly class ProvisioningOrchestrator
{
    public function __construct(
        private AwsProvisioningClientInterface $aws,
        private LoggerInterface $logger,
    ) {
    }

    public function advance(Server $server): bool
    {
        return match ($server->getStep()) {
            ServerStep::NONE => true,
            ServerStep::EC2 => $this->handleEc2Step($server),
            ServerStep::WAIT_IP => $this->handleWaitIpStep($server),
            ServerStep::DNS => $this->handleDnsStep($server),
            ServerStep::WAIT_DNS => $this->handleWaitDnsStep($server),
            ServerStep::WAIT_SSM => $this->handleWaitSsmStep($server),
            ServerStep::PROVISION => $this->handleProvisionStep($server),
            ServerStep::CERT => $this->handleCertStep($server),
            ServerStep::CLEANUP => true,
        };
    }

    private function handleEc2Step(Server $server): bool
    {
        $this->logger->info('Provisioning step start', ['step' => 'ec2', 'serverId' => $server->getId()]);

        if ($server->getStartedAt() === null) {
            $server->setStartedAt(new \DateTimeImmutable());
        }

        $server->setEndedAt(null);

        if ($server->getAwsInstanceId() === null) {
            $instanceId = $this->aws->createEc2Instance($server->getName());
            $server->setAwsInstanceId($instanceId);
        }

        $server->setStatus(ServerStatus::PROVISIONING);
        $server->setStep(ServerStep::WAIT_IP);

        $this->logger->info('Provisioning step end', ['step' => 'ec2', 'serverId' => $server->getId()]);

        return false;
    }

    private function handleWaitIpStep(Server $server): bool
    {
        $this->logger->info('Provisioning step start', ['step' => 'wait_ip', 'serverId' => $server->getId()]);

        $instanceId = $server->getAwsInstanceId();
        if ($instanceId === null) {
            throw new RetryableProvisioningException('Missing instance id while waiting for IP.');
        }

        $publicIp = $this->aws->getInstancePublicIp($instanceId);
        if ($publicIp === null) {
            throw new RetryableProvisioningException('Public IP not assigned yet.');
        }

        $server->setPublicIp($publicIp);
        $server->setStep(ServerStep::DNS);

        $this->logger->info('Provisioning step end', ['step' => 'wait_ip', 'serverId' => $server->getId(), 'publicIp' => $publicIp]);

        return false;
    }

    private function handleDnsStep(Server $server): bool
    {
        $this->logger->info('Provisioning step start', ['step' => 'dns', 'serverId' => $server->getId()]);

        $publicIp = $server->getPublicIp();
        if ($publicIp === null) {
            throw new RetryableProvisioningException('Cannot create DNS records without public IP.');
        }

        $this->aws->createDnsRecords($server->getDomain(), $server->getPortalDomain(), $publicIp);
        $server->setStatus(ServerStatus::CERT_PENDING);
        $server->setStep(ServerStep::WAIT_DNS);

        $this->logger->info('Provisioning step end', ['step' => 'dns', 'serverId' => $server->getId()]);

        return false;
    }

    private function handleWaitDnsStep(Server $server): bool
    {
        $this->logger->info('Provisioning step start', ['step' => 'wait_dns', 'serverId' => $server->getId()]);

        if (!$this->isDnsReady($server->getDomain()) || !$this->isDnsReady($server->getPortalDomain())) {
            throw new RetryableProvisioningException('DNS propagation still pending.');
        }

        $server->setStep(ServerStep::WAIT_SSM);

        $this->logger->info('Provisioning step end', ['step' => 'wait_dns', 'serverId' => $server->getId()]);

        return false;
    }

    private function handleWaitSsmStep(Server $server): bool
    {
        $this->logger->info('Provisioning step start', ['step' => 'wait_ssm', 'serverId' => $server->getId()]);

        $instanceId = $server->getAwsInstanceId();
        if ($instanceId === null) {
            throw new RetryableProvisioningException('Missing instance id while waiting for SSM readiness.');
        }

        $diagnostics = $this->aws->getSsmDiagnostics($instanceId);
        $this->logger->info('SSM readiness diagnostics', [
            'instanceId' => $instanceId,
            'hasIamProfile' => $diagnostics['hasIamProfile'],
            'ssmManaged' => $diagnostics['ssmManaged'],
        ]);

        if (!$diagnostics['ssmManaged']) {
            throw new RetryableProvisioningException('SSM agent is not ready yet.');
        }

        $server->setStep(ServerStep::PROVISION);

        $this->logger->info('Provisioning step end', ['step' => 'wait_ssm', 'serverId' => $server->getId()]);

        return false;
    }

    private function handleProvisionStep(Server $server): bool
    {
        $this->logger->info('Provisioning step start', ['step' => 'provision', 'serverId' => $server->getId()]);

        $instanceId = $server->getAwsInstanceId();
        if ($instanceId === null) {
            throw new RetryableProvisioningException('Missing instance id for provisioning.');
        }

        $commandId = $this->aws->sendProvisioningCommand($instanceId, $server->getDomain(), $server->getPortalDomain());
        $server->setStep(ServerStep::CERT);

        $this->logger->info('Provisioning step end', ['step' => 'provision', 'serverId' => $server->getId(), 'ssmCommandId' => $commandId]);

        return false;
    }

    private function handleCertStep(Server $server): bool
    {
        $this->logger->info('Provisioning step start', ['step' => 'cert', 'serverId' => $server->getId()]);

        $instanceId = $server->getAwsInstanceId();
        if ($instanceId === null) {
            throw new RetryableProvisioningException('Missing instance id for certbot step.');
        }

        $commandId = $this->aws->sendCertbotCommand($instanceId, $server->getDomain(), $server->getPortalDomain());

        $server->setStatus(ServerStatus::READY);
        $server->setStep(ServerStep::NONE);

        $this->logger->info('Provisioning step end', ['step' => 'cert', 'serverId' => $server->getId(), 'ssmCommandId' => $commandId]);

        return true;
    }

    private function isDnsReady(string $host): bool
    {
        return checkdnsrr($host, 'A');
    }
}
