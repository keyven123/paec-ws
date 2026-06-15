<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class EventTicketMarkupControllerTest extends TestCase
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
            'name' => 'Markup',
            'code' => 'markups',
            'available_access' => ['view', 'update'],
        ]);

        foreach (['view', 'update'] as $access) {
            RolePermission::create([
                'role_uuid' => $role->uuid,
                'permission_uuid' => $permission->uuid,
                'access' => 'markups-'.$access,
            ]);
        }

        $this->admin = AdminUser::create([
            'role_uuid' => $role->uuid,
            'email' => 'markup-admin@test.com',
            'password' => 'password123',
            'first_name' => 'Markup',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $this->token = JWTAuth::fromUser($this->admin);
        $this->organization = Organization::factory()->create();
    }

    public function test_it_lists_events_with_tickets_for_organization(): void
    {
        $event = Event::factory()->create(['organization_uuid' => $this->organization->uuid]);
        $ticket = EventTicket::create([
            'event_uuid' => $event->uuid,
            'code' => 'GA',
            'name' => 'General Admission',
            'price' => 500,
            'markup_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
            'markup_value' => 10,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->getJson('/api/v1/markups/organizations/'.$this->organization->uuid);

        $response->assertOk()
            ->assertJsonPath('data.0.uuid', $event->uuid)
            ->assertJsonPath('data.0.event_tickets.0.uuid', $ticket->uuid)
            ->assertJsonPath('data.0.event_tickets.0.display_price', 550);
    }

    public function test_it_updates_ticket_markup(): void
    {
        $event = Event::factory()->create(['organization_uuid' => $this->organization->uuid]);
        $ticket = EventTicket::create([
            'event_uuid' => $event->uuid,
            'code' => 'VIP',
            'name' => 'VIP',
            'price' => 1000,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
        ])->patchJson('/api/v1/markups/event-tickets/'.$ticket->uuid, [
            'organization_uuid' => $this->organization->uuid,
            'markup_type' => 'amount',
            'markup_value' => 50,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.markup_type', 'amount')
            ->assertJsonPath('data.markup_value', '50.00')
            ->assertJsonPath('data.display_price', 1050);

        $this->assertDatabaseHas('event_tickets', [
            'uuid' => $ticket->uuid,
            'markup_type' => 'amount',
            'markup_value' => 50,
        ]);
    }
}
