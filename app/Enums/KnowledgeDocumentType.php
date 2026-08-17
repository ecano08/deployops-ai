<?php

namespace App\Enums;

enum KnowledgeDocumentType: string
{
    case Architecture = 'architecture';
    case BusinessRules = 'business_rules';
    case Database = 'database';
    case Authorization = 'authorization';
    case Integrations = 'integrations';
    case Workflows = 'workflows';
    case TechnicalDecision = 'technical_decision';
    case Security = 'security';
    case Conventions = 'conventions';
    case Operations = 'operations';
    case KnownBugs = 'known_bugs';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
