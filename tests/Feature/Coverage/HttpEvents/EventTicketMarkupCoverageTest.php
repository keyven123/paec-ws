<?php

namespace Tests\Feature\Coverage\HttpEvents;

use App\Constants\GeneralConstants;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Organization;
use PHPUnit\Framework\Attributes\Test;

class EventTicketMarkupCoverageTest extends HttpEventsTestCase
{
    private string $token;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->token] = $this->createAdmin(['markups' => ['view', 'update']]);
        $this->organization = Organization::factory()->create();
    }

    #[Test]
    public function clearingMarkupTypeAlsoClearsMarkupValue(): void
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

        $this->withHeaders($this->bearer($this->token))
            ->patchJson('/api/v1/markups/event-tickets/' . $ticket->uuid, [
                'organization_uuid' => $this->organization->uuid,
                'markup_type' => null,
                'markup_value' => 25,
            ])
            ->assertOk()
            ->assertJsonPath('data.markup_type', null)
            ->assertJsonPath('data.markup_value', null);

        $this->assertDatabaseHas('event_tickets', [
            'uuid' => $ticket->uuid,
            'markup_type' => null,
            'markup_value' => null,
        ]);
    }

    #[Test]
    public function updatingTicketOfAnotherOrganizationReturns404(): void
    {
        $otherOrganization = Organization::factory()->create();
        $event = Event::factory()->create(['organization_uuid' => $otherOrganization->uuid]);
        $ticket = EventTicket::create([
            'event_uuid' => $event->uuid,
            'code' => 'VIP',
            'name' => 'VIP',
            'price' => 1000,
        ]);

        $this->withHeaders($this->bearer($this->token))
            ->patchJson('/api/v1/markups/event-tickets/' . $ticket->uuid, [
                'organization_uuid' => $this->organization->uuid,
                'markup_type' => 'amount',
                'markup_value' => 50,
            ])
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Event ticket not found for this organization',
            ]);

        $this->assertDatabaseHas('event_tickets', [
            'uuid' => $ticket->uuid,
            'markup_type' => null,
        ]);
    }
}
