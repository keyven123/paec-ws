<?php

namespace Tests\Feature\Coverage\GapEvents;

use App\Constants\GeneralConstants;
use App\Http\Repositories\EventRepository;
use App\Models\AffiliateConversion;
use App\Models\Event;
use App\Models\PromoCode;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Venue;
use Carbon\Carbon;
use PHPUnit\Framework\Attributes\Test;

class EventRepositoryExportTest extends GapEventsTestCase
{
    private EventRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repository = app(EventRepository::class);
    }

    private function createPromoCode(Event $event): PromoCode
    {
        return PromoCode::create([
            'organization_uuid' => $event->organization_uuid,
            'code' => 'SAVE25',
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['AMOUNT'],
            'discount_value' => 25,
            'is_unlimited' => true,
            'usable_from' => now()->subMonth(),
            'usable_to' => now()->addMonth(),
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
    }

    /**
     * Concert with a venue, a timed schedule and a second untimed schedule:
     * - paid transaction with promo code, affiliate partner, VIP x2 (one extra transferred ticket) and GA x1
     * - paid zero-value transaction whose only order points to a missing event ticket but has an event discount
     *
     * @return array{event: Event, promo: \App\Models\Transaction, legacy: \App\Models\Transaction}
     */
    private function seedConcertSales(): array
    {
        $organization = $this->createOrganization(['commission_percentage' => 10]);
        $venue = Venue::create(['name' => 'Grand Hall', 'code' => 'grand-hall', 'type' => Venue::TYPES['MUSICAL']]);
        $event = $this->createEvent('Concert Night', [
            'organization_uuid' => $organization->uuid,
            'venue_uuid' => $venue->uuid,
        ]);
        [$timedSchedule, $time] = $this->createSchedule($event);
        [$untimedSchedule] = $this->createSchedule($event, withTime: false);

        $vip = $this->createEventTicket($event, 'VIP', 100);
        $ga = $this->createEventTicket($event, 'General', 50);
        $buyer = $this->createCustomer('buyer@test.com');
        $partner = $this->createCustomer('partner@test.com', ['first_name' => 'Alex', 'last_name' => 'Partner']);
        $promoCode = $this->createPromoCode($event);

        $promo = $this->createTransaction($event, $buyer, 225, [
            'schedule_uuid' => $timedSchedule->uuid,
            'schedule_time_uuid' => $time->uuid,
            'promo_code_uuid' => $promoCode->uuid,
            'promo_code_discount' => 25,
            'payment_provider' => 'cash',
        ]);
        $this->createOrder($promo, $vip, 2, 100);
        $this->createOrder($promo, $ga, 1, 50);
        $this->createTicket($promo, $vip);
        $this->createTicket($promo, $vip);
        $this->createTicket($promo, $vip, ['status' => GeneralConstants::TICKET_STATUSES['TRANSFERRED']]);
        $this->createTicket($promo, $ga);
        AffiliateConversion::create([
            'partner_user_uuid' => $partner->uuid,
            'transaction_uuid' => $promo->uuid,
            'event_uuid' => $event->uuid,
            'order_total' => 250,
            'commission_percent' => 10,
            'commission_amount' => 25,
        ]);

        $legacy = $this->createTransaction($event, $buyer, 0, [
            'schedule_uuid' => $untimedSchedule->uuid,
            'discount' => 10,
            'created_at' => '2026-05-11 09:00:00',
        ]);
        $this->createOrder($legacy, null, 1, 0);

        return ['event' => $event, 'promo' => $promo, 'legacy' => $legacy];
    }

    #[Test]
    public function exportWritesAdminColumnsForEveryPaidOrderLine(): void
    {
        ['event' => $event, 'promo' => $promo, 'legacy' => $legacy] = $this->seedConcertSales();

        $rows = $this->csvRows($this->repository->export($event));

        $this->assertCount(4, $rows);
        $this->assertCount(27, $rows[0]);
        $this->assertSame('Gross Revenue', $rows[0][17]);

        [$header, $vipRow, $gaRow, $legacyRow] = $rows;
        $this->assertCount(27, $vipRow);
        $this->assertSame($promo->order_number, $vipRow[0]);
        $this->assertSame('2026/05/10 12:00:00', $vipRow[1]);
        $this->assertSame($promo->user->full_name, $vipRow[3]);
        $this->assertStringContainsString('Buyer', $vipRow[3]);
        $this->assertSame('buyer@test.com', $vipRow[4]);
        $this->assertSame('N/A', $vipRow[5]);
        $this->assertSame('VIP', $vipRow[6]);
        $this->assertSame('2', $vipRow[7]);
        $this->assertSame('100.00', $vipRow[8]);
        $this->assertSame('20.00', $vipRow[14]);
        $this->assertSame('cash', $vipRow[18]);
        $this->assertSame('CASH', $vipRow[19]);
        $this->assertSame('N/A', $vipRow[20]);
        $this->assertSame('Concert Night', $vipRow[21]);
        $this->assertSame('2026/06/01 - 10:00 - 12:00', $vipRow[22]);
        $this->assertSame('Grand Hall', $vipRow[23]);
        $this->assertSame('SAVE25', $vipRow[24]);
        $this->assertSame('paid', $vipRow[25]);
        $this->assertSame(User::where('email', 'partner@test.com')->first()->full_name, $vipRow[26]);
        $this->assertStringStartsWith('Alex', $vipRow[26]);

        $this->assertSame('General', $gaRow[6]);
        $this->assertSame('1', $gaRow[7]);
        $this->assertSame('5.00', $gaRow[14]);

        $this->assertSame($legacy->order_number, $legacyRow[0]);
        $this->assertSame('N/A', $legacyRow[6]);
        $this->assertSame('0', $legacyRow[7]);
        $this->assertSame('0.00', $legacyRow[14]);
        $this->assertSame('2026/06/01', $legacyRow[22]);
        $this->assertSame('EVENT TICKET PROMO', $legacyRow[24]);
        $this->assertSame('N/A', $legacyRow[25]);
        $this->assertSame('N/A', $legacyRow[26]);
    }

    #[Test]
    public function exportForMerchantsOmitsAdminOnlyColumns(): void
    {
        ['event' => $event, 'promo' => $promo] = $this->seedConcertSales();

        $rows = $this->csvRows($this->repository->export($event, includeAdminOnlyColumns: false));

        $this->assertCount(23, $rows[0]);
        $this->assertSame('Affiliate Commissions', $rows[0][11]);
        $this->assertNotContains('Gross Revenue', $rows[0]);
        $this->assertCount(23, $rows[1]);
        $this->assertSame($promo->order_number, $rows[1][0]);
        $this->assertSame('20.00', $rows[1][11]);
        $this->assertSame('Concert Night', $rows[1][17]);
    }

    #[Test]
    public function exportPaidTransactionsInRangeSkipsTransactionsWithoutAnEvent(): void
    {
        $street = $this->createEvent('Street Fair', ['address' => '123 Main St']);
        $buyer = $this->createCustomer('street@test.com');
        $ticket = $this->createEventTicket($street, 'Day Pass', 30);
        $paid = $this->createTransaction($street, $buyer, 30);
        $this->createOrder($paid, $ticket, 1, 30);

        $orphan = $this->createTransaction(null, $buyer, 99);
        $this->createOrder($orphan, $ticket, 1, 99);

        $outside = $this->createTransaction($street, $buyer, 30, ['created_at' => '2025-01-01 00:00:00']);
        $this->createOrder($outside, $ticket, 1, 30);

        $csv = $this->repository->exportPaidTransactionsInRange(
            null,
            Carbon::parse('2026-05-01'),
            Carbon::parse('2026-05-31 23:59:59'),
            includeAdminOnlyColumns: false,
        );
        $rows = $this->csvRows($csv);

        $this->assertCount(2, $rows);
        $this->assertSame($paid->order_number, $rows[1][0]);
        $this->assertSame('Street Fair', $rows[1][17]);
        $this->assertSame('N/A', $rows[1][18]);
        $this->assertSame('123 Main St', $rows[1][19]);
        $this->assertStringNotContainsString($orphan->order_number, $csv);
    }

    #[Test]
    public function getEventTicketsAggregatesNetSalesPerTicketType(): void
    {
        $event = $this->createEvent('Sales Event');
        $buyer = $this->createCustomer('sales@test.com');
        $partner = $this->createCustomer('aff@test.com');
        $vip = $this->createEventTicket($event, 'VIP', 100);
        $free = $this->createEventTicket($event, 'Freebie', 0);

        $paid = $this->createTransaction($event, $buyer, 190, ['promo_code_discount' => 10]);
        $this->createOrder($paid, $vip, 2, 100);
        AffiliateConversion::create([
            'partner_user_uuid' => $partner->uuid,
            'transaction_uuid' => $paid->uuid,
            'event_uuid' => $event->uuid,
            'order_total' => 200,
            'commission_percent' => 10,
            'commission_amount' => 20,
        ]);

        $complementary = $this->createTransaction($event, $buyer, 100);
        $this->createOrder($complementary, $vip, 1, 100);
        $this->createTicket($complementary, $vip, ['type' => 'complementary']);

        $freebies = $this->createTransaction($event, $buyer, 0);
        $this->createOrder($freebies, $free, 3, 0);

        $missingTicket = $this->createTransaction($event, $buyer, 75);
        $this->createOrder($missingTicket, null, 1, 75);

        $pending = $this->createTransaction($event, $buyer, 100, ['payment_status' => Transaction::PAYMENT_STATUS['PENDING']]);
        $this->createOrder($pending, $vip, 1, 100);

        $sales = $this->repository->getEventTickets($event->uuid)->all();

        $this->assertSame([
            ['name' => 'Freebie', 'total_sold_amount' => 0.0, 'total_sold_tickets' => 3],
            ['name' => 'VIP', 'total_sold_amount' => 170.0, 'total_sold_tickets' => 3],
        ], $sales);
        $this->assertSame(170.0, $this->repository->netRevenueAfterAffiliateFromTicketLines($event->uuid));
    }

    #[Test]
    public function attendeeReportIncludesCustomFieldsAndHonoursTheDateRange(): void
    {
        $event = $this->createEvent('Workshop', [
            'other_info' => ['Shirt Size' => 'text', 'Company' => 'text'],
        ]);
        $buyer = $this->createCustomer('attendee@test.com');
        $seat = $this->createEventTicket($event, 'Seat', 40);

        $inRange = $this->createTransaction($event, $buyer, 40, ['paid_at' => Carbon::parse('2026-05-10 12:00:00')]);
        $this->createTicket($inRange, $seat, [
            'ticket_number' => 'TKT-IN',
            'attendee_name' => 'Ana',
            'other_info' => ['Shirt Size' => 'M', 'Company' => null],
        ]);

        $outOfRange = $this->createTransaction($event, $buyer, 40, ['paid_at' => Carbon::parse('2026-01-10 12:00:00')]);
        $this->createTicket($outOfRange, $seat, [
            'ticket_number' => 'TKT-OUT',
            'other_info' => ['Shirt Size' => 'L', 'Company' => 'Acme'],
        ]);

        $csv = $this->repository->exportAttendeeRegistrationReport(
            $event,
            Carbon::parse('2026-05-01'),
            Carbon::parse('2026-05-31 23:59:59'),
        );
        $rows = $this->csvRows($csv);

        $this->assertCount(2, $rows);
        $this->assertSame(['Ticket Status', 'Shirt Size', 'Company'], array_slice($rows[0], 10));
        $this->assertSame([
            $inRange->order_number,
            'TKT-IN',
            '',
            '2026/05/10 12:00:00',
            Transaction::PAYMENT_STATUS['PAID'],
            'Ana',
            'attendee@test.com',
            'N/A',
            'Seat',
            'Workshop',
            'active',
            'M',
            '',
        ], $rows[1]);
        $this->assertStringNotContainsString('TKT-OUT', $csv);
    }

    #[Test]
    public function ticketCalendarSkipsFlexibleTicketsWhoseValidityEndsBeforeTheyWereBought(): void
    {
        $event = $this->createEvent('Theme Park');
        $buyer = $this->createCustomer('flex@test.com');
        $pass = $this->createEventTicket($event, 'Flex Pass', 20);
        $transaction = $this->createTransaction($event, $buyer, 40);

        $this->createTicket($transaction, $pass, [
            'visit_policy' => 'flexible',
            'created_at' => '2026-05-28 09:00:00',
            'valid_until' => '2026-06-05 23:59:59',
        ]);
        $this->createTicket($transaction, $pass, [
            'visit_policy' => 'flexible',
            'created_at' => '2026-05-20 09:00:00',
            'valid_until' => '2026-05-10 23:59:59',
        ]);

        $calendar = $this->repository->getEventTicketCalendar($event->uuid, 2026, 5);

        $this->assertSame(2, $calendar['month_summary']['flexible_ticket_count']);
        $this->assertSame(
            ['2026-05-28', '2026-05-29', '2026-05-30', '2026-05-31'],
            array_column($calendar['days'], 'date'),
        );
        $this->assertSame([1, 1, 1, 1], array_column($calendar['days'], 'flexible_ticket_count'));
    }
}
