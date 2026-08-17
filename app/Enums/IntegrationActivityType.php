<?php

namespace App\Enums;

enum IntegrationActivityType: string
{
    case TestConnection = 'test_connection';
    case WebhookReceived = 'webhook_received';
    case Error = 'error';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
