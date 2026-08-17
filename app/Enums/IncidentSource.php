<?php

namespace App\Enums;

enum IncidentSource: string
{
    case AiFailure = 'ai_failure';
    case IntegrationFailure = 'integration_failure';
    case Manual = 'manual';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
