<?php

namespace Tests\Feature\Coverage\RNotifications;

use App\Models\Event;
use App\Models\Role;
use App\Models\TempTransaction;
use App\Models\Upload;
use App\Models\User;
use App\Notifications\TempTransactionMarketingFollowupNotification;
use App\Notifications\VenueBalancePaidNotification;
use App\Notifications\VenueBookingConfirmedNotification;
use App\Notifications\VenueProposalDeclinedNotification;
use App\Notifications\VenueProposalSentNotification;
use App\Notifications\VenueVisitCustomerResponseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Coverage\Notifications\Concerns\BuildsNotificationFixtures;
use Tests\Feature\Coverage\RNotifications\Concerns\BuildsTicketTransactions;
use Tests\TestCase;

class VenueAndFollowupEdgeCasesTest extends TestCase
{
    use BuildsNotificationFixtures;
    use BuildsTicketTransactions;
    use RefreshDatabase;

    private AnonymousNotifiable $notifiable;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => self::FRONTEND_URL]);
        $this->notifiable = (new AnonymousNotifiable())->route('mail', 'recipient@example.test');
        Http::preventStrayRequests();
        Http::fake();
    }

    #[Test]
    public function theFollowupGreetsByTheFirstWordOfTheFullNameWhenNoFirstNameIsKnown(): void
    {
        $tempTransaction = $this->tempTransaction();

        $mail = (new TempTransactionMarketingFollowupNotification($tempTransaction))
            ->toMail((object) ['email' => 'guest@example.test', 'first_name' => '  ', 'full_name' => 'Rosa Maria Lim']);

        $this->assertSame('Rosa', $mail->viewData['data']['first_name']);
        $this->assertSame('Coverage Gala', $mail->viewData['data']['event_name']);
        $this->assertSame(self::FRONTEND_URL . '/checkout/' . $tempTransaction->uuid, $mail->viewData['data']['checkout_url']);
    }

    #[Test]
    public function theFollowupUsesASingleWordFullNameAsIs(): void
    {
        $mail = (new TempTransactionMarketingFollowupNotification($this->tempTransaction()))
            ->toMail((object) ['email' => 'guest@example.test', 'full_name' => 'Prince']);

        $this->assertSame('Prince', $mail->viewData['data']['first_name']);
    }

    #[Test]
    public function theFollowupFallsBackToThereWhenTheNotifiableHasNoName(): void
    {
        $mail = (new TempTransactionMarketingFollowupNotification($this->tempTransaction()))
            ->toMail((object) ['email' => 'guest@example.test']);

        $this->assertSame('there', $mail->viewData['data']['first_name']);
    }

    #[Test]
    public function aBookingConfirmationForAnExplicitTransactionShowsThatTransactionsPayment(): void
    {
        $this->bindExportServiceStub();
        $transaction = $this->createPaidTransaction(withPortrait: false);
        $inquiry = $this->createVenueInquiry(withFeaturedImage: false);

        $mail = (new VenueBookingConfirmedNotification($inquiry->uuid, $transaction->uuid))->toMail($this->notifiable);
        $booking = $mail->viewData['data']['booking'];

        $this->assertSame('ORD-RNOTIF-0001', $booking['order_number']);
        $this->assertStringEndsWith('2,500.00', $booking['amount_paid']);
        $this->assertStringStartsWith('October 01, 2026', $booking['paid_at']);
    }

    #[Test]
    public function aDeclinedProposalShowsTheFormattedProposalAmount(): void
    {
        $inquiry = $this->createVenueInquiry(['proposal_amount' => 98765.4], withFeaturedImage: false);

        $mail = (new VenueProposalDeclinedNotification($inquiry->uuid))->toMail($this->notifiable);

        $this->assertStringEndsWith('98,765.40', $mail->viewData['data']['proposal']['amount_label']);
        $this->assertStringContainsString('98,765.40', (string) $mail->render());
    }

    #[Test]
    public function aBalancePaidEmailAddsAdditionalChargesToTheBalance(): void
    {
        $inquiry = $this->createVenueInquiry([
            'balance_amount' => 20000,
            'additional_charges' => 1500.5,
        ], withFeaturedImage: false);

        $mail = (new VenueBalancePaidNotification($inquiry->uuid))->toMail($this->notifiable);
        $payment = $mail->viewData['data']['payment'];

        $this->assertStringEndsWith('21,500.50', $payment['balance_label']);
        $this->assertStringEndsWith('1,500.50', $payment['additional_charges_label']);
    }

    #[Test]
    public function theProposalFileIsAttachedWithItsExtensionWhenTheNameHasNone(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('proposals/abc123', '%PDF-proposal');
        $upload = $this->proposalUpload(['disk' => 'local', 'path' => 'proposals/abc123', 'name' => 'Skyline Proposal']);
        $inquiry = $this->createVenueInquiry(['proposal_upload_uuid' => $upload->uuid], withFeaturedImage: false);

        $mail = (new VenueProposalSentNotification($inquiry->uuid))->toMail($this->notifiable);

        $this->assertCount(1, $mail->rawAttachments);
        $this->assertSame('Skyline Proposal.pdf', $mail->rawAttachments[0]['name']);
        $this->assertSame('%PDF-proposal', $mail->rawAttachments[0]['data']);
        $this->assertSame('application/pdf', $mail->rawAttachments[0]['options']['mime']);
    }

    #[Test]
    public function theProposalEmailIsSentWithoutAnAttachmentWhenTheFileIsMissingFromStorage(): void
    {
        Storage::fake('local');
        $upload = $this->proposalUpload(['disk' => 'local', 'path' => 'proposals/missing.pdf']);
        $inquiry = $this->createVenueInquiry(['proposal_upload_uuid' => $upload->uuid], withFeaturedImage: false);

        $mail = (new VenueProposalSentNotification($inquiry->uuid))->toMail($this->notifiable);

        $this->assertSame([], $mail->rawAttachments);
        $this->assertSame('emails.venue-proposal-sent-dark', $mail->view);
    }

    #[Test]
    public function theProposalEmailIsSentWithoutAnAttachmentWhenStorageCannotBeRead(): void
    {
        $upload = $this->proposalUpload(['disk' => 'disk-that-is-not-configured', 'path' => 'proposals/x.pdf']);
        $inquiry = $this->createVenueInquiry(['proposal_upload_uuid' => $upload->uuid], withFeaturedImage: false);

        $mail = (new VenueProposalSentNotification($inquiry->uuid))->toMail($this->notifiable);

        $this->assertSame([], $mail->rawAttachments);
        $this->assertStringStartsWith('[Ticketoc] Proposal received for', $mail->subject);
    }

    #[Test]
    public function aSuggestedVisitDateIsHighlightedForTheMerchant(): void
    {
        $inquiry = $this->createVenueInquiry([
            'visit_scheduled_date' => '2026-11-05',
            'visit_scheduled_time' => '14:30:00',
        ], withFeaturedImage: false);

        $mail = (new VenueVisitCustomerResponseNotification(
            $inquiry->uuid,
            VenueVisitCustomerResponseNotification::RESPONSE_SUGGESTED,
            '2026-11-12',
            null,
            '10:15',
        ))->toMail($this->notifiable);
        $data = $mail->viewData['data'];

        $this->assertStringStartsWith('[Ticketoc] Customer suggested a new site visit date', $mail->subject);
        $this->assertSame('Customer suggested a different visit date', $data['email_headline']);
        $this->assertSame('In Discussion', $data['inquiry']['status']);
        $this->assertSame('Suggested date & time', $data['highlight_label']);
        $this->assertSame('Thursday, November 12, 2026', $data['visit']['suggested_date']);
        $this->assertSame('10:15 AM', $data['visit']['suggested_time']);
        $this->assertSame($data['visit']['suggested_label'], $data['highlight_value']);
        $this->assertStringContainsString('Thursday, November 12, 2026', $data['highlight_value']);
        $this->assertNotNull($data['visit']['scheduled_label']);
        $this->assertStringContainsString('Customer suggested a different visit date', html_entity_decode((string) $mail->render(), ENT_QUOTES));
    }

    #[Test]
    public function aSuggestionWithoutADateFallsBackToGenericWording(): void
    {
        $inquiry = $this->createVenueInquiry(withFeaturedImage: false);

        $data = (new VenueVisitCustomerResponseNotification(
            $inquiry->uuid,
            VenueVisitCustomerResponseNotification::RESPONSE_SUGGESTED,
        ))->toMail($this->notifiable)->viewData['data'];

        $this->assertNull($data['visit']['suggested_date']);
        $this->assertNull($data['visit']['suggested_time']);
        $this->assertSame('Alternative date & time', $data['highlight_value']);
    }

    private function tempTransaction(): TempTransaction
    {
        $event = Event::factory()->create(['event_name' => 'Coverage Gala']);
        $role = Role::create(['name' => 'Customer', 'code' => 'customer-' . Str::uuid()->toString()]);
        $user = User::factory()->create(['role_uuid' => $role->uuid]);

        return TempTransaction::create([
            'user_uuid' => $user->uuid,
            'event_uuid' => $event->uuid,
            'total_amount' => 1000,
            'sub_total' => 1000,
            'valid_until' => now()->addHour(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function proposalUpload(array $overrides): Upload
    {
        return Upload::create(array_merge([
            'uploadable_type' => 'venue_inquiry',
            'uploadable_uuid' => (string) Str::uuid(),
            'collection' => 'proposal',
            'type' => 'document',
            'mime_type' => 'application/pdf',
            'extension' => 'pdf',
            'order_number' => 0,
        ], $overrides));
    }
}
