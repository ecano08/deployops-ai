<?php

namespace App\Enums;

enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Engineer = 'engineer';
    case Viewer = 'viewer';

    public function canManageMembers(): bool
    {
        return $this === self::Owner || $this === self::Admin;
    }

    public function canOperate(): bool
    {
        return $this !== self::Viewer;
    }
}
