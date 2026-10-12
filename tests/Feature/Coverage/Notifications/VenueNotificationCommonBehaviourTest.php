<?php

namespace Tests\Feature\Coverage\Notifications;

use App\Notifications\VenueBalancePaidNotification;
use App\Notifications\VenueBookingConfirmedNotification;
use App\Notifications\VenueBookingUnavailableNotification;
use App\Notifications\VenueDepositPaidNotification;
use App\Notifications\VenueEventCompletedCustomerNotification;
use App\Notifications\VenueEventCompletedMerchantNotification;
use App\Notifications\VenueInquirySubmittedNotification;
use App\Notifications\VenueProposalAcceptedNotification;
use App\Notifications\VenueProposalDeclinedNotification;
use App\Notifications\VenueProposalSentNotification;
use App\Notifications\VenueVisitCustomerResponseNotification;
use App\Notifications\VenueVisitScheduledNotification;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Coverage\Notifications\Concerns\BuildsNotificationFixtures;
use Tests\TestCase;

/**
 * Behaviour shared by every venue-inquiry notification: mail-only delivery, featured image
 * embedding (remote fetch → CID attachment), graceful handling of a missing listing,
 * and rendering of the dark email template.
 */
class VenueNotificationCommonBehaviourTest extends TestCase
{
    use BuildsNotificationFixtures;
    use RefreshDatabase;

