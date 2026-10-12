<?php

namespace Tests\Feature\Coverage\PlatformServices;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\OrganizationPlatformCom;
use App\Models\Role;
use App\Services\Platform\OrganizationPlatformComService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrganizationPlatformComServiceCoverageTest extends TestCase
{
    use PlatformFixtures;
    use RefreshDatabase;

    private function service(): OrganizationPlatformComService
    {
        return $this->app->make(OrganizationPlatformComService::class);
    }

    #[Test]
    public function twoNullValuesDoNotDiffer(): void
    {
        $this->assertFalse($this->service()->commissionValuesDiffer(null, null));
    }

    #[Test]
    public function aNullAndANumberDiffer(): void
    {
        $this->assertTrue($this->service()->commissionValuesDiffer(null, 5.0));
        $this->assertTrue($this->service()->commissionValuesDiffer(5.0, null));
    }

    #[Test]
    public function valuesAreComparedAtTwoDecimals(): void
    {
        $this->assertFalse($this->service()->commissionValuesDiffer(10.001, 10.004));
        $this->assertTrue($this->service()->commissionValuesDiffer(10.0, 10.5));
    }

    #[Test]
    public function unchangedCommissionIsNotLogged(): void
    {
        $this->assertNull($this->service()->logCommissionChange(8.0, 8.0));
        $this->assertSame(0, OrganizationPlatformCom::count());
    }

    #[Test]
    public function changedCommissionIsLoggedRoundedAndAttributedToTheAdmin(): void
    {
        $role = Role::create(['name' => 'Admin', 'code' => GeneralConstants::ROLES['ADMIN']['name'], 'is_admin' => true]);
        $admin = AdminUser::create([
            'role_uuid' => $role->uuid,
            'email' => 'platform-com@test.com',
            'password' => 'password123',
            'first_name' => 'Admin',
            'last_name' => 'User',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
        auth('admin')->setUser($admin);
        $org = $this->makeOrg('Logged Org');

        $row = $this->service()->logCommissionChange(null, 12.345, $org->uuid);

        $this->assertNotNull($row);
        $this->assertDatabaseHas('organization_platform_coms', [
            'organization_uuid' => $org->uuid,
            'previous_coms' => null,
            'created_by' => $admin->uuid,
        ]);
        $this->assertEquals(12.35, (float) $row->fresh()->current_coms);
    }

    #[Test]
    public function platformDefaultChangeWithoutActorIsLoggedWithoutCreator(): void
    {
        $row = $this->service()->logCommissionChange(10.0, 11.0);

        $this->assertNotNull($row);
        $this->assertDatabaseHas('organization_platform_coms', [
            'organization_uuid' => null,
            'created_by' => null,
        ]);
        $this->assertEquals(10.0, (float) $row->fresh()->previous_coms);
    }
}
