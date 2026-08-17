<?php

namespace App\Enums;

enum IntegrationType: string
{
    case RestApi = 'rest_api';
    case Webhook = 'webhook';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
