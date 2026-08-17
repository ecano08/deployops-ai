<?php

namespace App\Enums;

enum AiActionAuditEventType: string
{
    case Proposed = 'proposed';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Executed = 'executed';
    case Failed = 'failed';
}
