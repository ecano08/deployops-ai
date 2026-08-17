<?php

namespace App\Services;

class WebhookSignatureVerifier
{
    public const int MAX_AGE_SECONDS = 300;

    /**
     * @return array{valid: bool, reason: ?string}
     */
    public function verify(
        string $payload,
        string $secret,
        ?string $signatureHeader,
        ?string $timestampHeader,
    ): array {
        if ($timestampHeader === null || $timestampHeader === '') {
            return ['valid' => false, 'reason' => 'missing_timestamp'];
        }

        if (! ctype_digit($timestampHeader)) {
            return ['valid' => false, 'reason' => 'invalid_timestamp'];
        }

        $timestamp = (int) $timestampHeader;
        $now = time();

        if ($timestamp < ($now - self::MAX_AGE_SECONDS) || $timestamp > ($now + 60)) {
            return ['valid' => false, 'reason' => 'stale_timestamp'];
        }

        if ($signatureHeader === null || $signatureHeader === '') {
            return ['valid' => false, 'reason' => 'missing_signature'];
        }

        $signedPayload = $timestampHeader.'.'.$payload;
        $expected = hash_hmac('sha256', $signedPayload, $secret);
        $provided = str_starts_with($signatureHeader, 'sha256=')
            ? substr($signatureHeader, 7)
            : $signatureHeader;

        if (! hash_equals($expected, $provided)) {
            return ['valid' => false, 'reason' => 'invalid_signature'];
        }

        return ['valid' => true, 'reason' => null];
    }
}
