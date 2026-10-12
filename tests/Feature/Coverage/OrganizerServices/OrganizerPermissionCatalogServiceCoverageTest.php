<?php

namespace Tests\Feature\Coverage\OrganizerServices;

use App\Models\Permission;
use App\Services\Organizer\OrganizerPermissionCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrganizerPermissionCatalogServiceCoverageTest extends TestCase
{
    use RefreshDatabase;

    private OrganizerPermissionCatalogService $service;

    private ?string $tempDatabasePath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new OrganizerPermissionCatalogService();
    }

    protected function tearDown(): void
    {
        if ($this->tempDatabasePath !== null) {
            File::deleteDirectory($this->tempDatabasePath);
        }

        parent::tearDown();
    }

    #[Test]
    public function catalogRowsAreLoadedOnceAndCached(): void
    {
        $first = $this->service->getCatalogRows();
        $this->assertContains(['code' => 'events', 'available_access' => 'rwudeix'], $first);

        $this->useCatalogCsv("code,available_access\nonly-this,r\n");

        $this->assertSame($first, $this->service->getCatalogRows());
        $this->assertSame('rwudeix', $this->service->getCatalogByCode()['events']);
    }

    #[Test]
    public function catalogSkipsRowsWithoutCodeAndTrimsValues(): void
    {
        $this->useCatalogCsv("code,available_access\n,rw\n  events , wr \n\nblank-access,\n");

        $this->assertSame([
            ['code' => 'events', 'available_access' => 'wr'],
            ['code' => 'blank-access', 'available_access' => ''],
        ], $this->service->getCatalogRows());
        $this->assertSame(['events' => 'wr', 'blank-access' => ''], $this->service->getCatalogByCode());
        $this->assertSame(['r', 'w'], $this->service->allowedLettersForCode('events'));
    }

    #[Test]
    public function codesOutsideCatalogFallBackToSharedPermissionDefinition(): void
    {
        $this->useCatalogCsv("code,available_access\nevents,r\nblank-access,\n");

        Permission::create([
            'name' => 'Legacy Array',
            'code' => 'legacy-array',
            'module' => 'Legacy',
            'available_access' => ['r', 'x'],
            'role_scope' => 'shared',
        ]);
        Permission::create([
            'name' => 'Legacy String',
            'code' => 'legacy-string',
            'module' => 'Legacy',
            'available_access' => 'rw',
            'role_scope' => 'shared',
        ]);
        Permission::create([
            'name' => 'Blank Access',
            'code' => 'blank-access',
            'module' => 'Legacy',
            'available_access' => ['d'],
            'role_scope' => 'shared',
        ]);
        Permission::create([
            'name' => 'Admin Only',
            'code' => 'admin-only',
            'module' => 'Admin',
            'available_access' => ['r'],
            'role_scope' => 'admin',
        ]);

        $this->assertSame(['r', 'x'], $this->service->allowedLettersForCode('legacy-array'));
        $this->assertSame(['r', 'w'], $this->service->allowedLettersForCode('legacy-string'));
        $this->assertSame(['d'], $this->service->allowedLettersForCode('blank-access'));
        $this->assertSame([], $this->service->allowedLettersForCode('admin-only'));
        $this->assertSame([], $this->service->allowedLettersForCode('missing'));

        $this->assertTrue($this->service->isAccessAllowed('legacy-string', 'wr'));
        $this->assertFalse($this->service->isAccessAllowed('legacy-string', 'rd'));
        $this->assertFalse($this->service->isAccessAllowed('admin-only', 'r'));
        $this->assertFalse($this->service->isAccessAllowed('missing', 'r'));
    }

    #[Test]
    public function assignablePermissionsKeepStoredAccessForCodesOutsideCatalog(): void
    {
        $this->useCatalogCsv("code,available_access\nevents,xr\n");

        Permission::create([
            'name' => 'Events',
            'code' => 'events',
            'module' => 'A Module',
            'available_access' => ['r', 'w', 'x'],
            'role_scope' => 'shared',
        ]);
        Permission::create([
            'name' => 'Legacy',
            'code' => 'legacy',
            'module' => 'B Module',
            'available_access' => ['u'],
            'role_scope' => 'shared',
        ]);

        $assignable = $this->service->getAssignablePermissions();

        $this->assertSame(['events', 'legacy'], $assignable->pluck('code')->all());
        $this->assertSame(['r', 'x'], $assignable[0]->available_access);
        $this->assertSame(['u'], $assignable[1]->available_access);
        $this->assertSame(['r', 'w', 'x'], Permission::query()->where('code', 'events')->value('available_access'));
    }

    private function useCatalogCsv(string $contents): void
    {
        $this->tempDatabasePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'organizer-catalog-' . uniqid();
        File::ensureDirectoryExists($this->tempDatabasePath . DIRECTORY_SEPARATOR . 'data');
        File::put($this->tempDatabasePath . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'organizer_permissions.csv', $contents);

        $this->app->useDatabasePath($this->tempDatabasePath);
    }
}
