<?php

namespace Tests\Unit;

use App\Constants\GeneralConstants;
use App\Jobs\RecordAffiliateCommissionReversalForCancelledTicketJob;
use App\Models\AffiliateConversion;
use App\Models\AffiliateLinkClick;
use App\Models\Event;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Transaction;
use App\Models\User;
use App\Services\AffiliateAttributionService;
use App\Services\AffiliateCommissionAvailabilityService;
use App\Services\AffiliatePartnerStatsService;
use App\Models\AffiliatePayoutRequest;
use App\Models\ActivityCompliance;
use App\Models\EventTicket;
use App\Models\Ticket;
use App\Models\TransactionOrder;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\SeedsUserAffiliate;
use Tests\TestCase;

class AffiliateAttributionServiceTest extends TestCase
{
    use RefreshDatabase;
    use SeedsUserAffiliate;

    private User $partner;
    private User $buyer;
    private Event $event;
    private Role $customerRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customerRole = Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);

        $this->partner = User::create([
            'role_uuid' => $this->customerRole->uuid,
            'email' => 'partner@test.com',
            'password' => bcrypt('password123'),
            'first_name' => 'Partner',
            'last_name' => 'User',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
        $this->seedApprovedAffiliate($this->partner, 'PARTNER1');

        $this->buyer = User::create([
            'role_uuid' => $this->customerRole->uuid,
            'email' => 'buyer@test.com',
            'password' => bcrypt('password123'),
            'first_name' => 'Buyer',
            'last_name' => 'User',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $this->event = Event::create([
            'event_name' => 'Affiliate Event',
            'event_description' => 'Test',
            'status' => 'published',
            'contact_email' => 'test@event.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['SEAT_SELECTION'],
            'affiliate_enabled' => true,
            'affiliate_commission_percent' => 10,
        ]);
    }

    // --- resolvePartnerUuid ---

    #[\PHPUnit\Framework\Attributes\Test]
    public function itResolvesPartnerUuidFromValidCode()
    {
        $result = AffiliateAttributionService::resolvePartnerUuid('PARTNER1', $this->buyer, $this->event);
        $this->assertEquals($this->partner->uuid, $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itResolvesCodeCaseInsensitively()
    {
        $result = AffiliateAttributionService::resolvePartnerUuid('partner1', $this->buyer, $this->event);
        $this->assertEquals($this->partner->uuid, $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsNullForInvalidCode()
    {
        $result = AffiliateAttributionService::resolvePartnerUuid('INVALID', $this->buyer, $this->event);
        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsNullForNullCode()
    {
        $result = AffiliateAttributionService::resolvePartnerUuid(null, $this->buyer, $this->event);
        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsNullForEmptyCode()
    {
        $result = AffiliateAttributionService::resolvePartnerUuid('', $this->buyer, $this->event);
        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsNullWhenAffiliateNotEnabled()
    {
        $this->event->update(['affiliate_enabled' => false]);

        $result = AffiliateAttributionService::resolvePartnerUuid('PARTNER1', $this->buyer, $this->event);
        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsNullWhenAffiliateEndDateHasPassed()
    {
        $this->event->update([
            'affiliate_ends_at' => Carbon::now()->subDays(3)->toDateString(),
        ]);

        $result = AffiliateAttributionService::resolvePartnerUuid('PARTNER1', $this->buyer, $this->event);
        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itResolvesPartnerWhenAffiliateEndDateIsToday()
    {
        $this->event->update([
            'affiliate_ends_at' => Carbon::now()->toDateString(),
        ]);

        $result = AffiliateAttributionService::resolvePartnerUuid('PARTNER1', $this->buyer, $this->event);
        $this->assertEquals($this->partner->uuid, $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function isAffiliateProgramActiveAtReturnsFalseAfterEndOfEndDate()
    {
        $this->event->update([
            'affiliate_ends_at' => '2026-01-15',
        ]);

        $this->assertTrue(
            AffiliateAttributionService::isAffiliateProgramActiveAt(
                $this->event->fresh(),
                Carbon::parse('2026-01-15 23:59:00', config('app.timezone'))
            )
        );

        $this->assertFalse(
            AffiliateAttributionService::isAffiliateProgramActiveAt(
                $this->event->fresh(),
                Carbon::parse('2026-01-16 00:00:01', config('app.timezone'))
            )
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsNullWhenBuyerIsSameAsPartner()
    {
        $result = AffiliateAttributionService::resolvePartnerUuid('PARTNER1', $this->partner, $this->event);
        $this->assertNull($result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsNullForNonApprovedPartnerCode()
    {
        $this->partner->userAffiliate->update(['affiliate_status' => GeneralConstants::AFFILIATE_STATUSES['NONE']]);

        $result = AffiliateAttributionService::resolvePartnerUuid('PARTNER1', $this->buyer, $this->event);
        $this->assertNull($result);
    }

    // --- recordClick ---

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRecordsClickForApprovedPartner()
    {
        $result = AffiliateAttributionService::recordClick('PARTNER1', '/events/test', '127.0.0.1', 'TestAgent');
        $this->assertTrue($result);

        $this->assertDatabaseHas('affiliate_link_clicks', [
            'partner_user_uuid' => $this->partner->uuid,
            'ref_code' => 'PARTNER1',
            'path' => '/events/test',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'TestAgent',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsFalseForEmptyRefCode()
    {
        $result = AffiliateAttributionService::recordClick('', null, null, null);
        $this->assertFalse($result);
        $this->assertEquals(0, AffiliateLinkClick::count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsFalseForNonExistentPartner()
    {
        $result = AffiliateAttributionService::recordClick('NOBODY', '/browse', null, null);
        $this->assertFalse($result);
        $this->assertEquals(0, AffiliateLinkClick::count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itTruncatesLongPath()
    {
        $longPath = str_repeat('a', 600);
        AffiliateAttributionService::recordClick('PARTNER1', $longPath, null, null);

        $click = AffiliateLinkClick::first();
        $this->assertNotNull($click);
        $this->assertEquals(512, strlen($click->path));
    }

    // --- recordConversionFromPaidTransaction ---

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRecordsConversionForPaidTransaction()
    {
        $schedule = Schedule::create([
            'event_uuid' => $this->event->uuid,
            'date_from' => '2025-01-01',
            'date_to' => '2025-01-02',
            'status' => 'published',
        ]);
        $scheduleTime = ScheduleTime::create([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
            'status' => 'published',
        ]);

        $transaction = Transaction::create([
            'user_uuid' => $this->buyer->uuid,
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'payment_method' => 'card',
            'order_number' => 'ORD-AFF-001',
            'total_amount' => 5000,
            'sub_total' => 5000,
            'tax_amount' => 0,
            'discount' => 0,
            'status' => 'completed',
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => 'confirmed',
            'affiliate_partner_uuid' => $this->partner->uuid,
        ]);

        AffiliateAttributionService::recordConversionFromPaidTransaction($transaction);

        $this->assertDatabaseHas('affiliate_conversions', [
            'partner_user_uuid' => $this->partner->uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $this->event->uuid,
            'order_total' => 5000,
            'commission_percent' => 10,
            'commission_amount' => 500,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRecordsConversionUsingNetSellingPriceFromOrderLines(): void
    {
        ActivityCompliance::query()->create([
            'activityable_type' => 'event',
            'activityable_id' => $this->event->uuid,
            'label' => 'VAT',
            'percentage' => 12,
            'amount_type' => ActivityCompliance::AMOUNT_TYPE['PERCENTAGE'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $schedule = Schedule::create([
            'event_uuid' => $this->event->uuid,
            'date_from' => '2025-01-01',
            'date_to' => '2025-01-02',
            'status' => 'published',
        ]);
        $scheduleTime = ScheduleTime::create([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
            'status' => 'published',
        ]);
        $eventTicket = EventTicket::create([
            'event_uuid' => $this->event->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'schedule_uuid' => $schedule->uuid,
            'code' => 'GA',
            'name' => 'General',
            'description' => 'GA',
            'price' => 999,
            'ticket_code' => 'GA1',
            'is_bundle' => false,
        ]);

        $transaction = Transaction::create([
            'user_uuid' => $this->buyer->uuid,
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'payment_method' => 'card',
            'order_number' => 'ORD-AFF-NET-001',
            'sub_total' => 999,
            'discount' => 200,
            'markup_amount' => 100,
            'tax_amount' => 107.88,
            'total_amount' => 1006.88,
            'status' => 'completed',
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => 'confirmed',
            'affiliate_partner_uuid' => $this->partner->uuid,
        ]);

        TransactionOrder::create([
            'user_uuid' => $this->buyer->uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_ticket_uuid' => $eventTicket->uuid,
            'quantity' => 1,
            'price' => 999,
            'markup' => 100,
            'markup_discount' => 0,
            'discount' => 200,
            'total_amount' => 899,
        ]);

        AffiliateAttributionService::recordConversionFromPaidTransaction($transaction->fresh());

        $this->assertDatabaseHas('affiliate_conversions', [
            'partner_user_uuid' => $this->partner->uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $this->event->uuid,
            'order_total' => 799,
            'commission_percent' => 10,
            'commission_amount' => 79.9,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itSkipsConversionForUnpaidTransaction()
    {
        $schedule = Schedule::create([
            'event_uuid' => $this->event->uuid,
            'date_from' => '2025-01-01',
            'date_to' => '2025-01-02',
            'status' => 'published',
        ]);
        $scheduleTime = ScheduleTime::create([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
            'status' => 'published',
        ]);

        $transaction = Transaction::create([
            'user_uuid' => $this->buyer->uuid,
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'payment_method' => 'card',
            'order_number' => 'ORD-AFF-002',
            'total_amount' => 5000,
            'sub_total' => 5000,
            'tax_amount' => 0,
            'discount' => 0,
            'status' => 'pending',
            'payment_status' => Transaction::PAYMENT_STATUS['PENDING'],
            'order_status' => 'processing',
            'affiliate_partner_uuid' => $this->partner->uuid,
        ]);

        AffiliateAttributionService::recordConversionFromPaidTransaction($transaction);

        $this->assertEquals(0, AffiliateConversion::count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itSkipsConversionWhenNoPartnerUuid()
    {
        $schedule = Schedule::create([
            'event_uuid' => $this->event->uuid,
            'date_from' => '2025-01-01',
            'date_to' => '2025-01-02',
            'status' => 'published',
        ]);
        $scheduleTime = ScheduleTime::create([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
            'status' => 'published',
        ]);

        $transaction = Transaction::create([
            'user_uuid' => $this->buyer->uuid,
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'payment_method' => 'card',
            'order_number' => 'ORD-AFF-003',
            'total_amount' => 5000,
            'sub_total' => 5000,
            'tax_amount' => 0,
            'discount' => 0,
            'status' => 'completed',
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => 'confirmed',
            'affiliate_partner_uuid' => null,
        ]);

        AffiliateAttributionService::recordConversionFromPaidTransaction($transaction);

        $this->assertEquals(0, AffiliateConversion::count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itSkipsConversionWhenBuyerIsSameAsPartner()
    {
        $schedule = Schedule::create([
            'event_uuid' => $this->event->uuid,
            'date_from' => '2025-01-01',
            'date_to' => '2025-01-02',
            'status' => 'published',
        ]);
        $scheduleTime = ScheduleTime::create([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
            'status' => 'published',
        ]);

        $transaction = Transaction::create([
            'user_uuid' => $this->partner->uuid,
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'payment_method' => 'card',
            'order_number' => 'ORD-AFF-004',
            'total_amount' => 5000,
            'sub_total' => 5000,
            'tax_amount' => 0,
            'discount' => 0,
            'status' => 'completed',
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => 'confirmed',
            'affiliate_partner_uuid' => $this->partner->uuid,
        ]);

        AffiliateAttributionService::recordConversionFromPaidTransaction($transaction);

        $this->assertEquals(0, AffiliateConversion::count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itSkipsConversionWhenAffiliateNotEnabledOnEvent()
    {
        $this->event->update(['affiliate_enabled' => false]);

        $schedule = Schedule::create([
            'event_uuid' => $this->event->uuid,
            'date_from' => '2025-01-01',
            'date_to' => '2025-01-02',
            'status' => 'published',
        ]);
        $scheduleTime = ScheduleTime::create([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
            'status' => 'published',
        ]);

        $transaction = Transaction::create([
            'user_uuid' => $this->buyer->uuid,
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'payment_method' => 'card',
            'order_number' => 'ORD-AFF-005',
            'total_amount' => 5000,
            'sub_total' => 5000,
            'tax_amount' => 0,
            'discount' => 0,
            'status' => 'completed',
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => 'confirmed',
            'affiliate_partner_uuid' => $this->partner->uuid,
        ]);

        AffiliateAttributionService::recordConversionFromPaidTransaction($transaction);

        $this->assertEquals(0, AffiliateConversion::count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itDoesNotDuplicateConversionForSameTransaction()
    {
        $schedule = Schedule::create([
            'event_uuid' => $this->event->uuid,
            'date_from' => '2025-01-01',
            'date_to' => '2025-01-02',
            'status' => 'published',
        ]);
        $scheduleTime = ScheduleTime::create([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
            'status' => 'published',
        ]);

        $transaction = Transaction::create([
            'user_uuid' => $this->buyer->uuid,
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'payment_method' => 'card',
            'order_number' => 'ORD-AFF-006',
            'total_amount' => 5000,
            'sub_total' => 5000,
            'tax_amount' => 0,
            'discount' => 0,
            'status' => 'completed',
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => 'confirmed',
            'affiliate_partner_uuid' => $this->partner->uuid,
        ]);

        AffiliateAttributionService::recordConversionFromPaidTransaction($transaction);
        AffiliateAttributionService::recordConversionFromPaidTransaction($transaction);

        $this->assertEquals(1, AffiliateConversion::count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRecordsProportionalCommissionReversalForCancelledTicket()
    {
        $schedule = Schedule::create([
            'event_uuid' => $this->event->uuid,
            'date_from' => '2025-01-01',
            'date_to' => '2025-01-02',
            'status' => 'published',
        ]);
        $scheduleTime = ScheduleTime::create([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
            'status' => 'published',
        ]);
        $eventTicket = EventTicket::create([
            'event_uuid' => $this->event->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'schedule_uuid' => $schedule->uuid,
            'code' => 'GA',
            'name' => 'General',
            'description' => 'GA',
            'price' => 100,
            'ticket_code' => 'GA1',
            'is_bundle' => false,
        ]);

        $transaction = Transaction::create([
            'user_uuid' => $this->buyer->uuid,
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'payment_method' => 'card',
            'order_number' => 'ORD-AFF-REV-1',
            'total_amount' => 10000,
            'sub_total' => 10000,
            'tax_amount' => 0,
            'discount' => 0,
            'status' => 'completed',
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => 'confirmed',
            'affiliate_partner_uuid' => $this->partner->uuid,
        ]);

        AffiliateConversion::create([
            'partner_user_uuid' => $this->partner->uuid,
            'transaction_uuid' => $transaction->uuid,
            'entry_type' => AffiliateConversion::ENTRY_TYPE_CREDIT,
            'event_uuid' => $this->event->uuid,
            'order_total' => 10000,
            'commission_percent' => 10,
            'commission_amount' => 1000,
        ]);

        $ticketA = Ticket::create([
            'user_uuid' => $this->buyer->uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $this->event->uuid,
            'event_ticket_uuid' => $eventTicket->uuid,
            'ticket_number' => 'T-A',
            'attendee_name' => 'Attendee A',
            'attendee_email' => 'a@test.com',
            'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
            'price' => 3000,
            'discount' => 0,
        ]);
        Ticket::create([
            'user_uuid' => $this->buyer->uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $this->event->uuid,
            'event_ticket_uuid' => $eventTicket->uuid,
            'ticket_number' => 'T-B',
            'attendee_name' => 'Attendee B',
            'attendee_email' => 'b@test.com',
            'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
            'price' => 7000,
            'discount' => 0,
        ]);

        (new RecordAffiliateCommissionReversalForCancelledTicketJob($ticketA->uuid))->handle();

        $this->assertDatabaseHas('affiliate_conversions', [
            'transaction_uuid' => $transaction->uuid,
            'entry_type' => AffiliateConversion::ENTRY_TYPE_REVERSAL,
            'ticket_uuid' => $ticketA->uuid,
            'commission_amount' => -300,
        ]);
        $this->assertEquals(2, AffiliateConversion::count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itDoesNotDuplicateReversalForSameTicket()
    {
        $schedule = Schedule::create([
            'event_uuid' => $this->event->uuid,
            'date_from' => '2025-02-01',
            'date_to' => '2025-02-02',
            'status' => 'published',
        ]);
        $scheduleTime = ScheduleTime::create([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
            'status' => 'published',
        ]);
        $eventTicket = EventTicket::create([
            'event_uuid' => $this->event->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'schedule_uuid' => $schedule->uuid,
            'code' => 'GA2',
            'name' => 'General',
            'description' => 'GA',
            'price' => 100,
            'ticket_code' => 'GA2',
            'is_bundle' => false,
        ]);

        $transaction = Transaction::create([
            'user_uuid' => $this->buyer->uuid,
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'payment_method' => 'card',
            'order_number' => 'ORD-AFF-REV-2',
            'total_amount' => 5000,
            'sub_total' => 5000,
            'tax_amount' => 0,
            'discount' => 0,
            'status' => 'completed',
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => 'confirmed',
            'affiliate_partner_uuid' => $this->partner->uuid,
        ]);

        AffiliateConversion::create([
            'partner_user_uuid' => $this->partner->uuid,
            'transaction_uuid' => $transaction->uuid,
            'entry_type' => AffiliateConversion::ENTRY_TYPE_CREDIT,
            'event_uuid' => $this->event->uuid,
            'order_total' => 5000,
            'commission_percent' => 10,
            'commission_amount' => 500,
        ]);

        $ticket = Ticket::create([
            'user_uuid' => $this->buyer->uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $this->event->uuid,
            'event_ticket_uuid' => $eventTicket->uuid,
            'ticket_number' => 'T-SINGLE',
            'attendee_name' => 'Solo',
            'attendee_email' => 'solo@test.com',
            'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
            'price' => 5000,
            'discount' => 0,
        ]);

        (new RecordAffiliateCommissionReversalForCancelledTicketJob($ticket->uuid))->handle();
        (new RecordAffiliateCommissionReversalForCancelledTicketJob($ticket->uuid))->handle();

        $this->assertEquals(1, AffiliateConversion::query()->where('entry_type', AffiliateConversion::ENTRY_TYPE_REVERSAL)->count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReducesNetEarningsInStatsAfterReversal()
    {
        $txn = $this->createTestTransaction(10000);
        $credit = AffiliateConversion::create([
            'partner_user_uuid' => $this->partner->uuid,
            'transaction_uuid' => $txn->uuid,
            'entry_type' => AffiliateConversion::ENTRY_TYPE_CREDIT,
            'event_uuid' => $this->event->uuid,
            'order_total' => 10000,
            'commission_percent' => 10,
            'commission_amount' => 1000,
        ]);
        $this->markAffiliateConversionMature($credit);
        $reversal = AffiliateConversion::create([
            'partner_user_uuid' => $this->partner->uuid,
            'transaction_uuid' => $txn->uuid,
            'entry_type' => AffiliateConversion::ENTRY_TYPE_REVERSAL,
            'ticket_uuid' => null,
            'event_uuid' => $this->event->uuid,
            'order_total' => 1000,
            'commission_percent' => 10,
            'commission_amount' => -250,
        ]);
        $this->markAffiliateConversionMature($reversal);

        $stats = AffiliatePartnerStatsService::dashboardStatsForUser($this->partner);

        $this->assertEquals(675, $stats['total_conversions']);
        $this->assertEquals(675, $stats['matured_commission_net']);
        $this->assertEquals(675, $stats['available_earnings']);
    }

    // --- AffiliatePartnerStatsService ---

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsZeroStatsForNonApprovedPartner()
    {
        $nonPartner = User::create([
            'role_uuid' => $this->customerRole->uuid,
            'email' => 'nonpartner@test.com',
            'password' => bcrypt('password123'),
            'first_name' => 'Non',
            'last_name' => 'Partner',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $stats = AffiliatePartnerStatsService::dashboardStatsForUser($nonPartner);

        $this->assertEquals(0, $stats['total_clicks']);
        $this->assertEquals(0.0, $stats['total_conversions']);
        $this->assertEquals(0.0, $stats['pending_earnings']);
        $this->assertEquals(0.0, $stats['paid_earnings']);
        $this->assertEquals(0.0, $stats['available_earnings']);
        $this->assertEquals(0.0, $stats['matured_commission_net']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itExcludesImmatureCommissionFromAvailableEarnings()
    {
        $tz = AffiliateCommissionAvailabilityService::timezone();
        Carbon::setTestNow(Carbon::parse('2026-04-10 12:00:00', $tz));
        try {
            $txn = $this->createTestTransaction(10000);
            AffiliateConversion::create([
                'partner_user_uuid' => $this->partner->uuid,
                'transaction_uuid' => $txn->uuid,
                'entry_type' => AffiliateConversion::ENTRY_TYPE_CREDIT,
                'event_uuid' => $this->event->uuid,
                'order_total' => 10000,
                'commission_percent' => 10,
                'commission_amount' => 1000,
            ]);

            $stats = AffiliatePartnerStatsService::dashboardStatsForUser($this->partner);

            $this->assertEquals(900, $stats['total_conversions']);
            $this->assertEquals(0.0, $stats['matured_commission_net']);
            $this->assertEquals(0.0, $stats['available_earnings']);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function markAffiliateConversionMature(AffiliateConversion $c): void
    {
        DB::table('affiliate_conversions')->where('uuid', $c->uuid)->update([
            'created_at' => '2020-01-01 00:00:00',
            'updated_at' => '2020-01-01 00:00:00',
        ]);
    }

    private function createTestTransaction(float $amount): Transaction
    {
        $schedule = Schedule::create([
            'event_uuid' => $this->event->uuid,
            'date_from' => '2025-06-01',
            'date_to' => '2025-06-02',
            'status' => 'published',
        ]);
        $scheduleTime = ScheduleTime::create([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
            'status' => 'published',
        ]);

        return Transaction::create([
            'user_uuid' => $this->buyer->uuid,
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'payment_method' => 'card',
            'order_number' => 'ORD-STAT-' . uniqid(),
            'total_amount' => $amount,
            'sub_total' => $amount,
            'tax_amount' => 0,
            'discount' => 0,
            'status' => 'completed',
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => 'confirmed',
            'affiliate_partner_uuid' => $this->partner->uuid,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCalculatesStatsCorrectly()
    {
        AffiliateLinkClick::create([
            'partner_user_uuid' => $this->partner->uuid,
            'ref_code' => 'PARTNER1',
            'path' => '/browse',
        ]);
        AffiliateLinkClick::create([
            'partner_user_uuid' => $this->partner->uuid,
            'ref_code' => 'PARTNER1',
            'path' => '/events/test',
        ]);
        AffiliateLinkClick::create([
            'partner_user_uuid' => $this->partner->uuid,
            'ref_code' => 'PARTNER1',
            'path' => '/events/another',
        ]);

        $txn1 = $this->createTestTransaction(10000);
        $c1 = AffiliateConversion::create([
            'partner_user_uuid' => $this->partner->uuid,
            'transaction_uuid' => $txn1->uuid,
            'event_uuid' => $this->event->uuid,
            'order_total' => 10000,
            'commission_percent' => 10,
            'commission_amount' => 1000,
        ]);
        $this->markAffiliateConversionMature($c1);
        $txn2 = $this->createTestTransaction(5000);
        $c2 = AffiliateConversion::create([
            'partner_user_uuid' => $this->partner->uuid,
            'transaction_uuid' => $txn2->uuid,
            'event_uuid' => $this->event->uuid,
            'order_total' => 5000,
            'commission_percent' => 10,
            'commission_amount' => 500,
        ]);
        $this->markAffiliateConversionMature($c2);

        AffiliatePayoutRequest::create([
            'user_uuid' => $this->partner->uuid,
            'amount_requested' => 300,
            'currency' => 'PHP',
            'status' => AffiliatePayoutRequest::STATUS_APPROVED,
            'processed_at' => now(),
        ]);
        AffiliatePayoutRequest::create([
            'user_uuid' => $this->partner->uuid,
            'amount_requested' => 200,
            'currency' => 'PHP',
            'status' => AffiliatePayoutRequest::STATUS_PENDING,
        ]);
        AffiliatePayoutRequest::create([
            'user_uuid' => $this->partner->uuid,
            'amount_requested' => 100,
            'currency' => 'PHP',
            'status' => AffiliatePayoutRequest::STATUS_DECLINED,
        ]);

        $stats = AffiliatePartnerStatsService::dashboardStatsForUser($this->partner);

        $this->assertEquals(3, $stats['total_clicks']);
        $this->assertEquals(1350, $stats['total_conversions']);
        $this->assertEquals(1350, $stats['matured_commission_net']);
        $this->assertEquals(300, $stats['paid_earnings']);
        $this->assertEquals(200, $stats['pending_earnings']);
        // available = 1350 - 300 (approved) - 200 (pending) = 850
        $this->assertEquals(850, $stats['available_earnings']);
    }
}
