<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\Event;
use App\Models\EventLocation;
use App\Models\EventTicket;
use App\Models\Role;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GuestCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected Event $event;
    protected EventTicket $eventTicket;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);

        $this->event = Event::create([
            'event_name' => 'Guest Checkout Event',
            'event_description' => 'Event without a configured location',
            'contact_email' => 'contact@event.com',
            'event_type' => Event::EVENT_TYPES['DAILY'],
            'schedule_type' => Event::SCHEDULE_TYPES['DAILY'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
        ]);

        $this->eventTicket = EventTicket::create([
            'event_uuid' => $this->event->uuid,
            'code' => 'day_pass',
            'name' => 'Day Pass',
            'description' => 'Day Pass',
            'price' => 500,
            'max_ticket' => 100,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
    }

    private function cartPayload(array $guest = []): array
    {
        return [
            'event_uuid' => $this->event->uuid,
            'tickets' => [[
                'event_ticket_uuid' => $this->eventTicket->uuid,
                'quantity' => 2,
                'valid_until' => now()->addDays(3)->toDateString(),
                'seats' => [],
            ]],
            'guest' => array_merge([
                'first_name' => 'Guest',
                'last_name' => 'Buyer',
                'address' => '123 Test St',
                'email' => 'guest.buyer@example.com',
                'phone_number' => '09121231234',
            ], $guest),
        ];
    }

    #[Test]
    public function guestCanPayAndTransactionIsRecordedForAdmin(): void
    {
        config(['app.debug' => false]);

        $temp = $this->postJson('/api/v1/public/temp-transactions/guest', $this->cartPayload())
            ->assertStatus(201)
            ->json();

        $this->assertSame(1, EventLocation::where('event_uuid', $this->event->uuid)->count());

        $placed = $this->postJson('/api/v1/public/temp-transactions/guest/place-order', [
            'temp_transaction_uuid' => $temp['uuid'],
            'email' => 'GUEST.buyer@example.com',
        ])->assertStatus(201)->assertJson(['success' => true]);

        $placed->assertJsonCount(2, 'tickets');
        foreach ($placed->json('tickets') as $ticket) {
            $this->assertSame(GeneralConstants::TICKET_STATUSES['ACTIVE'], $ticket['status']);
            $this->assertNotEmpty($ticket['qr_code']);
            $this->assertNotEmpty($ticket['event_ticket']['name']);
        }

        $guest = User::where('email', 'guest.buyer@example.com')->firstOrFail();
        $this->assertSame(User::GUEST_PROVIDER, $guest->provider);
        $this->assertSame('+639121231234', $guest->phone_number);

        $transaction = Transaction::where('user_uuid', $guest->uuid)->firstOrFail();
        $this->assertSame(Transaction::PAYMENT_STATUS['PAID'], $transaction->payment_status);
        $this->assertSame(Transaction::ORDER_STATUS['CONFIRMED'], $transaction->order_status);
        $this->assertEquals(1000, (float) $transaction->total_amount);

        $tickets = Ticket::where('transaction_uuid', $transaction->uuid)->get();
        $this->assertCount(2, $tickets);
        $this->assertTrue($tickets->every(
            fn (Ticket $ticket) => $ticket->status === GeneralConstants::TICKET_STATUSES['ACTIVE'] && $ticket->qr_code
        ));
    }

    #[Test]
    public function guestCheckoutRejectsMismatchedEmail(): void
    {
        $temp = $this->postJson('/api/v1/public/temp-transactions/guest', $this->cartPayload())
            ->assertStatus(201)
            ->json();

        $this->postJson('/api/v1/public/temp-transactions/guest/place-order', [
            'temp_transaction_uuid' => $temp['uuid'],
            'email' => 'someone.else@example.com',
        ])->assertStatus(422)->assertJsonValidationErrors('temp_transaction_uuid');

        $this->assertSame(0, Transaction::count());
    }

    #[Test]
    public function guestCheckoutReusesExistingAccountWithoutChangingIt(): void
    {
        $existing = User::factory()->create([
            'role_uuid' => Role::firstOrFail()->uuid,
            'email' => 'member@example.com',
            'first_name' => 'Existing',
        ]);

        $temp = $this->postJson('/api/v1/public/temp-transactions/guest', $this->cartPayload([
            'email' => 'member@example.com',
        ]))->assertStatus(201)->json();

        $this->assertSame($existing->uuid, $temp['user_uuid']);
        $this->assertSame('Existing', $existing->fresh()->first_name);
        $this->assertNull($existing->fresh()->provider);
    }

    #[Test]
    public function guestCanLaterSignUpWithSameEmailAndKeepOrders(): void
    {
        $temp = $this->postJson('/api/v1/public/temp-transactions/guest', $this->cartPayload())
            ->assertStatus(201)
            ->json();
        $this->postJson('/api/v1/public/temp-transactions/guest/place-order', [
            'temp_transaction_uuid' => $temp['uuid'],
            'email' => 'guest.buyer@example.com',
        ])->assertStatus(201);

        $this->postJson('/api/v1/login', [
            'email' => 'guest.buyer@example.com',
            'password' => 'Secret123!',
        ])->assertStatus(401);

        $this->postJson('/api/v1/register', [
            'first_name' => 'Guest',
            'last_name' => 'Buyer',
            'address' => '123 Test St',
            'phone_number' => '09121231234',
            'email' => 'guest.buyer@example.com',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
            'terms_accepted' => true,
        ])->assertStatus(201);

        $user = User::where('email', 'guest.buyer@example.com')->firstOrFail();
        $this->assertNull($user->provider);
        $this->assertSame(1, User::where('email', 'guest.buyer@example.com')->count());
        $this->assertSame(1, Transaction::where('user_uuid', $user->uuid)->count());
    }
}
