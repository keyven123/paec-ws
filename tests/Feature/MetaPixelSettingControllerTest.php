<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Http\Controllers\MetaPixelSettingController;
use App\Models\AdminUser;
use App\Models\CmsSetting;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MetaPixelSettingControllerTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $role = Role::create(['name' => 'CMS Admin', 'code' => 'cms_admin_'.uniqid()]);
        foreach (['cms-view', 'cms-update'] as $access) {
            $permission = Permission::firstOrCreate(
                ['code' => 'cms'],
                ['name' => 'CMS', 'available_access' => ['view', 'update', 'create', 'delete']],
            );
            RolePermission::firstOrCreate([
                'role_uuid' => $role->uuid,
                'permission_uuid' => $permission->uuid,
                'access' => $access,
            ]);
        }

        $this->admin = AdminUser::create([
            'role_uuid' => $role->uuid,
            'email' => 'meta-pixel-admin@test.com',
            'password' => 'password123',
            'first_name' => 'Meta',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);
    }

    private function asAdmin()
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.auth('admin')->login($this->admin),
        ]);
    }

    #[Test]
    public function admin_can_save_and_read_project_meta_pixel_settings(): void
    {
        $this->asAdmin()
            ->putJson('/api/v1/cms/meta-pixel', [
                'enabled' => true,
                'pixel_id' => '684805839657616',
                'access_token' => 'EAAU7elLt9LIBSqEY-test-token-abcdefghijklmnopqrstuvwxyz',
                'test_event_code' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.pixel_id', '684805839657616')
            ->assertJsonPath('data.access_token_set', true)
            ->assertJsonMissingPath('data.access_token');

        $this->asAdmin()
            ->getJson('/api/v1/cms/meta-pixel')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.pixel_id', '684805839657616')
            ->assertJsonPath('data.access_token_set', true);

        $stored = CmsSetting::getValue(MetaPixelSettingController::SETTING_KEY);
        $this->assertSame('EAAU7elLt9LIBSqEY-test-token-abcdefghijklmnopqrstuvwxyz', $stored['access_token']);
    }

    #[Test]
    public function saving_without_token_keeps_the_existing_token(): void
    {
        CmsSetting::setValue(MetaPixelSettingController::SETTING_KEY, [
            'enabled' => true,
            'pixel_id' => '111',
            'access_token' => 'KEEP_ME_TOKEN',
            'test_event_code' => null,
        ]);

        $this->asAdmin()
            ->putJson('/api/v1/cms/meta-pixel', [
                'enabled' => true,
                'pixel_id' => '222',
                'access_token' => '',
            ])
            ->assertOk()
            ->assertJsonPath('data.pixel_id', '222')
            ->assertJsonPath('data.access_token_set', true);

        $this->assertSame(
            'KEEP_ME_TOKEN',
            CmsSetting::getValue(MetaPixelSettingController::SETTING_KEY)['access_token'],
        );
    }

    #[Test]
    public function public_endpoint_exposes_pixel_id_but_never_the_token(): void
    {
        CmsSetting::setValue(MetaPixelSettingController::SETTING_KEY, [
            'enabled' => true,
            'pixel_id' => '684805839657616',
            'access_token' => 'SECRET_TOKEN_SHOULD_NOT_LEAK',
            'test_event_code' => 'TESTCODE',
        ]);

        $this->getJson('/api/v1/public/cms/meta-pixel')
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.pixel_id', '684805839657616')
            ->assertJsonPath('data.test_event_code', 'TESTCODE')
            ->assertJsonMissingPath('data.access_token')
            ->assertJsonMissingPath('data.access_token_set')
            ->assertJsonMissingPath('data.access_token_masked');
    }

    #[Test]
    public function test_endpoint_posts_a_purchase_event_to_meta(): void
    {
        CmsSetting::setValue(MetaPixelSettingController::SETTING_KEY, [
            'enabled' => true,
            'pixel_id' => '684805839657616',
            'access_token' => 'EAA_TEST_TOKEN',
            'test_event_code' => 'TEST12345',
        ]);

        Http::fake([
            'https://graph.facebook.com/v18.0/684805839657616/events' => Http::response([
                'events_received' => 1,
                'fbtrace_id' => 'trace',
            ], 200),
        ]);

        $this->asAdmin()
            ->postJson('/api/v1/cms/meta-pixel/test')
            ->assertOk()
            ->assertJsonPath('success', true);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://graph.facebook.com/v18.0/684805839657616/events'
                && $request['access_token'] === 'EAA_TEST_TOKEN'
                && $request['test_event_code'] === 'TEST12345'
                && $request['data'][0]['event_name'] === 'Purchase';
        });
    }
}
