<?php

namespace Tests\Feature\Coverage\GapAuthMisc;

use App\Constants\GeneralConstants;
use App\Http\Repositories\UserRepository;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserRecentActivityGapTest extends TestCase
{
    use GapAuthMiscFixtures;
    use RefreshDatabase;

    private Event $event;

    private EventTicket $eventTicket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->event = Event::create([
            'event_name' => 'Gap Activity Event',
            'event_description' => 'Recent activity coverage',
            'contact_email' => 'event@test.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'tags' => [],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
        ]);

        $this->eventTicket = EventTicket::create([
            'event_uuid' => $this->event->uuid,
            'code' => 'GAP-' . Str::random(6),
            'name' => 'General',
            'description' => 'Ticket type',
            'price' => 100,
        ]);
    }

    private function makePaidTransaction(User $user, array $attributes = []): Transaction
    {
        return Transaction::create(array_merge([
            'user_uuid' => $user->uuid,
            'event_uuid' => $this->event->uuid,
            'order_number' => 'ORD-' . Str::uuid(),
            'sub_total' => 100,
            'tax_amount' => 0,
            'discount' => 0,
            'total_amount' => 100,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
        ], $attributes));
    }

    private function makeTicket(Transaction $transaction, array $attributes = []): Ticket
    {
        return Ticket::create(array_merge([
            'user_uuid' => $transaction->user_uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $this->event->uuid,
            'event_ticket_uuid' => $this->eventTicket->uuid,
            'attendee_name' => 'Attendee',
            'attendee_email' => 'attendee-' . Str::random(8) . '@test.com',
            'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
        ], $attributes));
    }

    #[Test]
    public function manualPurchaseWithoutCreatorIsAttributedToSystem(): void
    {
        $customer = $this->makeCustomer(['first_name' => 'Mia', 'last_name' => 'Cruz']);
        $this->makePaidTransaction($customer, ['payment_order_id' => null]);

        $activity = app(UserRepository::class)->getUserRecentActivity($customer);

        $this->assertCount(1, $activity);
        $this->assertSame('purchase', $activity[0]['type']);
        $this->assertSame(
            "System manually added ticket to user {$customer->full_name} for Gap Activity Event",
            $activity[0]['message']
        );
    }

    #[Test]
    public function manualPurchaseWithCreatorReportsOnlyTheCreatorName(): void
    {
        $customer = $this->makeCustomer();
        $creator = $this->makeCustomer(['first_name' => 'Staff', 'last_name' => 'Member']);
        $transaction = $this->makePaidTransaction($customer, ['payment_order_id' => null]);
        DB::table('transactions')->where('uuid', $transaction->uuid)->update(['created_by' => $creator->uuid]);

        $activity = app(UserRepository::class)->getUserRecentActivity($customer);

        $this->assertSame('purchase', $activity[0]['type']);
        $this->assertSame($creator->full_name, $activity[0]['message']);
    }

    #[Test]
    public function transferredAndUsedTicketsAppearInActivity(): void
    {
        $customer = $this->makeCustomer(['first_name' => 'Ana', 'last_name' => 'Reyes']);
        $recipient = $this->makeCustomer(['first_name' => 'Ben', 'last_name' => 'Santos']);
        $transaction = $this->makePaidTransaction($customer, ['payment_order_id' => 'pay_123']);

        $this->makeTicket($transaction, [
            'status' => GeneralConstants::TICKET_STATUSES['TRANSFERRED'],
            'transferred_to' => $recipient->uuid,
            'transferred_at' => now()->subHour(),
        ]);
        $this->makeTicket($transaction, [
            'status' => GeneralConstants::TICKET_STATUSES['USED'],
            'used_at' => now()->subMinutes(5),
        ]);

        $activity = app(UserRepository::class)->getUserRecentActivity($customer)->keyBy('type');

        $this->assertSame(
            "{$customer->full_name} transferred ticket to {$recipient->full_name}",
            $activity['transferred_ticket']['message']
        );
        $this->assertSame(
            "{$customer->full_name} used ticket for Gap Activity Event",
            $activity['ticket_used']['message']
        );
        $this->assertStringContainsString('purchased tickets for Gap Activity Event', $activity['purchase']['message']);
    }
}
