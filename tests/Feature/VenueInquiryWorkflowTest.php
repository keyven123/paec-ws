<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\Role;
use App\Models\User;
use App\Models\AdminUser;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Transaction;
use App\Models\VenueInquiry;
use App\Models\VenueListing;
use App\Notifications\VenueBalancePaidNotification;
use App\Notifications\VenueDepositPaidNotification;
use App\Notifications\VenueEventCompletedCustomerNotification;
use App\Notifications\VenueEventCompletedMerchantNotification;
use App\Notifications\VenueProposalAcceptedNotification;
use App\Notifications\VenueProposalDeclinedNotification;
use App\Notifications\VenueProposalSentNotification;
use App\Notifications\VenueVisitCustomerResponseNotification;
use App\Services\VenueInquiryWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesVenueListingFixtures;
use Tests\TestCase;

class VenueInquiryWorkflowTest extends TestCase
{
    use CreatesVenueListingFixtures;
    use RefreshDatabase;

    private Role $customerRole;
    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpVenueListingAdmin();
        Storage::fake('public');

        $this->customerRole = Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);

        $this->customer = User::factory()->create([
            'role_uuid' => $this->customerRole->uuid,
            'email' => 'workflow-customer@test.com',
        ]);
    }

    private function createCustomerInquiry(array $overrides = []): VenueInquiry
    {
        $listing = $this->createVenueListing(['status' => VenueListing::STATUSES['PUBLISHED']]);

        return VenueInquiry::factory()->create(array_merge([
            'venue_listing_uuid' => $listing->uuid,
            'user_uuid' => $this->customer->uuid,
            'email' => $this->customer->email,
            'site_visit' => VenueInquiry::SITE_VISIT_NO,
            'status' => VenueInquiry::STATUSES['IN_DISCUSSION'],
        ], $overrides));
    }

    #[Test]
    public function it_sends_proposal_email_and_attaches_file_to_chat(): void
    {
        Notification::fake();

        $inquiry = $this->createCustomerInquiry([
            'event_date' => now()->addMonths(2)->toDateString(),
        ]);

        $this->withHeaders($this->withVenueAdminHeaders())
            ->postJson('/api/v1/venue-listings/inquiries/' . $inquiry->uuid . '/proposal', [
                'proposal_amount' => 85000,
                'proposal_valid_until' => now()->addWeek()->toDateString(),
                'file' => UploadedFile::fake()->create('proposal.pdf', 100, 'application/pdf'),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', VenueInquiry::STATUSES['PROPOSAL_SENT']);

        Notification::assertSentTo($this->customer, VenueProposalSentNotification::class);

        $proposalMessage = ChatMessage::query()
            ->where('message_type', ChatMessage::TYPE_PROPOSAL_CARD)
            ->first();

        $this->assertNotNull($proposalMessage);
        $this->assertNotNull($proposalMessage->attachment_upload_uuid);
        $this->assertSame('proposal.pdf', $proposalMessage->attachment_name);
    }

    #[Test]
    public function it_notifies_merchant_and_posts_declined_card_when_customer_declines_proposal(): void
    {
        Notification::fake();

        $inquiry = $this->createCustomerInquiry([
            'event_date' => now()->addMonths(2)->toDateString(),
            'status' => VenueInquiry::STATUSES['PROPOSAL_SENT'],
            'proposal_amount' => 10000,
            'proposal_valid_until' => now()->addWeek()->toDateString(),
        ]);

        $listing = VenueListing::query()->findOrFail($inquiry->venue_listing_uuid);
        $organization = $listing->organization;
        $this->assertNotNull($organization);

        $this->actingAs($this->customer, 'api')
            ->postJson('/api/v1/customer/venue-inquiries/' . $inquiry->uuid . '/decline-proposal')
            ->assertOk()
            ->assertJsonPath('data.status', VenueInquiry::STATUSES['IN_DISCUSSION']);

        Notification::assertSentTo($organization, VenueProposalDeclinedNotification::class);

        $this->assertDatabaseHas('chat_messages', [
            'sender_type' => ChatThread::SENDER_CUSTOMER,
            'sender_uuid' => $this->customer->uuid,
            'body' => 'Decline',
            'message_type' => ChatMessage::TYPE_TEXT,
        ]);

        $declinedMessage = ChatMessage::query()
            ->where('message_type', ChatMessage::TYPE_PROPOSAL_DECLINED_CARD)
            ->first();

        $this->assertNotNull($declinedMessage);
        $this->assertStringContainsString('declined the proposal', strtolower($declinedMessage->body));
    }

    #[Test]
    public function it_notifies_merchant_when_customer_accepts_scheduled_visit(): void
    {
        Notification::fake();

        $inquiry = $this->createCustomerInquiry([
            'site_visit' => VenueInquiry::SITE_VISIT_YES,
            'status' => VenueInquiry::STATUSES['SITE_VISIT_SCHEDULED'],
            'visit_scheduled_date' => now()->addDays(5)->toDateString(),
            'visit_scheduled_time' => '14:00:00',
        ]);

        $listing = VenueListing::query()->findOrFail($inquiry->venue_listing_uuid);
        $organization = $listing->organization;
        $this->assertNotNull($organization);

        $this->actingAs($this->customer, 'api')
            ->postJson('/api/v1/customer/venue-inquiries/' . $inquiry->uuid . '/accept-visit')
            ->assertOk()
            ->assertJsonPath('data.status', VenueInquiry::STATUSES['SITE_VISIT_SCHEDULED']);

        Notification::assertSentTo($organization, VenueVisitCustomerResponseNotification::class);

        $this->assertDatabaseHas('chat_messages', [
            'sender_type' => ChatThread::SENDER_CUSTOMER,
            'body' => 'Accept visit',
        ]);
    }

    #[Test]
    public function it_returns_inquiry_to_discussion_when_customer_declines_scheduled_visit(): void
    {
        Notification::fake();

        $inquiry = $this->createCustomerInquiry([
            'site_visit' => VenueInquiry::SITE_VISIT_YES,
            'status' => VenueInquiry::STATUSES['SITE_VISIT_SCHEDULED'],
            'visit_scheduled_date' => now()->addDays(5)->toDateString(),
            'visit_scheduled_time' => '14:00:00',
        ]);

        $listing = VenueListing::query()->findOrFail($inquiry->venue_listing_uuid);
        $organization = $listing->organization;
        $this->assertNotNull($organization);

        $this->actingAs($this->customer, 'api')
            ->postJson('/api/v1/customer/venue-inquiries/' . $inquiry->uuid . '/decline-visit')
            ->assertOk()
            ->assertJsonPath('data.status', VenueInquiry::STATUSES['IN_DISCUSSION']);

        Notification::assertSentTo($organization, VenueVisitCustomerResponseNotification::class);

        $this->assertDatabaseHas('venue_inquiries', [
            'uuid' => $inquiry->uuid,
            'status' => VenueInquiry::STATUSES['IN_DISCUSSION'],
            'visit_scheduled_date' => null,
        ]);

        $this->assertDatabaseHas('chat_messages', [
            'message_type' => ChatMessage::TYPE_VISIT_DECLINED_CARD,
        ]);
    }

    #[Test]
    public function it_returns_inquiry_to_discussion_when_customer_suggests_visit_date(): void
    {
        Notification::fake();

        $inquiry = $this->createCustomerInquiry([
            'site_visit' => VenueInquiry::SITE_VISIT_YES,
            'status' => VenueInquiry::STATUSES['SITE_VISIT_SCHEDULED'],
            'visit_scheduled_date' => now()->addDays(5)->toDateString(),
            'visit_scheduled_time' => '14:00:00',
        ]);

        $listing = VenueListing::query()->findOrFail($inquiry->venue_listing_uuid);
        $organization = $listing->organization;
        $this->assertNotNull($organization);

        $suggestedDate = now()->addDays(10)->toDateString();

        $this->actingAs($this->customer, 'api')
            ->postJson('/api/v1/customer/venue-inquiries/' . $inquiry->uuid . '/suggest-visit-date', [
                'suggested_date' => $suggestedDate,
                'suggested_time' => '10:30',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', VenueInquiry::STATUSES['IN_DISCUSSION']);

        Notification::assertSentTo($organization, VenueVisitCustomerResponseNotification::class);

        $this->assertDatabaseHas('venue_inquiries', [
            'uuid' => $inquiry->uuid,
            'visit_scheduled_date' => null,
        ]);

        $this->assertDatabaseHas('chat_messages', [
            'message_type' => ChatMessage::TYPE_VISIT_SUGGESTED_CARD,
        ]);
    }

    #[Test]
    public function it_notifies_merchant_and_posts_accept_message_when_customer_accepts_proposal(): void
    {
        Notification::fake();

        $inquiry = $this->createCustomerInquiry([
            'event_date' => now()->addMonths(2)->toDateString(),
            'status' => VenueInquiry::STATUSES['PROPOSAL_SENT'],
            'proposal_amount' => 15000,
            'proposal_valid_until' => now()->addWeek()->toDateString(),
        ]);

        $listing = VenueListing::query()->findOrFail($inquiry->venue_listing_uuid);
        $organization = $listing->organization;
        $this->assertNotNull($organization);

        $this->actingAs($this->customer, 'api')
            ->postJson('/api/v1/customer/venue-inquiries/' . $inquiry->uuid . '/accept-proposal')
            ->assertOk()
            ->assertJsonPath('data.status', VenueInquiry::STATUSES['ACCEPTED']);

        Notification::assertSentTo($organization, VenueProposalAcceptedNotification::class);

        $this->assertDatabaseHas('chat_messages', [
            'sender_type' => ChatThread::SENDER_CUSTOMER,
            'sender_uuid' => $this->customer->uuid,
            'body' => 'Accept Proposal',
            'message_type' => ChatMessage::TYPE_TEXT,
        ]);
    }

    #[Test]
    public function it_runs_skip_site_visit_proposal_flow(): void
    {
        $inquiry = $this->createCustomerInquiry([
            'event_date' => now()->addMonths(2)->toDateString(),
        ]);

        $proposalResponse = $this->withHeaders($this->withVenueAdminHeaders())
            ->postJson('/api/v1/venue-listings/inquiries/' . $inquiry->uuid . '/proposal', [
                'proposal_amount' => 85000,
                'proposal_valid_until' => now()->addWeek()->toDateString(),
                'file' => UploadedFile::fake()->create('proposal.pdf', 100, 'application/pdf'),
            ]);

        $proposalResponse->assertOk()
            ->assertJsonPath('data.status', VenueInquiry::STATUSES['PROPOSAL_SENT']);

        $acceptResponse = $this->actingAs($this->customer, 'api')
            ->postJson('/api/v1/customer/venue-inquiries/' . $inquiry->uuid . '/accept-proposal');

        $acceptResponse->assertOk()
            ->assertJsonPath('data.status', VenueInquiry::STATUSES['ACCEPTED']);

        $depositResponse = $this->withHeaders($this->withVenueAdminHeaders())
            ->postJson('/api/v1/venue-listings/inquiries/' . $inquiry->uuid . '/deposit-request', [
                'deposit_amount' => 25000,
                'deposit_due_date' => now()->addDays(7)->toDateString(),
            ]);

        $depositResponse->assertOk()
            ->assertJsonPath('data.status', VenueInquiry::STATUSES['DEPOSIT_REQUESTED'])
            ->assertJsonPath('data.deposit_amount', 25000);
    }

    #[Test]
    public function it_notifies_merchant_when_customer_pays_deposit(): void
    {
        Notification::fake();

        $inquiry = $this->createCustomerInquiry([
            'event_date' => now()->addMonths(2)->toDateString(),
            'status' => VenueInquiry::STATUSES['DEPOSIT_REQUESTED'],
            'deposit_amount' => 25000,
            'deposit_due_date' => now()->addDays(7)->toDateString(),
        ]);

        $listing = VenueListing::query()->findOrFail($inquiry->venue_listing_uuid);
        $organization = $listing->organization;
        $this->assertNotNull($organization);

        $merchantAdmin = AdminUser::create([
            'role_uuid' => $this->venueAdminRole->uuid,
            'organization_uuid' => $organization->uuid,
            'email' => 'deposit-merchant@test.com',
            'password' => 'password123',
            'first_name' => 'Deposit',
            'last_name' => 'Merchant',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $transaction = Transaction::create([
            'user_uuid' => $this->customer->uuid,
            'transactionable_type' => 'venue_inquiry',
            'transactionable_uuid' => $inquiry->uuid,
            'organization_uuid' => $organization->uuid,
            'order_number' => 'VEN-DEP-' . strtoupper(substr($inquiry->uuid, 0, 8)),
            'sub_total' => 25000,
            'tax_amount' => 0,
            'discount' => 0,
            'total_amount' => 25000,
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'payment_data' => [
                'venue_payment_phase' => VenueInquiry::PAYMENT_PHASE_DEPOSIT,
                'venue_inquiry_uuid' => $inquiry->uuid,
            ],
        ]);

        /** @var VenueInquiryWorkflowService $workflow */
        $workflow = app(VenueInquiryWorkflowService::class);
        $workflow->handleDepositPaid($inquiry, $transaction);

        Notification::assertSentTo($organization, VenueDepositPaidNotification::class);

        $this->assertDatabaseHas('platform_notifications', [
            'notifiable_type' => AdminUser::class,
            'notifiable_uuid' => $merchantAdmin->uuid,
            'type' => 'deposit_paid',
            'title' => 'Deposit received',
        ]);

        $this->assertDatabaseHas('platform_notifications', [
            'notifiable_type' => User::class,
            'notifiable_uuid' => $this->customer->uuid,
            'type' => 'deposit_paid',
            'title' => 'Deposit Paid',
        ]);

        $this->assertDatabaseHas('chat_messages', [
            'message_type' => ChatMessage::TYPE_DEPOSIT_PAID_CARD,
        ]);

        $this->assertDatabaseHas('venue_inquiries', [
            'uuid' => $inquiry->uuid,
            'status' => VenueInquiry::STATUSES['DEPOSIT_PAID'],
        ]);
    }

    #[Test]
    public function it_notifies_merchant_when_customer_pays_balance(): void
    {
        Notification::fake();

        $inquiry = $this->createCustomerInquiry([
            'event_date' => now()->addMonths(2)->toDateString(),
            'status' => VenueInquiry::STATUSES['BALANCE_DUE'],
            'deposit_amount' => 25000,
            'deposit_paid_at' => now()->subDays(3),
            'balance_amount' => 75000,
            'additional_charges' => 5000,
            'balance_due_date' => now()->addDays(14)->toDateString(),
        ]);

        $listing = VenueListing::query()->findOrFail($inquiry->venue_listing_uuid);
        $listing->update(['bookings_count' => 4]);
        $organization = $listing->organization;
        $this->assertNotNull($organization);

        $merchantAdmin = AdminUser::create([
            'role_uuid' => $this->venueAdminRole->uuid,
            'organization_uuid' => $organization->uuid,
            'email' => 'balance-merchant@test.com',
            'password' => 'password123',
            'first_name' => 'Balance',
            'last_name' => 'Merchant',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $transaction = Transaction::create([
            'user_uuid' => $this->customer->uuid,
            'transactionable_type' => 'venue_inquiry',
            'transactionable_uuid' => $inquiry->uuid,
            'organization_uuid' => $organization->uuid,
            'order_number' => 'VEN-BAL-' . strtoupper(substr($inquiry->uuid, 0, 8)),
            'sub_total' => 80000,
            'tax_amount' => 0,
            'discount' => 0,
            'total_amount' => 80000,
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'payment_data' => [
                'venue_payment_phase' => VenueInquiry::PAYMENT_PHASE_BALANCE,
                'venue_inquiry_uuid' => $inquiry->uuid,
            ],
        ]);

        /** @var VenueInquiryWorkflowService $workflow */
        $workflow = app(VenueInquiryWorkflowService::class);
        $workflow->handleFullyPaid($inquiry, $transaction);

        Notification::assertSentTo($organization, VenueBalancePaidNotification::class);

        $this->assertDatabaseHas('platform_notifications', [
            'notifiable_type' => AdminUser::class,
            'notifiable_uuid' => $merchantAdmin->uuid,
            'type' => 'fully_paid',
            'title' => 'Booking fully paid',
        ]);

        $this->assertDatabaseHas('platform_notifications', [
            'notifiable_type' => User::class,
            'notifiable_uuid' => $this->customer->uuid,
            'type' => 'fully_paid',
            'title' => 'Payment Completed',
        ]);

        $this->assertDatabaseHas('chat_messages', [
            'message_type' => ChatMessage::TYPE_FULLY_PAID_CARD,
        ]);

        $this->assertDatabaseHas('venue_inquiries', [
            'uuid' => $inquiry->uuid,
            'status' => VenueInquiry::STATUSES['FULLY_PAID'],
        ]);

        $this->assertDatabaseHas('venue_listings', [
            'uuid' => $listing->uuid,
            'bookings_count' => 5,
        ]);
    }

    #[Test]
    public function it_notifies_customer_and_merchant_when_inquiry_is_marked_completed(): void
    {
        Notification::fake();

        $inquiry = $this->createCustomerInquiry([
            'event_date' => now()->addMonths(2)->toDateString(),
            'event_type' => 'Wedding',
            'status' => VenueInquiry::STATUSES['FULLY_PAID'],
            'deposit_amount' => 25000,
            'balance_amount' => 75000,
        ]);

        $listing = VenueListing::query()->findOrFail($inquiry->venue_listing_uuid);
        $organization = $listing->organization;
        $this->assertNotNull($organization);

        $merchantAdmin = AdminUser::create([
            'role_uuid' => $this->venueAdminRole->uuid,
            'organization_uuid' => $organization->uuid,
            'email' => 'complete-merchant@test.com',
            'password' => 'password123',
            'first_name' => 'Complete',
            'last_name' => 'Merchant',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        /** @var VenueInquiryWorkflowService $workflow */
        $workflow = app(VenueInquiryWorkflowService::class);
        $workflow->transition($inquiry->fresh(), VenueInquiry::STATUSES['COMPLETED']);

        Notification::assertSentTo($this->customer, VenueEventCompletedCustomerNotification::class);
        Notification::assertSentTo($organization, VenueEventCompletedMerchantNotification::class);

        $this->assertDatabaseHas('platform_notifications', [
            'notifiable_type' => User::class,
            'notifiable_uuid' => $this->customer->uuid,
            'type' => 'event_completed',
            'title' => 'Event Completed',
        ]);

        $this->assertDatabaseHas('platform_notifications', [
            'notifiable_type' => AdminUser::class,
            'notifiable_uuid' => $merchantAdmin->uuid,
            'type' => 'event_completed',
            'title' => 'Event marked complete',
        ]);

        $this->assertDatabaseHas('venue_inquiries', [
            'uuid' => $inquiry->uuid,
            'status' => VenueInquiry::STATUSES['COMPLETED'],
        ]);
    }

    #[Test]
    public function it_transitions_through_full_sequential_workflow(): void
    {
        $eventDate = now()->addMonths(3)->toDateString();
        $inquiry = $this->createCustomerInquiry([
            'site_visit' => VenueInquiry::SITE_VISIT_YES,
            'status' => VenueInquiry::STATUSES['NEW'],
            'event_date' => $eventDate,
        ]);

        $this->withHeaders($this->withVenueAdminHeaders())
            ->patchJson('/api/v1/venue-listings/inquiries/' . $inquiry->uuid, [
                'visit_scheduled_date' => now()->addDays(5)->toDateString(),
                'visit_scheduled_time' => '14:00',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', VenueInquiry::STATUSES['SITE_VISIT_SCHEDULED']);

        $inquiry->refresh();

        $this->withHeaders($this->withVenueAdminHeaders())
            ->postJson('/api/v1/venue-listings/inquiries/' . $inquiry->uuid . '/proposal', [
                'proposal_amount' => 120000,
                'proposal_valid_until' => now()->addWeek()->toDateString(),
                'file' => UploadedFile::fake()->create('proposal.pdf', 100, 'application/pdf'),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', VenueInquiry::STATUSES['PROPOSAL_SENT']);

        $this->actingAs($this->customer, 'api')
            ->postJson('/api/v1/customer/venue-inquiries/' . $inquiry->uuid . '/accept-proposal')
            ->assertOk()
            ->assertJsonPath('data.status', VenueInquiry::STATUSES['ACCEPTED']);

        $this->withHeaders($this->withVenueAdminHeaders())
            ->postJson('/api/v1/venue-listings/inquiries/' . $inquiry->uuid . '/deposit-request', [
                'deposit_amount' => 40000,
                'deposit_due_date' => now()->addDays(10)->toDateString(),
            ])
            ->assertOk()
            ->assertJsonPath('data.status', VenueInquiry::STATUSES['DEPOSIT_REQUESTED']);

        /** @var VenueInquiryWorkflowService $workflow */
        $workflow = app(VenueInquiryWorkflowService::class);
        $inquiry->refresh();
        $inquiry->update([
            'deposit_amount' => 40000,
            'deposit_due_date' => now()->addDays(10)->toDateString(),
        ]);
        $workflow->transition($inquiry->fresh(), VenueInquiry::STATUSES['DEPOSIT_PAID']);

        $this->withHeaders($this->withVenueAdminHeaders())
            ->postJson('/api/v1/venue-listings/inquiries/' . $inquiry->uuid . '/final-billing', [
                'balance_amount' => 80000,
                'balance_due_date' => now()->addDays(20)->toDateString(),
                'additional_charges' => 5000,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', VenueInquiry::STATUSES['BALANCE_DUE']);

        $inquiry->refresh();
        $workflow->transition($inquiry->fresh(), VenueInquiry::STATUSES['FULLY_PAID']);

        $this->withHeaders($this->withVenueAdminHeaders())
            ->postJson('/api/v1/venue-listings/inquiries/' . $inquiry->uuid . '/complete')
            ->assertOk()
            ->assertJsonPath('data.status', VenueInquiry::STATUSES['COMPLETED']);
    }

    #[Test]
    public function public_blocked_dates_show_soft_hold_and_hard_block_reasons(): void
    {
        $eventDate = now()->addMonths(4)->toDateString();
        $listing = $this->createVenueListing(['status' => VenueListing::STATUSES['PUBLISHED']]);

        VenueInquiry::factory()->create([
            'venue_listing_uuid' => $listing->uuid,
            'event_date' => $eventDate,
            'status' => VenueInquiry::STATUSES['DEPOSIT_PAID'],
        ]);

        $this->getJson('/api/v1/public/venue-listings/' . $listing->slug . '/blocked-dates')
            ->assertOk()
            ->assertJsonFragment([
                'blocked_date' => $eventDate,
                'reason' => 'Reserved',
            ]);

        VenueInquiry::query()->update(['status' => VenueInquiry::STATUSES['FULLY_PAID']]);

        $this->getJson('/api/v1/public/venue-listings/' . $listing->slug . '/blocked-dates')
            ->assertOk()
            ->assertJsonFragment([
                'blocked_date' => $eventDate,
                'reason' => 'Booked',
            ]);
    }

    #[Test]
    public function deposit_paid_cancels_competing_open_inquiries_on_same_date(): void
    {
        $eventDate = now()->addMonths(5)->toDateString();
        $listing = $this->createVenueListing(['status' => VenueListing::STATUSES['PUBLISHED']]);

        $reserved = VenueInquiry::factory()->create([
            'venue_listing_uuid' => $listing->uuid,
            'event_date' => $eventDate,
            'status' => VenueInquiry::STATUSES['DEPOSIT_PAID'],
        ]);

        $competitor = VenueInquiry::factory()->create([
            'venue_listing_uuid' => $listing->uuid,
            'event_date' => $eventDate,
            'status' => VenueInquiry::STATUSES['PROPOSAL_SENT'],
        ]);

        /** @var VenueInquiryWorkflowService $workflow */
        $workflow = app(VenueInquiryWorkflowService::class);
        $workflow->cancelConflictingInquiries($reserved->fresh());

        $this->assertDatabaseHas('venue_inquiries', [
            'uuid' => $competitor->uuid,
            'status' => VenueInquiry::STATUSES['CANCELLED'],
        ]);
    }
}
