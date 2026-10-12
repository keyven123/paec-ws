<?php

namespace Tests\Feature\Coverage\MiscControllers;

use App\Constants\GeneralConstants;
use App\Models\Event;
use App\Models\EventTicketCoupon;
use App\Models\TicketCoupon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TicketCouponControllerCoverageTest extends TestCase
{
    use MiscFixtures;
    use RefreshDatabase;

    private Event $event;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->event = $this->createEvent();
        $this->customer = $this->createCustomer();
    }

    private function makeCoupon(array $attributes = []): TicketCoupon
    {
        $ticket = $this->createTicket($this->createTransaction($this->customer, $this->event));
        $eventTicketCoupon = EventTicketCoupon::create([
            'event_ticket_uuid' => $ticket->event_ticket_uuid,
            'name' => 'Free Drink',
        ]);

        return TicketCoupon::create(array_merge([
            'user_uuid' => $this->customer->uuid,
            'ticket_uuid' => $ticket->uuid,
            'event_uuid' => $this->event->uuid,
            'event_ticket_coupon_uuid' => $eventTicketCoupon->uuid,
            'name' => 'Free Drink',
            'qr_code' => 'QR-COUPON-1',
            'status' => GeneralConstants::TICKET_COUPON_STATUSES['ACTIVE'],
        ], $attributes));
    }

    #[Test]
    public function getByQrCodeReturnsActiveCoupon(): void
    {
        $coupon = $this->makeCoupon();

        $this->withHeaders($this->adminHeaders())
            ->getJson('/api/v1/ticket-coupons/qr-code/details?' . http_build_query([
                'qr_code' => 'QR-COUPON-1',
                'event_uuid' => $this->event->uuid,
            ]))
            ->assertOk()
            ->assertJsonPath('data.uuid', $coupon->uuid)
            ->assertJsonPath('data.qr_code', 'QR-COUPON-1');
    }

    #[Test]
    public function getByQrCodeReturns404WhenCouponIsNotActive(): void
    {
        $this->makeCoupon(['status' => GeneralConstants::TICKET_COUPON_STATUSES['CLAIMED']]);

        $this->withHeaders($this->adminHeaders())
            ->getJson('/api/v1/ticket-coupons/qr-code/details?' . http_build_query([
                'qr_code' => 'QR-COUPON-1',
                'event_uuid' => $this->event->uuid,
            ]))
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Invalid coupon.']);
    }

    #[Test]
    public function confirmClaimedMarksCouponClaimedByScanningAdmin(): void
    {
        $coupon = $this->makeCoupon();

        $this->withHeaders($this->adminHeaders())
            ->postJson("/api/v1/ticket-coupons/{$coupon->uuid}/confirm-claimed")
            ->assertOk()
            ->assertJsonPath('data.uuid', $coupon->uuid)
            ->assertJsonPath('data.status', GeneralConstants::TICKET_COUPON_STATUSES['CLAIMED']);

        $coupon->refresh();
        $this->assertSame(GeneralConstants::TICKET_COUPON_STATUSES['CLAIMED'], $coupon->status);
        $this->assertNotNull($coupon->claimed_at);
        $this->assertSame($this->superAdmin->uuid, $coupon->scanned_by);
    }

    #[Test]
    public function confirmClaimedReturns404ForUnknownCoupon(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->postJson('/api/v1/ticket-coupons/00000000-0000-0000-0000-000000000000/confirm-claimed')
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Coupon not found']);
    }
}
