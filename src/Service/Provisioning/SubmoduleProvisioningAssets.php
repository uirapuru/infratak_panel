<?php

declare(strict_types=1);

namespace App\Service\Provisioning;

final readonly class SubmoduleProvisioningAssets
{
    public function __construct(
        private string $projectDir,
        private string $provisioningSubmodulePath,
    ) {
    }

    /**
     * @return list<string>
     */
    public function buildProvisioningCommands(string $serverName, string $domain, string $portalDomain): array
    {
        $commands = [
            'set -euxo pipefail',
            'install -d -m 0755 /home/ubuntu/nginx',
            $this->buildHereDocCommand('/home/ubuntu/provisioning.sh', $this->getProvisioningScript()),
        ];

        foreach ($this->getRenderedNginxTemplates($domain, $portalDomain) as $fileName => $contents) {
            $commands[] = $this->buildHereDocCommand(sprintf('/home/ubuntu/nginx/%s', $fileName), $contents);
        }

        $commands[] = 'chmod +x /home/ubuntu/provisioning.sh';
        $commands[] = sprintf('bash -euxo pipefail /home/ubuntu/provisioning.sh %s', escapeshellarg($serverName));

        return $commands;
    }

    /**
     * @return list<string>
     */
    public function buildCertCommands(string $domain, string $portalDomain): array
    {
        return [
            'set -euxo pipefail',
            sprintf('certbot --nginx -d %s -d %s --non-interactive --agree-tos -m admin@calbal.net', $domain, $portalDomain),
            sprintf(
                'sed -i %s /etc/nginx/sites-available/ots_https /etc/nginx/sites-available/ots_certificate_enrollment',
                escapeshellarg(sprintf(
                    's|/etc/ssl/bootstrap/%1$s/fullchain.pem|/etc/letsencrypt/live/%1$s/fullchain.pem|g; s|/etc/ssl/bootstrap/%1$s/privkey.pem|/etc/letsencrypt/live/%1$s/privkey.pem|g',
                    $domain,
                )),
            ),
            sprintf(
                'sed -i %s /etc/nginx/sites-available/portal',
                escapeshellarg(sprintf(
                    's|/etc/ssl/bootstrap/%1$s/fullchain.pem|/etc/letsencrypt/live/%2$s/fullchain.pem|g; s|/etc/ssl/bootstrap/%1$s/privkey.pem|/etc/letsencrypt/live/%2$s/privkey.pem|g',
                    $portalDomain,
                    $domain,
                )),
            ),
            'nginx -t',
            'systemctl reload nginx',
        ];
    }

    public function assertCleanupTargetAvailable(): void
    {
        $makefileContents = $this->getMakefileContents();

        if (!preg_match('/^cleanup:\s+dns-delete\s+terminate\s*$/m', $makefileContents)) {
            throw new \RuntimeException('Provisioning submodule cleanup target is missing or has changed unexpectedly.');
        }
    }

    private function getProvisioningScript(): string
    {
        $scriptPath = sprintf('%s/%s/provisioning.sh', $this->projectDir, $this->provisioningSubmodulePath);
        $contents = @file_get_contents($scriptPath);

        if ($contents === false) {
            throw new \RuntimeException(sprintf('Unable to read provisioning script from submodule path: %s', $scriptPath));
        }

        return $contents;
    }

    private function getMakefileContents(): string
    {
        $makefilePath = sprintf('%s/%s/Makefile', $this->projectDir, $this->provisioningSubmodulePath);
        $contents = @file_get_contents($makefilePath);

        if ($contents === false) {
            throw new \RuntimeException(sprintf('Unable to read provisioning Makefile from submodule path: %s', $makefilePath));
        }

        return $contents;
    }

    /**
     * @return array<string, string>
     */
    private function getRenderedNginxTemplates(string $domain, string $portalDomain): array
    {
        $nginxDir = sprintf('%s/%s/nginx', $this->projectDir, $this->provisioningSubmodulePath);
        $templatePaths = glob(sprintf('%s/*', $nginxDir));

        if ($templatePaths === false || $templatePaths === []) {
            throw new \RuntimeException(sprintf('No nginx templates found in provisioning submodule path: %s', $nginxDir));
        }

        $rendered = [];

        foreach ($templatePaths as $templatePath) {
            if (!is_file($templatePath)) {
                continue;
            }

            $contents = @file_get_contents($templatePath);
            if ($contents === false) {
                throw new \RuntimeException(sprintf('Unable to read nginx template from submodule path: %s', $templatePath));
            }

            $rendered[basename($templatePath)] = str_replace(
                ['__DOMAIN__', '__PORTAL_DOMAIN__'],
                [$domain, $portalDomain],
                $contents,
            );
        }

        return $rendered;
    }

    private function buildHereDocCommand(string $targetPath, string $contents): string
    {
        $delimiter = 'INFRATAK_EOF';

        return sprintf("cat > %s <<'%s'\n%s\n%s", $targetPath, $delimiter, $contents, $delimiter);
    }
}
