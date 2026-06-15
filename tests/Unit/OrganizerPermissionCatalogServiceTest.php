<?php

namespace Tests\Unit;

use App\Models\Permission;
use App\Services\Organizer\OrganizerPermissionCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizerPermissionCatalogServiceTest extends TestCase
{
    use RefreshDatabase;

    private OrganizerPermissionCatalogService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(OrganizerPermissionCatalogService::class);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itLoadsCatalogRowsFromOrganizerPermissionsCsv(): void
    {
        $rows = $this->service->getCatalogRows();

        $this->assertNotEmpty($rows);

        $codes = collect($rows)->pluck('code')->all();
        $this->assertContains('organizer-dashboard', $codes);
        $this->assertContains('events', $codes);
        $this->assertContains('categories', $codes);
        $this->assertContains('roles', $codes);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itIdentifiesAllowedCodesAndAccessLetters(): void
    {
        $this->assertTrue($this->service->isCodeAllowed('events'));
        $this->assertTrue($this->service->isAccessAllowed('events', 'r'));
        $this->assertTrue($this->service->isAccessAllowed('events', 'rw'));
        $this->assertFalse($this->service->isCodeAllowed('dashboard'));
        $this->assertFalse($this->service->isAccessAllowed('categories', 'w'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itOrdersCatalogAccessLettersConsistently(): void
    {
        $this->assertSame(['r', 'w', 'u', 'd', 'e', 'i', 'x'], $this->service->allowedLettersForCode('events'));
        $this->assertSame(['r'], $this->service->allowedLettersForCode('categories'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsOnlyCatalogPermissionsWithCappedAvailableAccess(): void
    {
        Permission::create([
            'name' => 'Events',
            'code' => 'events',
            'module' => 'Activities Module',
            'available_access' => ['r', 'w', 'u', 'd', 'e', 'i'],
            'role_scope' => 'shared',
        ]);

        Permission::create([
            'name' => 'Categories',
            'code' => 'categories',
            'module' => 'Other Module',
            'available_access' => ['r', 'w', 'u', 'd', 'x'],
            'role_scope' => 'shared',
        ]);

        Permission::create([
            'name' => 'Dashboard',
            'code' => 'dashboard',
            'module' => 'Dashboard Module',
            'available_access' => ['r', 'x'],
            'role_scope' => 'admin',
        ]);

        $assignable = $this->service->getAssignablePermissions();
        $codes = $assignable->pluck('code')->all();

        $this->assertContains('events', $codes);
        $this->assertContains('categories', $codes);
        $this->assertNotContains('dashboard', $codes);

        $events = $assignable->firstWhere('code', 'events');
        $categories = $assignable->firstWhere('code', 'categories');

        $this->assertSame(['r', 'w', 'u', 'd', 'e', 'i', 'x'], $events->available_access);
        $this->assertSame(['r'], $categories->available_access);
    }
}
