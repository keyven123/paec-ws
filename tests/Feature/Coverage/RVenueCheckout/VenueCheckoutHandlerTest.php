<?php

namespace Tests\Feature\Coverage\RVenueCheckout;

use App\Models\Transaction;
use App\Models\User;
use App\Models\VenueInquiry;
use App\Models\VenueListing;
use App\Notifications\VenueBalancePaidNotification;
use App\Notifications\VenueDepositPaidNotification;
use App\Services\Checkout\VenueCheckoutHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VenueCheckoutHandlerTest extends TestCase
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

    private function handler(): VenueCheckoutHandler
    {
        return app(VenueCheckoutHandler::class);
    }

    private function transactionFor(string $inquiryUuid, array $paymentData, float $amount = 20000): Transaction
    {
        return Transaction::create([
            'user_uuid' => $this->customer->uuid,
            'transactionable_type' => 'venue_inquiry',
            'transactionable_uuid' => $inquiryUuid,
            'order_number' => 'VEN-' . Str::upper(Str::random(8)),
            'sub_total' => $amount,
            'tax_amount' => 0,
            'discount' => 0,
            'total_amount' => $amount,
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'payment_data' => $paymentData,
        ]);
    }

    #[Test]
    public function morphAliasIsVenueInquiry(): void
    {
        $this->assertSame('venue_inquiry', $this->handler()->morphAlias());
    }

    #[Test]
    public function paidTransactionWithoutLinkedInquiryLogsWarningAndDoesNothing(): void
    {
        Log::spy();
        $missingUuid = (string) Str::uuid();
        $transaction = $this->transactionFor($missingUuid, []);

        $this->handler()->handlePaid($transaction);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context) => $message === 'Venue transaction paid without a linked inquiry'
                && $context['transaction_uuid'] === $transaction->uuid
                && $context['transactionable_uuid'] === $missingUuid)
            ->once();
        Notification::assertNothingSent();
    }

    #[Test]
    public function depositPhaseDefaultsWhenPaymentDataHasNoPhaseAndMarksDepositPaid(): void
    {
        $inquiry = VenueInquiry::factory()->create([
            'user_uuid' => $this->customer->uuid,
            'status' => VenueInquiry::STATUSES['DEPOSIT_REQUESTED'],
            'deposit_amount' => 20000,
        ]);
        $transaction = $this->transactionFor($inquiry->uuid, ['venue_inquiry_uuid' => $inquiry->uuid]);

        $this->handler()->handlePaid($transaction);

        $inquiry->refresh();
        $this->assertSame(VenueInquiry::STATUSES['DEPOSIT_PAID'], $inquiry->status);
        $this->assertNotNull($inquiry->deposit_paid_at);

        $organization = VenueListing::query()->findOrFail($inquiry->venue_listing_uuid)->organization;
        Notification::assertSentTo($organization, VenueDepositPaidNotification::class);
    }

    #[Test]
    public function balancePhaseMarksInquiryFullyPaid(): void
    {
        $inquiry = VenueInquiry::factory()->create([
            'user_uuid' => $this->customer->uuid,
            'status' => VenueInquiry::STATUSES['BALANCE_DUE'],
            'balance_amount' => 30000,
        ]);
        $listing = VenueListing::query()->findOrFail($inquiry->venue_listing_uuid);
        $bookingsBefore = (int) $listing->bookings_count;
        $transaction = $this->transactionFor($inquiry->uuid, [
            'venue_payment_phase' => VenueInquiry::PAYMENT_PHASE_BALANCE,
        ], 30000);

        $this->handler()->handlePaid($transaction, true);

        $inquiry->refresh();
        $this->assertSame(VenueInquiry::STATUSES['FULLY_PAID'], $inquiry->status);
        $this->assertNotNull($inquiry->fully_paid_at);
        $this->assertSame($bookingsBefore + 1, (int) $listing->fresh()->bookings_count);
        Notification::assertSentTo($listing->organization, VenueBalancePaidNotification::class);
    }

    #[Test]
    public function workflowFailureIsCaughtAndLogged(): void
    {
        Log::spy();
        $inquiry = VenueInquiry::factory()->create([
            'user_uuid' => $this->customer->uuid,
            'status' => VenueInquiry::STATUSES['NEW'],
        ]);
        $transaction = $this->transactionFor($inquiry->uuid, [
            'venue_payment_phase' => VenueInquiry::PAYMENT_PHASE_BALANCE,
        ]);

        $this->handler()->handlePaid($transaction);

        $this->assertSame(VenueInquiry::STATUSES['NEW'], $inquiry->fresh()->status);
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context) => $message === 'Venue checkout handler failed'
                && $context['inquiry_uuid'] === $inquiry->uuid
                && $context['transaction_uuid'] === $transaction->uuid
                && $context['phase'] === VenueInquiry::PAYMENT_PHASE_BALANCE
                && str_contains($context['error'], 'Cannot move inquiry from "new" to "fully_paid"'))
            ->once();
    }
}