    private AnonymousNotifiable $notifiable;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => self::FRONTEND_URL]);
        $this->notifiable = (new AnonymousNotifiable())->route('mail', 'recipient@example.test');
    }

    /**
     * @return array<string, array{0: Closure(string): Notification, 1: string}>
     */
    public static function venueNotifications(): array
    {
        return [
            'balance paid' => [
                fn (string $uuid) => new VenueBalancePaidNotification($uuid),
                'emails.venue-balance-paid-dark',
            ],
            'booking confirmed' => [
                fn (string $uuid) => new VenueBookingConfirmedNotification($uuid),
                'emails.venue-booking-confirmed-dark',
            ],
            'booking unavailable' => [
                fn (string $uuid) => new VenueBookingUnavailableNotification($uuid),
                'emails.venue-booking-unavailable-dark',
            ],
            'deposit paid' => [
                fn (string $uuid) => new VenueDepositPaidNotification($uuid),
                'emails.venue-deposit-paid-dark',
            ],
            'event completed (customer)' => [
                fn (string $uuid) => new VenueEventCompletedCustomerNotification($uuid),
                'emails.venue-event-completed-customer-dark',
            ],
            'event completed (merchant)' => [
                fn (string $uuid) => new VenueEventCompletedMerchantNotification($uuid),
                'emails.venue-event-completed-merchant-dark',
            ],
            'inquiry submitted' => [
                fn (string $uuid) => new VenueInquirySubmittedNotification($uuid),
                'emails.venue-inquiry-submitted-dark',
            ],
            'proposal accepted' => [
                fn (string $uuid) => new VenueProposalAcceptedNotification($uuid),
                'emails.venue-proposal-accepted-dark',
            ],
            'proposal declined' => [
                fn (string $uuid) => new VenueProposalDeclinedNotification($uuid),
                'emails.venue-proposal-declined-dark',
            ],
            'proposal sent' => [
                fn (string $uuid) => new VenueProposalSentNotification($uuid),
                'emails.venue-proposal-sent-dark',
            ],
            'visit customer response' => [
                fn (string $uuid) => new VenueVisitCustomerResponseNotification(
                    $uuid,
                    VenueVisitCustomerResponseNotification::RESPONSE_ACCEPTED,
                ),
                'emails.venue-visit-customer-response-merchant-dark',
            ],
            'visit scheduled' => [
                fn (string $uuid) => new VenueVisitScheduledNotification($uuid),
                'emails.venue-visit-scheduled-dark',
            ],
        ];
    }

    #[Test]
    #[DataProvider('venueNotifications')]
    public function itIsDeliveredByMailOnlyAndHasAnEmptyArrayRepresentation(Closure $make, string $view): void
    {
        $notification = $make((string) Str::uuid());

        $this->assertSame(['mail'], $notification->via($this->notifiable));
        $this->assertSame([], $notification->toArray($this->notifiable));
    }

    #[Test]
    #[DataProvider('venueNotifications')]
    public function itEmbedsTheFeaturedImageOnlyWhenTheRemoteFetchReturnsUsableImageBytes(Closure $make, string $view): void
    {
        $inquiry = $this->createVenueInquiry();
        $scenarios = $this->imageEmbedScenarios('venue-featured', svgHasOwnExtension: false);
        $this->fakeImageResponses(array_column($scenarios, 0));

        foreach ($scenarios as $label => [, $expectedEmbed]) {
            $mail = $make($inquiry->uuid)->toMail($this->notifiable);

            $this->assertSame($view, $mail->view, $label);
            $this->assertSame(self::FEATURED_IMAGE_URL, $mail->viewData['data']['venue']['image'], $label);
            $this->assertSame($expectedEmbed, $mail->viewData['data']['venue']['image_embed'], $label);
        }

        // A faked connection failure throws before the request is recorded.
        $answered = array_filter(array_column($scenarios, 0), fn ($response) => ! $response instanceof \Throwable);
        Http::assertSentCount(count($answered));
        Http::assertSent(fn ($request) => $request->url() === self::FEATURED_IMAGE_URL
            && $request->hasHeader('Accept', 'image/*'));
    }

    #[Test]
    #[DataProvider('venueNotifications')]
    public function itSkipsTheImageFetchWhenTheListingHasNoFeaturedImage(Closure $make, string $view): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $inquiry = $this->createVenueInquiry(withFeaturedImage: false);

        $mail = $make($inquiry->uuid)->toMail($this->notifiable);

        $this->assertNull($mail->viewData['data']['venue']['image']);
        $this->assertNull($mail->viewData['data']['venue']['image_embed']);
        Http::assertNothingSent();
    }

    #[Test]
    #[DataProvider('venueNotifications')]
    public function itFallsBackToGenericVenueWordingWhenTheListingNoLongerExists(Closure $make, string $view): void
    {
        Http::preventStrayRequests();
        Http::fake();
        $inquiry = $this->createVenueInquiry();
        $this->detachVenueListing($inquiry);

        $mail = $make($inquiry->uuid)->toMail($this->notifiable);
        $data = $mail->viewData['data'];

        $this->assertStringEndsWith('your venue', $mail->subject);
        $this->assertNull($data['venue']['image']);
        $this->assertNull($data['venue']['image_embed']);
        $this->assertNull($data['venue']['city']);
        $this->assertSame('Maria Santos', $data['guest']['full_name']);
        Http::assertNothingSent();
    }

    #[Test]
    #[DataProvider('venueNotifications')]
    public function itRendersTheDarkTemplateWithVenueAndInquiryDetails(Closure $make, string $view): void
    {
        $this->fakeImageResponses([Http::response('PNG-BYTES', 200, ['Content-Type' => 'image/png'])]);
        $inquiry = $this->createVenueInquiry();

        $mail = $make($inquiry->uuid)->toMail($this->notifiable);
        $html = (string) $mail->render();

        $this->assertStringStartsWith('[Ticketoc] ', $mail->subject);
        $this->assertStringContainsString('Skyline Function Hall', $mail->subject);
        $this->assertStringContainsString('Skyline Function Hall', $html);
        $this->assertStringContainsString($mail->viewData['data']['email_headline'], html_entity_decode($html, ENT_QUOTES));
        $this->assertSame($this->inquiryReference($inquiry), $mail->viewData['data']['inquiry']['reference']);
        $this->assertSame(date('Y'), $mail->viewData['data']['current_year']);
        $this->assertSame(self::FRONTEND_URL . '/privacy-policy', $mail->viewData['data']['privacy_policy_link']);
        $this->assertSame(self::FRONTEND_URL . '/terms-and-conditions', $mail->viewData['data']['tc_link']);
    }

    #[Test]
    #[DataProvider('venueNotifications')]
    public function itFailsLoudlyWhenTheInquiryDoesNotExist(Closure $make, string $view): void
    {
        $this->expectException(ModelNotFoundException::class);

        $make((string) Str::uuid())->toMail($this->notifiable);
    }
}
