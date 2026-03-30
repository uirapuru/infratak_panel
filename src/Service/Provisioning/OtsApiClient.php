<?php

declare(strict_types=1);

namespace App\Service\Provisioning;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class OtsApiClient
{
    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    public function rotateAdminPassword(string $domain, string $oldPassword, string $newPassword): void
    {
        $baseUrl = sprintf('https://%s', $domain);
        $commonOptions = [
            'verify_peer' => false,
            'verify_host' => false,
            'timeout' => 20,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ];

        $loginResponse = $this->httpClient->request('GET', sprintf('%s/api/login', $baseUrl), $commonOptions);
        $loginStatus = $loginResponse->getStatusCode();
        $loginBody = $loginResponse->getContent(false);

        if ($loginStatus !== 200) {
            throw new \RuntimeException(sprintf('GET /api/login failed: status=%d body=%s', $loginStatus, $loginBody));
        }

        try {
            /** @var array{response?: array{csrf_token?: string}} $loginJson */
            $loginJson = json_decode($loginBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(sprintf('GET /api/login parse failed: %s; body=%s', $exception->getMessage(), $loginBody), previous: $exception);
        }

        $csrfToken = $loginJson['response']['csrf_token'] ?? null;
        if (!is_string($csrfToken) || $csrfToken === '') {
            throw new \RuntimeException(sprintf('GET /api/login did not return csrf_token; body=%s', $loginBody));
        }

        $cookies = $this->extractCookies($loginResponse->getHeaders(false)['set-cookie'] ?? []);

        $authResponse = $this->httpClient->request('POST', sprintf('%s/api/login', $baseUrl), [
            ...$commonOptions,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-CSRFToken' => $csrfToken,
                'Referer' => sprintf('%s/api/login', $baseUrl),
                'Cookie' => $this->buildCookieHeader($cookies),
            ],
            'json' => [
                'username' => 'administrator',
                'password' => $oldPassword,
            ],
        ]);

        $authStatus = $authResponse->getStatusCode();
        $authBody = $authResponse->getContent(false);

        if ($authStatus !== 200) {
            throw new \RuntimeException(sprintf('POST /api/login failed: status=%d body=%s', $authStatus, $authBody));
        }

        $cookies = array_merge($cookies, $this->extractCookies($authResponse->getHeaders(false)['set-cookie'] ?? []));

        $changeResponse = $this->httpClient->request('POST', sprintf('%s/api/password/change', $baseUrl), [
            ...$commonOptions,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-CSRFToken' => $csrfToken,
                'Referer' => sprintf('%s/api/password/change', $baseUrl),
                'Cookie' => $this->buildCookieHeader($cookies),
            ],
            'json' => [
                'password' => $oldPassword,
                'new_password' => $newPassword,
                'new_password_confirm' => $newPassword,
            ],
        ]);

        $changeStatus = $changeResponse->getStatusCode();
        $changeBody = $changeResponse->getContent(false);

        if ($changeStatus !== 200) {
            throw new \RuntimeException(sprintf('POST /api/password/change failed: status=%d body=%s', $changeStatus, $changeBody));
        }

        try {
            /** @var array{has_errors?: bool} $changeJson */
            $changeJson = json_decode($changeBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(sprintf('POST /api/password/change parse failed: %s; body=%s', $exception->getMessage(), $changeBody), previous: $exception);
        }

        if (($changeJson['has_errors'] ?? false) === true) {
            throw new \RuntimeException(sprintf('POST /api/password/change rejected: %s', $changeBody));
        }
    }

    /**
     * @param list<string> $setCookieHeaders
     *
     * @return array<string, string>
     */
    private function extractCookies(array $setCookieHeaders): array
    {
        $cookies = [];

        foreach ($setCookieHeaders as $header) {
            $parts = explode(';', $header);
            $nameValue = trim($parts[0] ?? '');
            if ($nameValue === '' || !str_contains($nameValue, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $nameValue, 2);
            $name = trim($name);
            if ($name === '') {
                continue;
            }

            $cookies[$name] = $value;
        }

        return $cookies;
    }

    /**
     * @param array<string, string> $cookies
     */
    private function buildCookieHeader(array $cookies): string
    {
        if ($cookies === []) {
            return '';
        }

        $pairs = [];
        foreach ($cookies as $name => $value) {
            $pairs[] = sprintf('%s=%s', $name, $value);
        }

        return implode('; ', $pairs);
    }
}
