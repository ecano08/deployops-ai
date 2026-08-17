<?php

namespace App\Support;

class ResourceSanitizer
{
    /**
     * @param  array<string, mixed>|null  $config
     * @return array<string, mixed>|null
     */
    public static function integrationConfig(?array $config): ?array
    {
        if ($config === null) {
            return null;
        }

        $denyList = [
            'api_key',
            'webhook_secret',
            'secret',
            'token',
            'password',
            'authorization',
            'bearer',
        ];

        $safe = [];

        foreach ($config as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if (in_array($normalizedKey, $denyList, true)) {
                continue;
            }

            if (is_array($value)) {
                $safe[$key] = self::integrationConfig($value);
            } else {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>|null
     */
    public static function metadata(?array $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }

        $denyList = [
            'api_key',
            'webhook_secret',
            'secret',
            'token',
            'password',
            'authorization',
            'authorization_header',
            'bearer',
        ];

        $safe = [];

        foreach ($metadata as $key => $value) {
            $normalizedKey = strtolower((string) $key);

            if (in_array($normalizedKey, $denyList, true)) {
                continue;
            }

            if (is_array($value)) {
                $safe[$key] = self::metadata($value);
            } elseif (is_string($value) && strlen($value) > 500) {
                $safe[$key] = substr($value, 0, 500).'…';
            } else {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }
}
