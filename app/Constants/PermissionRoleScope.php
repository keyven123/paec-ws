<?php

namespace App\Constants;

final class PermissionRoleScope
{
    public const ADMIN = 'admin';

    public const ORGANIZER = 'organizer';

    public const SHARED = 'shared';

    /**
     * @return list<string>
     */
    public static function allowedForAdminRole(): array
    {
        return [self::ADMIN, self::SHARED];
    }

    /**
     * @return list<string>
     */
    public static function allowedForOrganizerRole(): array
    {
        return [self::ORGANIZER, self::SHARED];
    }

    /**
     * @return list<string>
     */
    public static function allowedForRole(bool $isAdmin): array
    {
        return $isAdmin ? self::allowedForAdminRole() : self::allowedForOrganizerRole();
    }
}
