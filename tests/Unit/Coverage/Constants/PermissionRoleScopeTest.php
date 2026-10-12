<?php

namespace Tests\Unit\Coverage\Constants;

use App\Constants\PermissionRoleScope;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class PermissionRoleScopeTest extends TestCase
{
    #[Test]
    public function adminRolesSeeAdminAndSharedPermissions(): void
    {
        $this->assertSame(['admin', 'shared'], PermissionRoleScope::allowedForAdminRole());
        $this->assertSame(PermissionRoleScope::allowedForAdminRole(), PermissionRoleScope::allowedForRole(true));
    }

    #[Test]
    public function organizerRolesSeeOrganizerAndSharedPermissions(): void
    {
        $this->assertSame(['organizer', 'shared'], PermissionRoleScope::allowedForOrganizerRole());
        $this->assertSame(PermissionRoleScope::allowedForOrganizerRole(), PermissionRoleScope::allowedForRole(false));
    }

    #[Test]
    public function noRoleCanSeeTheOtherSidesExclusivePermissions(): void
    {
        $this->assertNotContains(PermissionRoleScope::ORGANIZER, PermissionRoleScope::allowedForRole(true));
        $this->assertNotContains(PermissionRoleScope::ADMIN, PermissionRoleScope::allowedForRole(false));
    }
}
