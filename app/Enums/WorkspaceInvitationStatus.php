<?php

namespace App\Enums;

enum WorkspaceInvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
}
