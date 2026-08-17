<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\IpUtils;

class IntegrationOutboundUrlValidator
{
    private const array BLOCKED_HOSTS = [
        'localhost',
        'metadata.google.internal',
        'metadata.google',
    ];

    private const array PRIVATE_IP_RANGES = [
        '0.0.0.0/8',
        '10.0.0.0/8',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '::1',
        'fc00::/7',
        'fe80::/10',
    ];

    public function isAllowed(string $url): bool
    {
        return $this->reasonIfBlocked($url) === null;
    }

    public function reasonIfBlocked(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return 'invalid_url';
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true)) {
            return 'invalid_scheme';
        }

        $host = strtolower($parts['host'] ?? '');

        if ($host === '') {
            return 'missing_host';
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'embedded_credentials';
        }

        if (in_array($host, self::BLOCKED_HOSTS, true)) {
            return 'blocked_host';
        }

        if (str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            return 'blocked_host';
        }

        if ($this->isBlockedIp($host)) {
            return 'private_network';
        }

        if (! filter_var($host, FILTER_VALIDATE_IP)) {
            $resolvedHosts = @gethostbynamel($host);

            if ($resolvedHosts === false || $resolvedHosts === []) {
                return null;
            }

            foreach ($resolvedHosts as $resolvedIp) {
                if ($this->isBlockedIp($resolvedIp)) {
                    return 'private_network';
                }
            }
        }

        return null;
    }

    public function buildSafeUrl(string $baseUrl, ?string $endpoint): ?string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $endpoint = $endpoint ?? '';

        if ($endpoint !== '' && str_contains($endpoint, '://')) {
            return null;
        }

        $url = $baseUrl.($endpoint !== '' ? '/'.ltrim($endpoint, '/') : '');

        return $this->isAllowed($url) ? $url : null;
    }

    private function isBlockedIp(string $host): bool
    {
        if (! filter_var($host, FILTER_VALIDATE_IP)) {
            return false;
        }

        return IpUtils::checkIp($host, self::PRIVATE_IP_RANGES);
    }
}
