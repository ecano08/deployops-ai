<?php

namespace App\Enums;

enum ProjectFactStatus: string
{
    case Proposed = 'proposed';
    case Verified = 'verified';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
