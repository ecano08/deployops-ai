<?php

namespace App\Enums;

enum GroundedContextKind: string
{
    case Documented = 'documented';
    case VerifiedFact = 'verified_fact';
    case Inferred = 'inferred';
    case Unknown = 'unknown';
    case Conflicting = 'conflicting';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
