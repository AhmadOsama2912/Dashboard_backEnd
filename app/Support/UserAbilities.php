<?php

namespace App\Support;

final class UserAbilities
{
    // Read access
    public const DASHBOARD_READ = 'user:dashboard:read';
    public const SCREENS_READ   = 'user:screens:read';

    // Screens actions
    public const SCREENS_ASSIGN    = 'user:screens:assign';
    public const SCREENS_BROADCAST = 'user:screens:broadcast';

    // Supervisor management
    public const USER_MANAGE = 'user:manage';

    // Company CMS
    public const PLAYLIST_WRITE = 'user:playlist:write';

    public static function managerDefaults(): array
    {
        return [
            self::DASHBOARD_READ,
            self::SCREENS_READ,
            self::SCREENS_ASSIGN,
            self::SCREENS_BROADCAST,
            self::PLAYLIST_WRITE,
            self::USER_MANAGE,
        ];
    }

    public static function supervisorDefaults(): array
    {
        return [
            self::DASHBOARD_READ,
            self::SCREENS_READ,
            self::SCREENS_ASSIGN,
            self::SCREENS_BROADCAST,
        ];
    }
}
