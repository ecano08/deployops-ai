<?php

namespace App\Enums;

enum IntegrationStatus: string
{
    case Disconnected = 'disconnected';
    case Connected = 'connected';
    case Error = 'error';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
