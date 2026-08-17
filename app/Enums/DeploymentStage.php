<?php

namespace App\Enums;

enum DeploymentStage: string
{
    case Discovery = 'discovery';
    case Integration = 'integration';
    case Build = 'build';
    case Validation = 'validation';
    case Deployed = 'deployed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
