<?php

declare(strict_types=1);

namespace App\Service\Provisioning;

interface AwsProvisioningClientInterface
{
    public function createEc2Instance(string $serverName): string;

    public function getInstancePublicIp(string $instanceId): ?string;

    public function isSsmReady(string $instanceId): bool;

    /**
     * @return array{hasIamProfile: bool, ssmManaged: bool}
     */
    public function getSsmDiagnostics(string $instanceId): array;

    public function createDnsRecords(string $domain, string $portalDomain, string $ip): void;

    public function sendProvisioningCommand(string $instanceId, string $domain, string $portalDomain): string;

    public function sendCertbotCommand(string $instanceId, string $domain, string $portalDomain): string;

    /**
     * @return array{commandId: string, status: string, output: string}
     */
    public function sendDiagnoseCommand(string $instanceId, string $domain, string $portalDomain): array;

    public function cleanupServer(string $serverName, ?string $instanceId, string $domain, string $portalDomain): void;

    public function terminateInstance(string $instanceId): void;
}
