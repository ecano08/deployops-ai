<?php

namespace App\Services;

class CopilotQuestionRedactor
{
    /**
     * @var array<int, string>
     */
    private const array PATTERNS = [
        '/\bsk-[a-zA-Z0-9]{8,}/',
        '/\bwhsec_[a-zA-Z0-9]+/',
        '/\bBearer\s+[a-zA-Z0-9._-]+/i',
        '/(?i)(api[_-]?key|token|password|secret|authorization)\s*[:=]\s*\S+/',
    ];

    public function redact(string $question): string
    {
        $redacted = $question;

        foreach (self::PATTERNS as $pattern) {
            $redacted = preg_replace($pattern, '[REDACTED]', $redacted) ?? $redacted;
        }

        return mb_substr($redacted, 0, 500);
    }
}
