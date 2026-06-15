<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Organization;
use App\Models\Role;
use App\Models\TempTransaction;
use App\Models\TempTransactionOrder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CleanupTempTransactionCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create([
            'name' => 'Customer',
            'code' => 'customer-cleanup-temp-test',
            'is_admin' => false,
        ]);

        $this->user = User::factory()->create(['role_uuid' => $role->uuid]);

        $this->organization = Organization::create([
            'name' => 'Cleanup Temp Org',
            'representative_first_name' => 'Test',
            'representative_last_name' => 'Org',
            'email' => 'cleanup-temp-org@test.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function it_deletes_seat_selection_temp_transactions_older_than_ten_minutes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', 'Asia/Manila'));

        $event = $this->createEvent(Event::EVENT_CONFIGS['SEAT_SELECTION']);
        $ticket = $this->createEventTicket($event);
        $expiredSeatHold = $this->createTempTransaction($event, createdMinutesAgo: 15);
        $this->createTempOrderWithSeats($expiredSeatHold, $ticket);

        $recentSeatHold = $this->createTempTransaction($event, createdMinutesAgo: 5);
        $this->createTempOrderWithSeats($recentSeatHold, $ticket);

        $this->artisan('app:cleanup-temp-transaction')->assertSuccessful();

        $this->assertDatabaseMissing('temp_transactions', ['uuid' => $expiredSeatHold->uuid]);
        $this->assertDatabaseHas('temp_transactions', ['uuid' => $recentSeatHold->uuid]);
    }

    #[Test]
    public function it_keeps_non_seat_temp_transactions_younger_than_one_hour(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', 'Asia/Manila'));

        $event = $this->createEvent(Event::EVENT_CONFIGS['OPEN_TICKET']);
        $ticket = $this->createEventTicket($event);
        $recentOpenHold = $this->createTempTransaction($event, createdMinutesAgo: 30);
        $this->createTempOrderWithoutSeats($recentOpenHold, $ticket);

        $this->artisan('app:cleanup-temp-transaction')->assertSuccessful();

        $this->assertDatabaseHas('temp_transactions', ['uuid' => $recentOpenHold->uuid]);
    }

    #[Test]
    public function it_deletes_non_seat_temp_transactions_older_than_one_hour(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', 'Asia/Manila'));

        $event = $this->createEvent(Event::EVENT_CONFIGS['OPEN_TICKET']);
        $ticket = $this->createEventTicket($event);
        $expiredOpenHold = $this->createTempTransaction($event, createdMinutesAgo: 70);
        $this->createTempOrderWithoutSeats($expiredOpenHold, $ticket);

        $this->artisan('app:cleanup-temp-transaction')->assertSuccessful();

        $this->assertDatabaseMissing('temp_transactions', ['uuid' => $expiredOpenHold->uuid]);
    }

    #[Test]
    public function it_deletes_temp_transaction_orders_when_parent_is_removed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01 12:00:00', 'Asia/Manila'));

        $event = $this->createEvent(Event::EVENT_CONFIGS['SEAT_SELECTION']);
        $ticket = $this->createEventTicket($event);
        $expiredSeatHold = $this->createTempTransaction($event, createdMinutesAgo: 20);
        $order = $this->createTempOrderWithSeats($expiredSeatHold, $ticket);

        $this->artisan('app:cleanup-temp-transaction')->assertSuccessful();

        $this->assertDatabaseMissing('temp_transaction_orders', ['uuid' => $order->uuid]);
    }

    private function createEvent(string $eventConfig): Event
    {
        return Event::create([
            'organization_uuid' => $this->organization->uuid,
            'event_name' => 'Cleanup Test Event',
            'event_description' => 'Temp transaction cleanup',
            'contact_email' => 'cleanup-event@test.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => $eventConfig,
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
            'tags' => [],
        ]);
    }

    private function createEventTicket(Event $event): EventTicket
    {
        return EventTicket::create([
            'event_uuid' => $event->uuid,
            'code' => 'CLN-' . substr(uniqid(), -6),
            'name' => 'General',
            'description' => 'Test ticket',
            'price' => 100.00,
            'is_bundle' => false,
        ]);
    }

    private function createTempTransaction(Event $event, int $createdMinutesAgo): TempTransaction
    {
        $tempTransaction = TempTransaction::create([
            'user_uuid' => $this->user->uuid,
            'event_uuid' => $event->uuid,
            'organization_uuid' => $this->organization->uuid,
            'total_amount' => 100.00,
            'sub_total' => 100.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
        ]);

        $createdAt = now()->subMinutes($createdMinutesAgo);

        DB::table('temp_transactions')->where('uuid', $tempTransaction->uuid)->update([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        return $tempTransaction->fresh();
    }

    private function createTempOrderWithSeats(TempTransaction $tempTransaction, EventTicket $ticket): TempTransactionOrder
    {
        return TempTransactionOrder::create([
            'user_uuid' => $this->user->uuid,
            'temp_transaction_uuid' => $tempTransaction->uuid,
            'event_ticket_uuid' => $ticket->uuid,
            'quantity' => 1,
            'price' => 100.00,
            'total_amount' => 100.00,
            'discount' => 0.00,
            'seats' => [
                [
                    'uuid' => '00000000-0000-4000-8000-000000000001',
                    'row' => 'A',
                    'seat_no' => 1,
                    'category' => 'vip',
                    'color' => '#ff0000',
                ],
            ],
        ]);
    }

    private function createTempOrderWithoutSeats(TempTransaction $tempTransaction, EventTicket $ticket): TempTransactionOrder
    {
        return TempTransactionOrder::create([
            'user_uuid' => $this->user->uuid,
            'temp_transaction_uuid' => $tempTransaction->uuid,
            'event_ticket_uuid' => $ticket->uuid,
            'quantity' => 1,
            'price' => 100.00,
            'total_amount' => 100.00,
            'discount' => 0.00,
            'seats' => null,
        ]);
    }
}
