<?php

declare(strict_types=1);

namespace App\Service\Monitoring;

final class WorkerHealthService
{
    public function __construct(
        private readonly string $provisioningDsn,
        private readonly string $projectionDsn,
    ) {
    }

    /**
     * @return array{provisioning: array<string, mixed>, projection: array<string, mixed>}
     */
    public function getWorkersStatus(): array
    {
        return [
            'provisioning' => $this->getQueueStatus($this->provisioningDsn, 'infratak.provisioning'),
            'projection' => $this->getQueueStatus($this->projectionDsn, 'infratak.projection'),
        ];
    }

    /**
     * @return array{isRunning: bool, consumers: int, stateLabel: string, stateColor: string, details: string}
     */
    private function getQueueStatus(string $dsn, string $queueName): array
    {
        $connection = $this->parseRabbitConnection($dsn);

        if ($connection === null) {
            return [
                'isRunning' => false,
                'consumers' => 0,
                'stateLabel' => 'Unknown',
                'stateColor' => '#64748b',
                'details' => 'Unable to parse transport DSN.',
            ];
        }

        $url = sprintf(
            'http://%s:%d/api/queues/%s/%s',
            $connection['host'],
            $connection['managementPort'],
            rawurlencode($connection['vhost']),
            rawurlencode($queueName)
        );

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 2,
                'header' => sprintf(
                    "Authorization: Basic %s\r\nAccept: application/json\r\n",
                    base64_encode(sprintf('%s:%s', $connection['user'], $connection['password']))
                ),
            ],
        ]);

        $response = @file_get_contents($url, false, $context);

        if ($response === false) {
            return [
                'isRunning' => false,
                'consumers' => 0,
                'stateLabel' => 'Unknown',
                'stateColor' => '#64748b',
                'details' => 'RabbitMQ Management API is unreachable.',
            ];
        }

        $payload = json_decode($response, true);

        if (!is_array($payload)) {
            return [
                'isRunning' => false,
                'consumers' => 0,
                'stateLabel' => 'Unknown',
                'stateColor' => '#64748b',
                'details' => 'Invalid API response.',
            ];
        }

        $consumers = (int) ($payload['consumers'] ?? 0);
        $isRunning = $consumers > 0;

        return [
            'isRunning' => $isRunning,
            'consumers' => $consumers,
            'stateLabel' => $isRunning ? 'Running' : 'Stopped',
            'stateColor' => $isRunning ? '#16a34a' : '#dc2626',
            'details' => sprintf('Consumers: %d', $consumers),
        ];
    }

    /**
     * @return array{host: string, managementPort: int, user: string, password: string, vhost: string}|null
     */
    private function parseRabbitConnection(string $dsn): ?array
    {
        $parts = parse_url($dsn);

        if (!is_array($parts)) {
            return null;
        }

        $host = $parts['host'] ?? null;
        $user = $parts['user'] ?? null;
        $password = $parts['pass'] ?? null;
        $path = $parts['path'] ?? '';

        if (!is_string($host) || !is_string($user) || !is_string($password)) {
            return null;
        }

        $segments = explode('/', ltrim($path, '/'));
        $encodedVhost = $segments[0] ?? '%2f';
        $vhost = rawurldecode($encodedVhost);

        return [
            'host' => $host,
            'managementPort' => 15672,
            'user' => rawurldecode($user),
            'password' => rawurldecode($password),
            'vhost' => $vhost !== '' ? $vhost : '/',
        ];
    }
}