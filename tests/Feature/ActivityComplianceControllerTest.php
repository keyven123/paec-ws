<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\ActivityCompliance;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class ActivityComplianceControllerTest extends TestCase
{
    use RefreshDatabase;

    private AdminUser $admin;

    private string $token;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create([
            'name' => 'Admin',
            'code' => GeneralConstants::ROLES['ADMIN']['name'],
        ]);

        $permission = Permission::create([
            'name' => 'Activity Compliance',
            'code' => 'activity-compliances',
            'available_access' => ['view', 'create', 'update', 'delete'],
        ]);

        foreach (['view', 'create', 'update', 'delete'] as $access) {
            RolePermission::create([
                'role_uuid' => $role->uuid,
                'permission_uuid' => $permission->uuid,
                'access' => 'activity-compliances-'.$access,
            ]);
        }

        $this->admin = AdminUser::create([
            'role_uuid' => $role->uuid,
            'email' => 'compliance-admin@test.com',
            'password' => 'password123',
            'first_name' => 'Compliance',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $this->token = JWTAuth::fromUser($this->admin);
        $this->organization = Organization::factory()->create();
    }

    public function test_it_lists_events_with_activity_compliances_for_organization(): void
    {
        $event = Event::factory()->create(['organization_uuid' => $this->organization->uuid]);
        ActivityCompliance::query()->create([
            'activityable_type' => 'event',
            'activityable_id' => $event->uuid,
            'label' => 'VAT',
            'percentage' => 12,
            'amount_type' => 'percentage',
            'status' => GeneralConstants::GENERAL_STATUSES['INACTIVE'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->getJson('/api/v1/activity-compliances/organizations/'.$this->organization->uuid);

        $response->assertOk()
            ->assertJsonPath('data.0.uuid', $event->uuid)
            ->assertJsonPath('data.0.activity_compliances.0.label', 'VAT');
    }

    public function test_it_toggles_compliance_status(): void
    {
        $event = Event::factory()->create(['organization_uuid' => $this->organization->uuid]);
        $compliance = ActivityCompliance::query()->create([
            'activityable_type' => 'event',
            'activityable_id' => $event->uuid,
            'label' => 'VAT',
            'percentage' => 12,
            'amount_type' => 'percentage',
            'status' => GeneralConstants::GENERAL_STATUSES['INACTIVE'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->patchJson('/api/v1/activity-compliances/'.$compliance->uuid, [
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', GeneralConstants::GENERAL_STATUSES['ACTIVE']);

        $this->assertDatabaseHas('activity_compliances', [
            'uuid' => $compliance->uuid,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
    }

    public function test_it_updates_compliance_label_and_percentage(): void
    {
        $event = Event::withoutEvents(function () {
            return Event::factory()->create(['organization_uuid' => $this->organization->uuid]);
        });

        $compliance = ActivityCompliance::query()->create([
            'activityable_type' => 'event',
            'activityable_id' => $event->uuid,
            'label' => 'VAT',
            'percentage' => 12,
            'amount_type' => 'percentage',
            'status' => GeneralConstants::GENERAL_STATUSES['INACTIVE'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->patchJson('/api/v1/activity-compliances/'.$compliance->uuid, [
            'label' => 'Value Added Tax',
            'percentage' => 10,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.label', 'Value Added Tax')
            ->assertJsonPath('data.percentage', 10);

        $this->assertDatabaseHas('activity_compliances', [
            'uuid' => $compliance->uuid,
            'label' => 'Value Added Tax',
            'percentage' => 10,
        ]);
    }

    public function test_it_creates_activity_compliance_for_event(): void
    {
        $event = Event::withoutEvents(function () {
            return Event::factory()->create(['organization_uuid' => $this->organization->uuid]);
        });

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->postJson('/api/v1/activity-compliances', [
            'organization_uuid' => $this->organization->uuid,
            'event_uuid' => $event->uuid,
            'label' => 'VAT',
            'percentage' => 12,
            'amount_type' => 'percentage',
            'status' => GeneralConstants::GENERAL_STATUSES['INACTIVE'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.label', 'VAT')
            ->assertJsonPath('data.percentage', 12)
            ->assertJsonPath('data.activityable_id', $event->uuid)
            ->assertJsonPath('data.activityable_type', 'event');

        $this->assertDatabaseHas('activity_compliances', [
            'activityable_id' => $event->uuid,
            'label' => 'VAT',
            'percentage' => 12,
        ]);
    }

    public function test_it_rejects_duplicate_label_for_same_event(): void
    {
        $event = Event::withoutEvents(function () {
            return Event::factory()->create(['organization_uuid' => $this->organization->uuid]);
        });

        ActivityCompliance::query()->create([
            'activityable_type' => 'event',
            'activityable_id' => $event->uuid,
            'label' => 'VAT',
            'percentage' => 12,
            'amount_type' => 'percentage',
            'status' => GeneralConstants::GENERAL_STATUSES['INACTIVE'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->postJson('/api/v1/activity-compliances', [
            'organization_uuid' => $this->organization->uuid,
            'event_uuid' => $event->uuid,
            'label' => 'VAT',
            'percentage' => 5,
            'amount_type' => 'percentage',
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['label']);
    }

    public function test_it_rejects_create_when_active_percentages_exceed_100(): void
    {
        $event = Event::withoutEvents(function () {
            return Event::factory()->create(['organization_uuid' => $this->organization->uuid]);
        });

        ActivityCompliance::query()->create([
            'activityable_type' => 'event',
            'activityable_id' => $event->uuid,
            'label' => 'Existing',
            'percentage' => 80,
            'amount_type' => 'percentage',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->postJson('/api/v1/activity-compliances', [
            'organization_uuid' => $this->organization->uuid,
            'event_uuid' => $event->uuid,
            'label' => 'New fee',
            'percentage' => 30,
            'amount_type' => 'percentage',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['percentage']);
    }

    public function test_it_deletes_unused_compliance_via_api(): void
    {
        $event = Event::withoutEvents(function () {
            return Event::factory()->create(['organization_uuid' => $this->organization->uuid]);
        });
        $compliance = ActivityCompliance::query()->create([
            'activityable_type' => 'event',
            'activityable_id' => $event->uuid,
            'label' => 'Service Charge',
            'percentage' => 0,
            'amount_type' => 'percentage',
            'status' => GeneralConstants::GENERAL_STATUSES['INACTIVE'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->delete('/api/v1/activity-compliances/'.$compliance->uuid);

        $response->assertNoContent();
        $this->assertNull(ActivityCompliance::query()->find($compliance->uuid));
    }
}
