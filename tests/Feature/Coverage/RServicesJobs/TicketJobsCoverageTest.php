<?php

namespace Tests\Feature\Coverage\RServicesJobs;

use App\Constants\GeneralConstants;
use App\Jobs\DeleteUserTicketCouponsJob;
use App\Jobs\SuccessPaymentProcess;
use App\Jobs\UpdateUserTicketCouponsJob;
use App\Models\EventTicketCoupon;
use App\Models\Organization;
use App\Models\PromoCode;
use App\Models\Ticket;
use App\Models\TicketCoupon;
use App\Models\TicketSeat;
use App\Models\Transaction;
use App\Models\Venue;
use App\Models\VenueSeat;
use App\Notifications\PaymentSuccessfulNotification;
use App\Services\MetaPixelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SeedsUserAffiliate;
use Tests\Feature\Coverage\RServicesJobs\Concerns\CreatesRServicesJobsFixtures;
use Tests\TestCase;

class TicketJobsCoverageTest extends TestCase
{
    use CreatesRServicesJobsFixtures;
    use RefreshDatabase;
    use SeedsUserAffiliate;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Http::fake();
        Notification::fake();
    }

    private function ticketCoupon(Ticket $ticket, EventTicketCoupon $eventCoupon, array $attributes = []): TicketCoupon
    {
        return TicketCoupon::create(array_merge([
            'user_uuid' => $ticket->user_uuid,
            'ticket_uuid' => $ticket->uuid,
            'event_uuid' => $ticket->event_uuid,
            'event_ticket_coupon_uuid' => $eventCoupon->uuid,
            'name' => $eventCoupon->name,
            'qr_code' => 'COUPON_'.Str::upper(Str::random(10)),
            'status' => GeneralConstants::TICKET_COUPON_STATUSES['ACTIVE'],
        ], $attributes));
    }

    // --- DeleteUserTicketCouponsJob ---

    #[Test]
    public function deleteUserTicketCouponsJobDeletesCouponsOfRemovedEventCoupons(): void
    {
        $customer = $this->createRsjUser();
        $event = $this->createRsjEvent();
        $eventTicket = $this->createRsjEventTicket($event);
        $ticket = $this->createRsjTicket($this->createRsjTransaction($event, $customer), $eventTicket);
        $removed = EventTicketCoupon::create(['event_ticket_uuid' => $eventTicket->uuid, 'name' => 'Free drink']);
        $kept = EventTicketCoupon::create(['event_ticket_uuid' => $eventTicket->uuid, 'name' => 'Free popcorn']);
        $this->ticketCoupon($ticket, $removed);
        $this->ticketCoupon($ticket, $kept);

        (new DeleteUserTicketCouponsJob([$removed->uuid]))->handle();

        $this->assertDatabaseMissing('ticket_coupons', ['event_ticket_coupon_uuid' => $removed->uuid]);
        $this->assertDatabaseHas('ticket_coupons', ['event_ticket_coupon_uuid' => $kept->uuid]);
    }

    #[Test]
    public function deleteUserTicketCouponsJobIgnoresEmptyList(): void
    {
        $customer = $this->createRsjUser();
        $event = $this->createRsjEvent();
        $eventTicket = $this->createRsjEventTicket($event);
        $ticket = $this->createRsjTicket($this->createRsjTransaction($event, $customer), $eventTicket);
        $this->ticketCoupon($ticket, EventTicketCoupon::create(['event_ticket_uuid' => $eventTicket->uuid, 'name' => 'Snack']));

        (new DeleteUserTicketCouponsJob([]))->handle();

        $this->assertDatabaseCount('ticket_coupons', 1);
    }

    // --- UpdateUserTicketCouponsJob ---

    #[Test]
    public function updateUserTicketCouponsJobIgnoresUnknownEventTicket(): void
    {
        (new UpdateUserTicketCouponsJob((string) Str::uuid()))->handle();

        $this->assertDatabaseCount('ticket_coupons', 0);
    }

    #[Test]
    public function updateUserTicketCouponsJobCreatesMissingAndRenamesChangedCoupons(): void
    {
        $customer = $this->createRsjUser();
        $event = $this->createRsjEvent();
        $eventTicket = $this->createRsjEventTicket($event);
        $ticket = $this->createRsjTicket($this->createRsjTransaction($event, $customer), $eventTicket);

        $renamed = EventTicketCoupon::create(['event_ticket_uuid' => $eventTicket->uuid, 'name' => 'Large drink']);
        $unchanged = EventTicketCoupon::create(['event_ticket_uuid' => $eventTicket->uuid, 'name' => 'Popcorn']);
        $added = EventTicketCoupon::create(['event_ticket_uuid' => $eventTicket->uuid, 'name' => 'Parking']);
        $this->ticketCoupon($ticket, $renamed, ['name' => 'Small drink']);
        $this->ticketCoupon($ticket, $unchanged);

        (new UpdateUserTicketCouponsJob($eventTicket->uuid))->handle();

        $this->assertDatabaseHas('ticket_coupons', [
            'ticket_uuid' => $ticket->uuid,
            'event_ticket_coupon_uuid' => $renamed->uuid,
            'name' => 'Large drink',
        ]);
        $this->assertDatabaseHas('ticket_coupons', [
            'ticket_uuid' => $ticket->uuid,
            'event_ticket_coupon_uuid' => $unchanged->uuid,
            'name' => 'Popcorn',
        ]);
        $created = TicketCoupon::where('event_ticket_coupon_uuid', $added->uuid)->first();
        $this->assertNotNull($created);
        $this->assertSame($ticket->uuid, $created->ticket_uuid);
        $this->assertSame($customer->uuid, $created->user_uuid);
        $this->assertSame($event->uuid, $created->event_uuid);
        $this->assertSame('Parking', $created->name);
        $this->assertSame(GeneralConstants::TICKET_COUPON_STATUSES['ACTIVE'], $created->status);
        $this->assertStringStartsWith('COUPON_', $created->qr_code);
        $this->assertSame(3, TicketCoupon::where('ticket_uuid', $ticket->uuid)->count());
    }

    // --- SuccessPaymentProcess ---

    #[Test]
    public function successPaymentProcessCountsPromoUsageAndReservesSeats(): void
    {
        $venue = Venue::create(['name' => 'Arena', 'code' => 'ARENA-'.Str::random(4), 'type' => Venue::TYPES['MUSICAL']]);
        $seat = VenueSeat::create(['venue_uuid' => $venue->uuid, 'col' => 3, 'row' => 2, 'seat_no' => 7, 'category' => 'VIP', 'color' => '#ff0000']);
        $customer = $this->createRsjUser();
        $event = $this->createRsjEvent(['venue_uuid' => $venue->uuid, 'ticket_prefix' => 'ARN_', 'total_revenue' => 0, 'total_orders' => 0, 'ticket_sold' => 0]);
        $eventTicket = $this->createRsjEventTicket($event, ['sold_ticket' => 0]);
        $promo = PromoCode::create([
            'organization_uuid' => Organization::factory()->create()->uuid,
            'code' => 'RSJ10',
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
            'discount_value' => 10,
            'is_unlimited' => true,
            'used_count' => 2,
            'usable_from' => now()->subDay(),
            'usable_to' => now()->addMonth(),
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
        $transaction = $this->createRsjTransaction($event, $customer, ['promo_code_uuid' => $promo->uuid, 'total_amount' => 900]);
        $seated = $this->createRsjTicket($transaction, $eventTicket, [
            'venue_seat_uuid' => $seat->uuid,
            'col' => 3,
            'row' => 2,
            'status' => GeneralConstants::TICKET_STATUSES['PENDING'],
        ]);
        $seated->delete();

        (new SuccessPaymentProcess($transaction->uuid))->handle();

        $this->assertSame(3, (int) $promo->fresh()->used_count);
        $ticketSeat = TicketSeat::where('ticket_uuid', $seated->uuid)->first();
        $this->assertNotNull($ticketSeat);
        $this->assertSame($venue->uuid, $ticketSeat->venue_uuid);
        $this->assertSame($seat->uuid, $ticketSeat->venue_seat_uuid);
        $this->assertSame('3-2', (string) DB::table('ticket_seats')->where('ticket_uuid', $seated->uuid)->value('seat_no'));
        $this->assertSame('VIP', $ticketSeat->category);
        $this->assertSame('#ff0000', $ticketSeat->color);

        $seated = Ticket::find($seated->uuid);
        $this->assertNotNull($seated);
        $this->assertSame(GeneralConstants::TICKET_STATUSES['ACTIVE'], $seated->status);
        $this->assertStringStartsWith('ARN_', $seated->qr_code);
        $this->assertEquals(900, (float) $event->fresh()->total_revenue);
        $this->assertSame(1, (int) $event->fresh()->ticket_sold);
        $this->assertSame(1, (int) $eventTicket->fresh()->sold_ticket);
        $this->assertNull($transaction->fresh()->qr_code);
        Notification::assertSentTo($customer, PaymentSuccessfulNotification::class);
    }

    #[Test]
    public function successPaymentProcessKeepsTheGroupQrOnlyForMultiTicketOrders(): void
    {
        $customer = $this->createRsjUser();
        $event = $this->createRsjEvent();
        $eventTicket = $this->createRsjEventTicket($event);
        $transaction = $this->createRsjTransaction($event, $customer);
        $this->createRsjTicket($transaction, $eventTicket, ['status' => GeneralConstants::TICKET_STATUSES['PENDING']]);
        $this->createRsjTicket($transaction, $eventTicket, ['status' => GeneralConstants::TICKET_STATUSES['PENDING']]);
        $groupQr = $transaction->fresh()->qr_code;

        (new SuccessPaymentProcess($transaction->uuid))->handle();

        $this->assertStringStartsWith(Transaction::ORDER_QR_PREFIX, $groupQr);
        $this->assertSame($groupQr, $transaction->fresh()->qr_code);
    }

    #[Test]
    public function successPaymentProcessLogsAffiliateAndMetaPixelFailuresWithoutAborting(): void
    {
        $partner = $this->createRsjUser('Partner');
        $this->seedApprovedAffiliate($partner, 'RSJSPP');
        $customer = $this->createRsjUser();
        $event = $this->createRsjEvent(['affiliate_enabled' => true, 'affiliate_commission_percent' => 10]);
        DB::table('events')->where('uuid', $event->uuid)->update(['affiliate_ends_at' => 'not-a-date']);
        $eventTicket = $this->createRsjEventTicket($event);
        $transaction = $this->createRsjTransaction($event, $customer, ['affiliate_partner_uuid' => $partner->uuid]);
        $ticket = $this->createRsjTicket($transaction, $eventTicket, ['status' => GeneralConstants::TICKET_STATUSES['PENDING']]);

        $metaPixel = $this->mock(MetaPixelService::class);
        $metaPixel->shouldReceive('trackPurchase')->once()->andThrow(new \RuntimeException('pixel down'));
        Log::spy();

        (new SuccessPaymentProcess($transaction->uuid))->handle();

        Log::shouldHaveReceived('error')->with(
            'Affiliate conversion recording failed in SuccessPaymentProcess',
            \Mockery::on(fn (array $context) => $context['transaction_uuid'] === $transaction->uuid && $context['error'] !== ''),
        )->once();
        Log::shouldHaveReceived('error')->with('Meta Pixel Purchase tracking failed in SuccessPaymentProcess', [
            'transaction_uuid' => $transaction->uuid,
            'error' => 'pixel down',
        ])->once();
        $this->assertDatabaseCount('affiliate_conversions', 0);
        $this->assertSame(GeneralConstants::TICKET_STATUSES['ACTIVE'], $ticket->fresh()->status);
        Notification::assertSentTo($customer, PaymentSuccessfulNotification::class);
        $this->assertDatabaseHas('platform_notifications', [
            'notifiable_uuid' => $customer->uuid,
            'type' => 'ticket_purchase',
        ]);
    }
}
