<?php

namespace Tests\Feature\Coverage\RVenueCheckout;

use App\Http\Repositories\ChatRepository;
use App\Models\ChatMessage;
use App\Models\PlatformNotification;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VenueInquiry;
use App\Models\VenueListing;
use App\Notifications\VenueBookingConfirmedNotification;
use App\Notifications\VenueBookingUnavailableNotification;
use App\Notifications\VenueEventCompletedCustomerNotification;
use App\Notifications\VenueProposalSentNotification;
use App\Notifications\VenueVisitCustomerResponseNotification;
use App\Notifications\VenueVisitScheduledNotification;
use App\Services\NotificationService;
use App\Services\VenueInquiryWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VenueInquiryWorkflowServiceTest extends TestCase
{
    use CreatesVenueCustomers;
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->customer = $this->createVenueCustomer();
    }

    private function service(): VenueInquiryWorkflowService
    {
        return app(VenueInquiryWorkflowService::class);
    }

    private function inquiry(array $overrides = []): VenueInquiry
    {
        return VenueInquiry::factory()->create(array_merge([
            'user_uuid' => $this->customer->uuid,
            'email' => $this->customer->email,
        ], $overrides));
    }

    private function guestInquiry(array $overrides = []): VenueInquiry
    {
        return $this->inquiry(array_merge(['user_uuid' => null, 'email' => 'guest@example.test'], $overrides));
    }

    private function inquiryWithDeletedVenue(array $overrides = []): VenueInquiry
    {
        $inquiry = $this->inquiry($overrides);
        VenueListing::query()->whereKey($inquiry->venue_listing_uuid)->firstOrFail()->delete();

        return $inquiry->fresh();
    }

    private function transactionFor(VenueInquiry $inquiry, float $amount): Transaction
    {
        return Transaction::create([
            'user_uuid' => $this->customer->uuid,
            'transactionable_type' => 'venue_inquiry',
            'transactionable_uuid' => $inquiry->uuid,
            'order_number' => 'VEN-' . Str::upper(Str::random(8)),
            'sub_total' => $amount,
            'tax_amount' => 0,
            'discount' => 0,
            'total_amount' => $amount,
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
        ]);
    }

    private function assertValidationMessage(callable $callback, string $field, string $message): void
    {
        try {
            $callback();
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
            $this->assertStringContainsString($message, $e->errors()[$field][0]);
        }
    }

    private function cardCount(VenueInquiry $inquiry, string $type): int
    {
        return ChatMessage::query()
            ->where('message_type', $type)
            ->whereHas('thread', fn ($q) => $q->where('venue_inquiry_uuid', $inquiry->uuid))
            ->count();
    }

    // ---- guard clauses -------------------------------------------------

    #[Test]
    public function invalidTransitionIsRejected(): void
    {
        $inquiry = $this->inquiry(['status' => VenueInquiry::STATUSES['NEW']]);

        $this->assertValidationMessage(
            fn () => $this->service()->markCompleted($inquiry),
            'status',
            'Cannot move inquiry from "new" to "completed".',
        );
        $this->assertSame(VenueInquiry::STATUSES['NEW'], $inquiry->fresh()->status);
    }

    #[Test]
    public function proposalCannotBeSentFromNewInquiry(): void
    {
        $inquiry = $this->inquiry(['status' => VenueInquiry::STATUSES['NEW']]);

        $this->assertValidationMessage(
            fn () => $this->service()->sendProposal($inquiry, ['proposal_amount' => 1000]),
            'inquiry',
            'A proposal can only be sent while the inquiry is in discussion',
        );
        $this->assertNull($inquiry->fresh()->proposal_amount);
    }

    #[Test]
    public function visitCannotBeScheduledForInquiryWithoutSiteVisit(): void
    {
        $inquiry = $this->inquiry(['site_visit' => VenueInquiry::SITE_VISIT_NO]);

        $this->assertValidationMessage(
            fn () => $this->service()->scheduleVisit($inquiry, ['visit_scheduled_date' => '2026-11-01'], false),
            'visit_scheduled_date',
            'Visit schedule can only be set for site visit inquiries.',
        );
    }

    #[Test]
    public function visitCannotBeScheduledAfterProposalAccepted(): void
    {
        $inquiry = $this->inquiry([
            'site_visit' => VenueInquiry::SITE_VISIT_YES,
            'status' => VenueInquiry::STATUSES['ACCEPTED'],
        ]);

        $this->assertValidationMessage(
            fn () => $this->service()->scheduleVisit($inquiry, ['visit_scheduled_date' => '2026-11-01'], false),
            'visit_scheduled_date',
            'Cannot schedule a visit at this stage.',
        );
        $this->assertSame(VenueInquiry::STATUSES['ACCEPTED'], $inquiry->fresh()->status);
    }

    #[Test]
    public function declineProposalRequiresSentProposal(): void
    {
        $inquiry = $this->inquiry(['status' => VenueInquiry::STATUSES['IN_DISCUSSION']]);

        $this->assertValidationMessage(
            fn () => $this->service()->declineProposal($inquiry),
            'inquiry',
            'Only a sent proposal can be declined.',
        );
    }

    #[Test]
    public function visitResponsesRequireScheduledVisit(): void
    {
        $inquiry = $this->inquiry(['status' => VenueInquiry::STATUSES['IN_DISCUSSION']]);

        $this->assertValidationMessage(
            fn () => $this->service()->acceptVisitSchedule($inquiry),
            'inquiry',
            'Only a scheduled site visit can be accepted.',
        );
        $this->assertValidationMessage(
            fn () => $this->service()->declineVisitSchedule($inquiry),
            'inquiry',
            'Only a scheduled site visit can be declined.',
        );
        $this->assertValidationMessage(
            fn () => $this->service()->suggestVisitDate($inquiry, '2026-11-10', '10:00'),
            'inquiry',
            'Only a scheduled site visit can receive a suggested alternative date.',
        );
    }

    #[Test]
    public function cancelIsIdempotentForCancelledInquiry(): void
    {
        $inquiry = $this->inquiry(['status' => VenueInquiry::STATUSES['CANCELLED']]);

        $result = $this->service()->cancel($inquiry);

        $this->assertSame($inquiry, $result);
        $this->assertSame(VenueInquiry::STATUSES['CANCELLED'], $inquiry->fresh()->status);
    }

    #[Test]
    public function completedInquiryCannotBeCancelled(): void
    {
        $inquiry = $this->inquiry(['status' => VenueInquiry::STATUSES['COMPLETED']]);

        $this->assertValidationMessage(
            fn () => $this->service()->cancel($inquiry),
            'status',
            'This inquiry cannot be cancelled at its current stage.',
        );
        $this->assertSame(VenueInquiry::STATUSES['COMPLETED'], $inquiry->fresh()->status);
    }

    // ---- re-sending in the same status ------------------------------------

    #[Test]
    public function requestingDepositAgainUpdatesAmountsAndResendsCard(): void
    {
        $inquiry = $this->inquiry([
            'status' => VenueInquiry::STATUSES['DEPOSIT_REQUESTED'],
            'deposit_amount' => 5000,
            'deposit_due_date' => '2026-11-01',
        ]);

        $updated = $this->service()->requestDeposit($inquiry, 7500, '2026-11-15');

        $this->assertSame(VenueInquiry::STATUSES['DEPOSIT_REQUESTED'], $updated->status);
        $this->assertEquals(7500, (float) $updated->deposit_amount);
        $this->assertEquals(7500, (float) $updated->approved_amount);
        $this->assertSame('2026-11-15', $updated->deposit_due_date->toDateString());
        $this->assertSame('2026-11-15', $updated->approved_due_date->toDateString());
        $this->assertSame(1, $this->cardCount($inquiry, ChatMessage::TYPE_DEPOSIT_REQUESTED_CARD));
        $this->assertTrue(PlatformNotification::query()
            ->where('notifiable_uuid', $this->customer->uuid)
            ->where('type', 'deposit_requested')
            ->exists());
    }

    #[Test]
    public function sendingFinalBillingAgainUpdatesBalanceAndResendsCard(): void
    {
        $inquiry = $this->inquiry([
            'status' => VenueInquiry::STATUSES['BALANCE_DUE'],
            'balance_amount' => 10000,
        ]);

        $updated = $this->service()->sendFinalBilling($inquiry, 12000, '2026-12-01', 800);

        $this->assertSame(VenueInquiry::STATUSES['BALANCE_DUE'], $updated->status);
        $this->assertEquals(12000, (float) $updated->balance_amount);
        $this->assertEquals(800, (float) $updated->additional_charges);
        $this->assertSame('2026-12-01', $updated->balance_due_date->toDateString());

        $card = ChatMessage::query()->where('message_type', ChatMessage::TYPE_BALANCE_DUE_CARD)->sole();
        $this->assertEquals(12800, $card->metadata['total_due']);
        $this->assertSame('₱800.00', $card->metadata['additional_charges_label']);
    }

    // ---- visit scheduling / proposal side-effects -------------------------

    #[Test]
    public function reschedulingVisitWithoutTimeNotifiesCustomerWithUpdatedTitle(): void
    {
        $inquiry = $this->inquiry([
            'site_visit' => VenueInquiry::SITE_VISIT_YES,
            'status' => VenueInquiry::STATUSES['SITE_VISIT_SCHEDULED'],
            'visit_scheduled_date' => '2026-11-01',
            'visit_scheduled_time' => '10:00:00',
        ]);

        $updated = $this->service()->scheduleVisit($inquiry, [
            'visit_scheduled_date' => '2026-11-03',
            'visit_scheduled_time' => null,
        ], true);

        $this->assertSame('2026-11-03', $updated->visit_scheduled_date->toDateString());
        $this->assertNull($updated->visit_scheduled_time);
        Notification::assertSentTo($this->customer, VenueVisitScheduledNotification::class);

        $notification = PlatformNotification::query()
            ->where('notifiable_uuid', $this->customer->uuid)
            ->where('type', 'visit_scheduled')
            ->sole();
        $this->assertSame('Site Visit Scheduled (Updated)', $notification->title);
        $this->assertStringContainsString('has been moved to', $notification->body);

        $card = ChatMessage::query()->where('message_type', ChatMessage::TYPE_SCHEDULE_CARD)->sole();
        $this->assertTrue($card->metadata['is_reschedule']);
        $this->assertNull($card->metadata['visit_time']);
    }

    #[Test]
    public function proposalToGuestIsEmailedOnDemand(): void
    {
        $inquiry = $this->guestInquiry(['status' => VenueInquiry::STATUSES['IN_DISCUSSION']]);

        $updated = $this->service()->sendProposal($inquiry, [
            'proposal_amount' => 45000,
            'proposal_valid_until' => '2026-11-30',
        ]);

        $this->assertSame(VenueInquiry::STATUSES['PROPOSAL_SENT'], $updated->status);
        Notification::assertSentOnDemand(
            VenueProposalSentNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'guest@example.test',
        );
        $this->assertSame(0, PlatformNotification::query()->count());
    }

    // ---- inquiries whose venue listing was removed -------------------------

    #[Test]
    public function acceptingProposalForRemovedVenueSkipsMerchantNotification(): void
    {
        $inquiry = $this->inquiryWithDeletedVenue([
            'status' => VenueInquiry::STATUSES['PROPOSAL_SENT'],
            'proposal_amount' => 1000,
        ]);

        $updated = $this->service()->acceptProposal($inquiry);

        $this->assertSame(VenueInquiry::STATUSES['ACCEPTED'], $updated->status);
        $this->assertNull($updated->venueListing);
        $this->assertFalse(PlatformNotification::query()->where('type', 'proposal_accepted')
            ->where('notifiable_uuid', '!=', $this->customer->uuid)->exists());
        $customerNotice = PlatformNotification::query()->where('type', 'proposal_accepted')->sole();
        $this->assertStringContainsString('"the venue"', $customerNotice->body);
    }

    #[Test]
    public function decliningProposalForRemovedVenuePostsCardWithoutAddress(): void
    {
        $inquiry = $this->inquiryWithDeletedVenue([
            'status' => VenueInquiry::STATUSES['PROPOSAL_SENT'],
            'proposal_amount' => 1000,
        ]);

        $updated = $this->service()->declineProposal($inquiry);

        $this->assertSame(VenueInquiry::STATUSES['IN_DISCUSSION'], $updated->status);
        $this->assertFalse(PlatformNotification::query()->where('type', 'proposal_declined')->exists());
        $card = ChatMessage::query()->where('message_type', ChatMessage::TYPE_PROPOSAL_DECLINED_CARD)->sole();
        $this->assertNull($card->metadata['venue_address']);
        $this->assertSame('the venue', $card->metadata['venue_name']);
    }

    #[Test]
    public function depositPaidForRemovedVenueStillUpdatesInquiry(): void
    {
        $inquiry = $this->inquiryWithDeletedVenue([
            'status' => VenueInquiry::STATUSES['DEPOSIT_REQUESTED'],
            'deposit_amount' => 3000,
        ]);

        $updated = $this->service()->handleDepositPaid($inquiry, $this->transactionFor($inquiry, 3000));

        $this->assertSame(VenueInquiry::STATUSES['DEPOSIT_PAID'], $updated->status);
        $this->assertFalse(PlatformNotification::query()->where('type', 'deposit_paid')
            ->where('notifiable_uuid', '!=', $this->customer->uuid)->exists());
        $card = ChatMessage::query()->where('message_type', ChatMessage::TYPE_DEPOSIT_PAID_CARD)->sole();
        $this->assertSame('your venue', $card->metadata['venue_name']);
    }

    #[Test]
    public function fullyPaidForRemovedVenueConfirmsBookingByEmail(): void
    {
        $inquiry = $this->inquiryWithDeletedVenue([
            'status' => VenueInquiry::STATUSES['BALANCE_DUE'],
            'balance_amount' => 9000,
        ]);
        $transaction = $this->transactionFor($inquiry, 9000);

        $updated = $this->service()->handleFullyPaid($inquiry, $transaction);

        $this->assertSame(VenueInquiry::STATUSES['FULLY_PAID'], $updated->status);
        Notification::assertSentOnDemand(
            VenueBookingConfirmedNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === $this->customer->email,
        );
        $this->assertFalse(PlatformNotification::query()->where('type', 'fully_paid')
            ->where('notifiable_uuid', '!=', $this->customer->uuid)->exists());
        $card = ChatMessage::query()->where('message_type', ChatMessage::TYPE_FULLY_PAID_CARD)->sole();
        $this->assertEquals(9000, $card->metadata['amount_paid']);
    }

    #[Test]
    public function completingGuestInquiryForRemovedVenueEmailsGuestOnly(): void
    {
        $inquiry = $this->inquiryWithDeletedVenue([
            'user_uuid' => null,
            'email' => 'guest@example.test',
            'status' => VenueInquiry::STATUSES['FULLY_PAID'],
        ]);

        $updated = $this->service()->markCompleted($inquiry);

        $this->assertSame(VenueInquiry::STATUSES['COMPLETED'], $updated->status);
        $this->assertNotNull($updated->completed_at);
        Notification::assertSentOnDemand(
            VenueEventCompletedCustomerNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'guest@example.test',
        );
        $this->assertSame(0, PlatformNotification::query()->count());
    }

    #[Test]
    public function acceptingVisitForRemovedVenueSkipsMerchantNotification(): void
    {
        $inquiry = $this->inquiryWithDeletedVenue([
            'status' => VenueInquiry::STATUSES['SITE_VISIT_SCHEDULED'],
            'visit_scheduled_date' => '2026-11-05',
            'visit_scheduled_time' => '09:30:00',
        ]);

        $updated = $this->service()->acceptVisitSchedule($inquiry);

        $this->assertSame(VenueInquiry::STATUSES['SITE_VISIT_SCHEDULED'], $updated->status);
        Notification::assertNotSentTo($this->customer, VenueVisitCustomerResponseNotification::class);
        $this->assertSame(0, PlatformNotification::query()->count());
        $card = ChatMessage::query()->where('message_type', ChatMessage::TYPE_VISIT_ACCEPTED_CARD)->sole();
        $this->assertSame('09:30', $card->metadata['visit_time']);
        $this->assertNull($card->metadata['venue_address']);
    }

    #[Test]
    public function decliningVisitWithoutTimeUsesDateOnlyLabel(): void
    {
        $inquiry = $this->inquiry([
            'status' => VenueInquiry::STATUSES['SITE_VISIT_SCHEDULED'],
            'visit_scheduled_date' => '2026-11-05',
            'visit_scheduled_time' => null,
        ]);

        $updated = $this->service()->declineVisitSchedule($inquiry);

        $this->assertSame(VenueInquiry::STATUSES['IN_DISCUSSION'], $updated->status);
        $this->assertNull($updated->visit_scheduled_date);
        $card = ChatMessage::query()->where('message_type', ChatMessage::TYPE_VISIT_DECLINED_CARD)->sole();
        $this->assertStringContainsString('2026', $card->metadata['visit_label']);
        $this->assertStringNotContainsString('the scheduled visit', $card->metadata['visit_label']);
    }

    // ---- defensive error handling -----------------------------------------

    #[Test]
    public function fullyPaidSideEffectFailureIsLoggedButStatusIsKept(): void
    {
        Log::spy();
        $this->mock(NotificationService::class, function ($mock) {
            $mock->shouldReceive('send')->andThrow(new \RuntimeException('notifications down'));
            $mock->shouldReceive('sendToOrganization');
        });

        $inquiry = $this->inquiry([
            'status' => VenueInquiry::STATUSES['BALANCE_DUE'],
            'balance_amount' => 5000,
        ]);

        $updated = $this->service()->handleFullyPaid($inquiry, $this->transactionFor($inquiry, 5000));

        $this->assertSame(VenueInquiry::STATUSES['FULLY_PAID'], $updated->status);
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context) => $message === 'Failed after fully paid workflow'
                && $context['inquiry_uuid'] === $inquiry->uuid
                && $context['error'] === 'notifications down')
            ->once();
    }

    #[Test]
    public function chatFailuresAreLoggedWithoutBreakingVisitAcceptance(): void
    {
        Log::spy();
        $this->mock(ChatRepository::class, function ($mock) {
            $mock->shouldReceive('firstOrCreateThreadForInquiry')->andThrow(new \RuntimeException('chat down'));
        });

        $inquiry = $this->inquiry([
            'status' => VenueInquiry::STATUSES['SITE_VISIT_SCHEDULED'],
            'visit_scheduled_date' => '2026-11-05',
            'visit_scheduled_time' => '09:30:00',
        ]);
        $organization = VenueListing::query()->findOrFail($inquiry->venue_listing_uuid)->organization;

        $updated = $this->service()->acceptVisitSchedule($inquiry);

        $this->assertSame(VenueInquiry::STATUSES['SITE_VISIT_SCHEDULED'], $updated->status);
        Notification::assertSentTo($organization, VenueVisitCustomerResponseNotification::class);
        $this->assertSame(0, ChatMessage::query()->count());
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context) => $message === 'Failed to send customer chat message'
                && $context['inquiry_uuid'] === $inquiry->uuid
                && $context['error'] === 'chat down')
            ->once();
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context) => $message === 'Failed to send workflow chat card'
                && $context['message_type'] === ChatMessage::TYPE_VISIT_ACCEPTED_CARD
                && $context['error'] === 'chat down')
            ->once();
    }

    // ---- conflicting inquiries ---------------------------------------------

    #[Test]
    public function conflictCancellationIsSkippedWhenReservedInquiryHasNoEventDate(): void
    {
        $reserved = $this->inquiry(['event_date' => null, 'status' => VenueInquiry::STATUSES['DEPOSIT_PAID']]);
        $other = $this->inquiry([
            'venue_listing_uuid' => $reserved->venue_listing_uuid,
            'event_date' => null,
            'status' => VenueInquiry::STATUSES['NEW'],
        ]);

        $this->service()->cancelConflictingInquiries($reserved);

        $this->assertSame(VenueInquiry::STATUSES['NEW'], $other->fresh()->status);
        Notification::assertNothingSent();
    }

    #[Test]
    public function conflictingRegisteredCustomerIsCancelledAndNotified(): void
    {
        $reserved = $this->guestInquiry([
            'event_date' => '2026-12-12',
            'status' => VenueInquiry::STATUSES['DEPOSIT_REQUESTED'],
            'deposit_amount' => 1000,
        ]);
        $rival = $this->createVenueCustomer();
        $conflict = $this->inquiry([
            'venue_listing_uuid' => $reserved->venue_listing_uuid,
            'user_uuid' => $rival->uuid,
            'email' => $rival->email,
            'event_date' => '2026-12-12',
            'status' => VenueInquiry::STATUSES['IN_DISCUSSION'],
        ]);
        $listing = VenueListing::query()->findOrFail($reserved->venue_listing_uuid);

        $this->service()->handleDepositPaid($reserved, $this->transactionFor($reserved, 1000));

        $this->assertSame(VenueInquiry::STATUSES['DEPOSIT_PAID'], $reserved->fresh()->status);
        $this->assertSame(VenueInquiry::STATUSES['CANCELLED'], $conflict->fresh()->status);
        Notification::assertSentOnDemand(
            VenueBookingUnavailableNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === $rival->email,
        );
        $notice = PlatformNotification::query()
            ->where('notifiable_uuid', $rival->uuid)
            ->where('type', 'venue_date_unavailable')
            ->sole();
        $this->assertSame('/venue/' . $listing->slug, $notice->action_url);
        $this->assertStringContainsString($listing->name, $notice->body);
        $this->assertFalse(PlatformNotification::query()->where('type', 'deposit_paid')
            ->whereNull('notifiable_uuid')->exists());
    }

    #[Test]
    public function conflictNotificationFailureIsLogged(): void
    {
        Log::spy();
        $this->mock(NotificationService::class, function ($mock) {
            $mock->shouldReceive('send')->andThrow(new \RuntimeException('push failed'));
        });

        $reserved = $this->inquiry([
            'event_date' => '2026-12-20',
            'status' => VenueInquiry::STATUSES['DEPOSIT_PAID'],
        ]);
        $conflict = $this->inquiry([
            'venue_listing_uuid' => $reserved->venue_listing_uuid,
            'event_date' => '2026-12-20',
            'status' => VenueInquiry::STATUSES['NEW'],
        ]);

        $this->service()->cancelConflictingInquiries($reserved);

        $this->assertSame(VenueInquiry::STATUSES['CANCELLED'], $conflict->fresh()->status);
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context) => $message === 'Failed to cancel conflicting venue inquiry'
                && $context['inquiry_uuid'] === $conflict->uuid
                && $context['error'] === 'push failed')
            ->once();
    }
}
