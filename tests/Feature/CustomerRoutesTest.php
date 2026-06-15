<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\AffiliateConversion;
use App\Models\AffiliatePayoutRequest;
use App\Models\Event;
use App\Models\EventSection;
use App\Models\EventTicket;
use App\Models\Organization;
use App\Models\PromoCode;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\TempTransaction;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SeedsUserAffiliate;
use Tests\TestCase;

/**
 * Feature coverage for routes in routes/routes/customer.php (prefix /api/v1/customer).
 */
class CustomerRoutesTest extends TestCase
{
    use RefreshDatabase;
    use SeedsUserAffiliate;

    private Role $customerRole;

    private User $user;

    private User $recipientUser;

    private Event $event;

    private Schedule $schedule;

    private ScheduleTime $scheduleTime;

    private EventTicket $eventTicket;

    private Transaction $transaction;

    private Ticket $ticketForTransfer;

    private Ticket $ticketForLifecycle;

    private TempTransaction $tempTransaction;

    private Organization $organization;

    private PromoCode $promoCode;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->customerRole = Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);

        $this->user = User::factory()->create([
            'role_uuid' => $this->customerRole->uuid,
            'email' => 'owner-customer@test.com',
        ]);

        $this->recipientUser = User::factory()->create([
            'role_uuid' => $this->customerRole->uuid,
            'email' => 'recipient-customer@test.com',
        ]);

        $this->organization = Organization::create([
            'name' => 'Customer Routes Org',
            'representative_first_name' => 'Org',
            'representative_last_name' => 'Rep',
            'email' => 'org-customer-routes@test.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ]);

        $this->event = Event::create([
            'organization_uuid' => $this->organization->uuid,
            'event_name' => 'Customer Routes Event',
            'event_description' => 'Testing customer APIs',
            'contact_email' => 'event@test.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['SEAT_SELECTION'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
            'tags' => [],
            'affiliate_enabled' => true,
            'affiliate_commission_percent' => 10,
        ]);

        $this->schedule = Schedule::create([
            'event_uuid' => $this->event->uuid,
            'date_from' => '2026-06-01',
            'date_to' => '2026-06-02',
            'status' => GeneralConstants::SCHEDULE_STATUSES['PUBLISHED'],
        ]);

        $this->scheduleTime = ScheduleTime::create([
            'schedule_uuid' => $this->schedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
            'status' => GeneralConstants::SCHEDULE_STATUSES['PUBLISHED'],
        ]);

        $this->eventTicket = EventTicket::create([
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $this->schedule->uuid,
            'schedule_time_uuid' => $this->scheduleTime->uuid,
            'code' => 'CRT-GA',
            'name' => 'General',
            'description' => 'GA ticket',
            'price' => 50.00,
            'is_bundle' => false,
        ]);

        $this->transaction = Transaction::create([
            'user_uuid' => $this->user->uuid,
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $this->schedule->uuid,
            'schedule_time_uuid' => $this->scheduleTime->uuid,
            'payment_method' => 'card',
            'order_number' => 'ORD-CRT-' . uniqid(),
            'total_amount' => 50.00,
            'sub_total' => 50.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
        ]);

        $this->ticketForTransfer = Ticket::create([
            'user_uuid' => $this->user->uuid,
            'transaction_uuid' => $this->transaction->uuid,
            'event_uuid' => $this->event->uuid,
            'event_ticket_uuid' => $this->eventTicket->uuid,
            'attendee_name' => 'Owner Name',
            'attendee_email' => 'owner@test.com',
            'qr_code' => 'QR-CRT-TR-' . uniqid(),
            'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
            'is_downloaded' => false,
        ]);

        $this->ticketForLifecycle = Ticket::create([
            'user_uuid' => $this->user->uuid,
            'transaction_uuid' => $this->transaction->uuid,
            'event_uuid' => $this->event->uuid,
            'event_ticket_uuid' => $this->eventTicket->uuid,
            'attendee_name' => 'Lifecycle Name',
            'attendee_email' => 'lifecycle@test.com',
            'qr_code' => 'QR-CRT-LC-' . uniqid(),
            'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
            'is_downloaded' => false,
        ]);

        $this->tempTransaction = TempTransaction::create([
            'user_uuid' => $this->user->uuid,
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $this->schedule->uuid,
            'schedule_time_uuid' => $this->scheduleTime->uuid,
            'organization_uuid' => $this->organization->uuid,
            'total_amount' => 100.00,
            'sub_total' => 100.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
        ]);

        $this->promoCode = PromoCode::create([
            'organization_uuid' => $this->organization->uuid,
            'code' => 'SAVE10CRT',
            'description' => 'Customer route test promo',
            'activityable_id' => $this->event->uuid,
            'activityable_type' => Event::class,
            'discount_type' => GeneralConstants::DISCOUNT_TYPES['PERCENTAGE'],
            'discount_value' => 10,
            'is_unlimited' => true,
            'usable_from' => now()->subDay(),
            'usable_to' => now()->addMonth(),
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
    }

    private function actingCustomer(): static
    {
        return $this->actingAs($this->user, 'api');
    }

    #[Test]
    public function customer_routes_require_authentication(): void
    {
        $routes = [
            ['GET', '/api/v1/customer/my-tickets'],
            ['GET', '/api/v1/customer/my-coupons'],
            ['GET', '/api/v1/customer/my-transactions'],
            ['GET', '/api/v1/customer/affiliate'],
            ['GET', '/api/v1/customer/temp-transactions?event_uuid=' . $this->event->uuid],
            ['GET', '/api/v1/customer/temp-transactions/' . $this->tempTransaction->uuid],
            ['GET', '/api/v1/customer/promo-codes/SAVE10CRT?event_uuid=' . $this->event->uuid],
        ];

        foreach ($routes as [$method, $uri]) {
            $response = $this->json($method, $uri);
            $response->assertStatus(401, "Expected 401 for {$method} {$uri}");
        }

        $this->postJson('/api/v1/customer/tickets/' . $this->ticketForTransfer->uuid . '/transfer-by-email', [
            'email' => $this->recipientUser->email,
        ])->assertStatus(401);

        $this->putJson('/api/v1/customer/tickets/' . $this->ticketForLifecycle->uuid, [
            'attendee_name' => 'Updated',
        ])->assertStatus(401);

        $this->patchJson('/api/v1/customer/tickets/' . $this->ticketForLifecycle->uuid . '/download')
            ->assertStatus(401);

        $this->patchJson('/api/v1/customer/tickets/' . $this->ticketForLifecycle->uuid . '/use')
            ->assertStatus(401);

        $this->postJson('/api/v1/customer/upload', [])->assertStatus(401);

        $this->postJson('/api/v1/customer/temp-transactions', [])->assertStatus(401);

        $this->putJson('/api/v1/customer/temp-transactions/' . $this->tempTransaction->uuid, [])
            ->assertStatus(401);

        $this->deleteJson('/api/v1/customer/temp-transactions/' . $this->tempTransaction->uuid)
            ->assertStatus(401);

        $this->postJson('/api/v1/customer/temp-transactions/checkout', [])->assertStatus(401);

        $this->postJson('/api/v1/customer/temp-transactions/checkout-free', [])->assertStatus(401);

        $this->postJson('/api/v1/customer/transactions/' . $this->transaction->uuid . '/complete')
            ->assertStatus(401);

        $this->postJson('/api/v1/customer/transactions/' . $this->transaction->uuid . '/cancel')
            ->assertStatus(401);

        $this->postJson('/api/v1/customer/affiliate/apply')->assertStatus(401);

        $this->getJson('/api/v1/customer/affiliate/available-events')->assertStatus(401);

        $this->getJson('/api/v1/customer/affiliate/available-fun')->assertStatus(401);

        $this->getJson('/api/v1/customer/affiliate/payout-requests')->assertStatus(401);

        $this->getJson('/api/v1/customer/affiliate/conversions')->assertStatus(401);

        $this->postJson('/api/v1/customer/affiliate/payout-requests', ['amount' => 1000])
            ->assertStatus(401);

        $this->patchJson('/api/v1/customer/affiliate/bank-details', [])->assertStatus(401);
    }

    #[Test]
    public function get_my_tickets_returns_paginated_tickets(): void
    {
        $this->actingCustomer()
            ->getJson('/api/v1/customer/my-tickets')
            ->assertStatus(200)
            ->assertJsonStructure(['data', 'meta', 'links']);
    }

    #[Test]
    public function get_my_coupons_returns_paginated_coupons(): void
    {
        $this->actingCustomer()
            ->getJson('/api/v1/customer/my-coupons')
            ->assertStatus(200)
            ->assertJsonStructure(['data', 'meta', 'links']);
    }

    #[Test]
    public function get_my_transactions_returns_paginated_transactions(): void
    {
        $this->actingCustomer()
            ->getJson('/api/v1/customer/my-transactions')
            ->assertStatus(200)
            ->assertJsonStructure(['data', 'meta', 'links']);
    }

    #[Test]
    public function transfer_ticket_by_email_succeeds_for_valid_recipient(): void
    {
        $response = $this->actingCustomer()
            ->postJson('/api/v1/customer/tickets/' . $this->ticketForTransfer->uuid . '/transfer-by-email', [
                'email' => $this->recipientUser->email,
            ]);

        $response->assertStatus(200)->assertJsonStructure(['data' => ['uuid', 'status']]);

        $this->assertSame(
            GeneralConstants::TICKET_STATUSES['TRANSFERRED'],
            $this->ticketForTransfer->fresh()->status
        );
    }

    #[Test]
    public function update_my_ticket_updates_attendee_fields(): void
    {
        $response = $this->actingCustomer()
            ->putJson('/api/v1/customer/tickets/' . $this->ticketForLifecycle->uuid, [
                'attendee_name' => 'Updated Name',
                'attendee_email' => 'updated@test.com',
            ]);

        $response->assertStatus(200);
        $this->assertSame('Updated Name', $this->ticketForLifecycle->fresh()->attendee_name);
    }

    #[Test]
    public function download_ticket_marks_ticket_as_downloaded(): void
    {
        $this->actingCustomer()
            ->patchJson('/api/v1/customer/tickets/' . $this->ticketForLifecycle->uuid . '/download')
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertTrue($this->ticketForLifecycle->fresh()->is_downloaded);
    }

    #[Test]
    public function use_ticket_marks_ticket_as_used(): void
    {
        $ticket = Ticket::create([
            'user_uuid' => $this->user->uuid,
            'transaction_uuid' => $this->transaction->uuid,
            'event_uuid' => $this->event->uuid,
            'event_ticket_uuid' => $this->eventTicket->uuid,
            'attendee_name' => 'Use Test',
            'attendee_email' => 'use@test.com',
            'qr_code' => 'QR-USE-' . uniqid(),
            'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
            'is_downloaded' => false,
        ]);

        $this->actingCustomer()
            ->patchJson('/api/v1/customer/tickets/' . $ticket->uuid . '/use')
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertSame(
            GeneralConstants::TICKET_STATUSES['USED'],
            $ticket->fresh()->status
        );
    }

    #[Test]
    public function upload_accepts_a_file(): void
    {
        $file = UploadedFile::fake()->create('ticket.pdf', 100, 'application/pdf');

        $this->actingCustomer()
            ->post('/api/v1/customer/upload', [
                'file' => $file,
                'type' => 'pdf',
            ])
            ->assertStatus(201);
    }

    #[Test]
    public function get_promo_public_code_returns_resource_when_eligible(): void
    {
        $this->actingCustomer()
            ->getJson('/api/v1/customer/promo-codes/SAVE10CRT?event_uuid=' . $this->event->uuid)
            ->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    #[Test]
    public function get_promo_by_uuid_requires_event_uuid(): void
    {
        $this->actingCustomer()
            ->getJson('/api/v1/customer/promo-codes/' . $this->promoCode->uuid)
            ->assertStatus(422)
            ->assertJsonPath('message', 'Event is required to validate this promo code');
    }

    #[Test]
    public function get_promo_by_uuid_returns_resource_when_eligible(): void
    {
        $this->actingCustomer()
            ->getJson('/api/v1/customer/promo-codes/' . $this->promoCode->uuid . '?event_uuid=' . $this->event->uuid)
            ->assertStatus(200)
            ->assertJsonPath('data.uuid', $this->promoCode->uuid)
            ->assertJsonPath('data.code', 'SAVE10CRT');
    }

    #[Test]
    public function customer_cannot_reuse_promo_code_from_paid_transaction(): void
    {
        $this->transaction->update([
            'promo_code_uuid' => $this->promoCode->uuid,
            'promo_code_discount' => 5.00,
        ]);

        $this->actingCustomer()
            ->getJson('/api/v1/customer/promo-codes/SAVE10CRT?event_uuid=' . $this->event->uuid)
            ->assertStatus(404)
            ->assertJsonPath('message', 'You have already used this promo code');

        $this->actingCustomer()
            ->getJson('/api/v1/customer/promo-codes/' . $this->promoCode->uuid . '?event_uuid=' . $this->event->uuid)
            ->assertStatus(404)
            ->assertJsonPath('message', 'You have already used this promo code');
    }

    #[Test]
    public function customer_can_use_promo_when_past_transaction_with_promo_was_not_paid(): void
    {
        Transaction::create([
            'user_uuid' => $this->user->uuid,
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $this->schedule->uuid,
            'schedule_time_uuid' => $this->scheduleTime->uuid,
            'organization_uuid' => $this->organization->uuid,
            'order_number' => 'ORD-CRT-PENDING-' . uniqid(),
            'total_amount' => 45.00,
            'sub_total' => 50.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
            'promo_code_uuid' => $this->promoCode->uuid,
            'promo_code_discount' => 5.00,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PENDING'],
            'order_status' => Transaction::ORDER_STATUS['PENDING'],
        ]);

        $this->actingCustomer()
            ->getJson('/api/v1/customer/promo-codes/SAVE10CRT?event_uuid=' . $this->event->uuid)
            ->assertStatus(200)
            ->assertJsonPath('data.code', 'SAVE10CRT');
    }

    #[Test]
    public function get_temp_transactions_list_succeeds(): void
    {
        $this->actingCustomer()
            ->getJson('/api/v1/customer/temp-transactions?event_uuid=' . $this->event->uuid)
            ->assertStatus(200);
    }

    #[Test]
    public function get_temp_transaction_by_uuid_succeeds(): void
    {
        $this->actingCustomer()
            ->getJson('/api/v1/customer/temp-transactions/' . $this->tempTransaction->uuid)
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['uuid', 'user_uuid', 'event_uuid']]);
    }

    #[Test]
    public function post_temp_transactions_validates_payload(): void
    {
        $this->actingCustomer()
            ->postJson('/api/v1/customer/temp-transactions', [])
            ->assertStatus(422);
    }

    #[Test]
    public function put_temp_transaction_validates_payload(): void
    {
        $this->actingCustomer()
            ->putJson('/api/v1/customer/temp-transactions/' . $this->tempTransaction->uuid, [])
            ->assertStatus(422);
    }

    #[Test]
    public function delete_temp_transaction_releases_seat_selection_reservation(): void
    {
        $seatHold = TempTransaction::create([
            'user_uuid' => $this->user->uuid,
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $this->schedule->uuid,
            'schedule_time_uuid' => $this->scheduleTime->uuid,
            'organization_uuid' => $this->organization->uuid,
            'total_amount' => 100.00,
            'sub_total' => 100.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
        ]);

        $this->actingCustomer()
            ->deleteJson('/api/v1/customer/temp-transactions/' . $seatHold->uuid)
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertDatabaseMissing('temp_transactions', ['uuid' => $seatHold->uuid]);
    }

    #[Test]
    public function delete_temp_transaction_rejects_non_seat_selection_events(): void
    {
        $openTicketEvent = Event::create([
            'organization_uuid' => $this->organization->uuid,
            'event_name' => 'Open Ticket Event',
            'event_description' => 'No seats',
            'contact_email' => 'open@test.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
            'tags' => [],
        ]);

        $openTempTransaction = TempTransaction::create([
            'user_uuid' => $this->user->uuid,
            'event_uuid' => $openTicketEvent->uuid,
            'organization_uuid' => $this->organization->uuid,
            'total_amount' => 50.00,
            'sub_total' => 50.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
        ]);

        $this->actingCustomer()
            ->deleteJson('/api/v1/customer/temp-transactions/' . $openTempTransaction->uuid)
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertDatabaseHas('temp_transactions', ['uuid' => $openTempTransaction->uuid]);
    }

    #[Test]
    public function checkout_validates_payload(): void
    {
        $this->actingCustomer()
            ->postJson('/api/v1/customer/temp-transactions/checkout', [])
            ->assertStatus(422);
    }

    #[Test]
    public function checkout_free_validates_payload(): void
    {
        $this->actingCustomer()
            ->postJson('/api/v1/customer/temp-transactions/checkout-free', [])
            ->assertStatus(422);
    }

    #[Test]
    public function complete_payment_succeeds_for_free_zero_amount_transaction(): void
    {
        $freeTxn = Transaction::create([
            'user_uuid' => $this->user->uuid,
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $this->schedule->uuid,
            'schedule_time_uuid' => $this->scheduleTime->uuid,
            'payment_provider' => 'free',
            'order_number' => 'ORD-FREE-CRT-' . uniqid(),
            'total_amount' => 0.00,
            'sub_total' => 0.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
            'paid_at' => now(),
        ]);

        $this->actingCustomer()
            ->postJson('/api/v1/customer/transactions/' . $freeTxn->uuid . '/complete')
            ->assertStatus(200)
            ->assertJson(['success' => true, 'payment_status' => 'paid']);
    }

    #[Test]
    public function cancel_payment_cancels_transaction(): void
    {
        $txn = Transaction::create([
            'user_uuid' => $this->user->uuid,
            'event_uuid' => $this->event->uuid,
            'schedule_uuid' => $this->schedule->uuid,
            'schedule_time_uuid' => $this->scheduleTime->uuid,
            'order_number' => 'ORD-CANCEL-CRT-' . uniqid(),
            'total_amount' => 10.00,
            'sub_total' => 10.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PENDING'],
            'order_status' => Transaction::ORDER_STATUS['PENDING'],
        ]);

        $this->actingCustomer()
            ->postJson('/api/v1/customer/transactions/' . $txn->uuid . '/cancel')
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertSame(Transaction::PAYMENT_STATUS['CANCELLED'], $txn->fresh()->payment_status);
    }

    #[Test]
    public function affiliate_show_returns_dashboard_payload(): void
    {
        $this->actingCustomer()
            ->getJson('/api/v1/customer/affiliate')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['status', 'bank_details']]);
    }

    #[Test]
    public function affiliate_apply_enrolls_new_partner(): void
    {
        $freshUser = User::factory()->create([
            'role_uuid' => $this->customerRole->uuid,
            'email' => 'affiliate-new@test.com',
        ]);

        $this->actingAs($freshUser, 'api')
            ->postJson('/api/v1/customer/affiliate/apply')
            ->assertStatus(201)
            ->assertJsonFragment(['message' => 'Welcome to the TicketOC affiliate partner program.']);
    }

    #[Test]
    public function affiliate_available_events_requires_approved_partner(): void
    {
        $this->actingCustomer()
            ->getJson('/api/v1/customer/affiliate/available-events')
            ->assertStatus(403);
    }

    #[Test]
    public function affiliate_available_events_returns_catalog_for_approved_partner(): void
    {
        $partner = User::factory()->create(['role_uuid' => $this->customerRole->uuid]);
        $this->seedApprovedAffiliate($partner, 'CRTREF1');

        $this->actingAs($partner, 'api')
            ->getJson('/api/v1/customer/affiliate/available-events')
            ->assertStatus(200)
            ->assertJsonStructure(['data', 'meta', 'links']);
    }

    #[Test]
    public function affiliate_available_fun_requires_amusement_section_and_approved_partner(): void
    {
        EventSection::create([
            'name' => EventSection::AMUSEMENT_SECTION,
            'title' => 'Amusements',
            'status' => 'active',
        ]);

        $partner = User::factory()->create(['role_uuid' => $this->customerRole->uuid]);
        $this->seedApprovedAffiliate($partner, 'CRTREF2');

        $this->actingAs($partner, 'api')
            ->getJson('/api/v1/customer/affiliate/available-fun')
            ->assertStatus(200)
            ->assertJsonStructure(['data', 'meta', 'links']);
    }

    #[Test]
    public function affiliate_payout_and_conversions_require_partner_status(): void
    {
        $this->actingCustomer()
            ->getJson('/api/v1/customer/affiliate/payout-requests')
            ->assertStatus(403);

        $this->actingCustomer()
            ->getJson('/api/v1/customer/affiliate/conversions')
            ->assertStatus(403);
    }

    #[Test]
    public function affiliate_payout_history_and_conversions_succeed_for_approved_partner(): void
    {
        $partner = User::factory()->create(['role_uuid' => $this->customerRole->uuid]);
        $this->seedApprovedAffiliate($partner, 'CRTREF3');

        $conversion = AffiliateConversion::create([
            'partner_user_uuid' => $partner->uuid,
            'transaction_uuid' => $this->transaction->uuid,
            'event_uuid' => $this->event->uuid,
            'order_total' => 10000,
            'commission_percent' => 10,
            'commission_amount' => 1000,
        ]);

        DB::table('affiliate_conversions')->where('uuid', $conversion->uuid)->update([
            'created_at' => '2020-01-01 00:00:00',
            'updated_at' => '2020-01-01 00:00:00',
        ]);

        $this->actingAs($partner, 'api')
            ->getJson('/api/v1/customer/affiliate/conversions')
            ->assertStatus(200)
            ->assertJsonStructure(['data']);

        $this->actingAs($partner, 'api')
            ->getJson('/api/v1/customer/affiliate/payout-requests')
            ->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    #[Test]
    public function affiliate_store_payout_request_creates_pending_request_when_eligible(): void
    {
        $partner = User::factory()->create(['role_uuid' => $this->customerRole->uuid]);
        $this->seedApprovedAffiliate($partner, 'CRTREF4');

        $conversion = AffiliateConversion::create([
            'partner_user_uuid' => $partner->uuid,
            'transaction_uuid' => $this->transaction->uuid,
            'event_uuid' => $this->event->uuid,
            'order_total' => 20000,
            'commission_percent' => 10,
            'commission_amount' => 2000,
        ]);

        DB::table('affiliate_conversions')->where('uuid', $conversion->uuid)->update([
            'created_at' => '2020-01-01 00:00:00',
            'updated_at' => '2020-01-01 00:00:00',
        ]);

        $this->actingAs($partner, 'api')
            ->postJson('/api/v1/customer/affiliate/payout-requests', ['amount' => 1000])
            ->assertStatus(201)
            ->assertJsonStructure(['data']);
    }

    #[Test]
    public function affiliate_update_bank_details_succeeds_for_approved_partner(): void
    {
        $partner = User::factory()->create(['role_uuid' => $this->customerRole->uuid]);
        $this->seedApprovedAffiliate($partner, 'CRTREF5');

        $this->actingAs($partner, 'api')
            ->patchJson('/api/v1/customer/affiliate/bank-details', [
                'bank' => 'BDO',
                'branch' => 'Makati',
                'account_name' => 'Partner Account',
                'account_number' => '1234567890',
                'tin' => '123-456-789-000',
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.bank_details.bank', 'BDO');
    }
}
