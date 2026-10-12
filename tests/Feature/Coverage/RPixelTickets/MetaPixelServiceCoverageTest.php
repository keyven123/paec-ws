<?php

namespace Tests\Feature\Coverage\RPixelTickets;

use App\Models\Transaction;
use App\Services\MetaPixelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MetaPixelServiceCoverageTest extends TestCase
{
    use RefreshDatabase;
    use RPixelTicketsFixtures;

    private const GRAPH = 'https://graph.facebook.com/v18.0/';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config([
            'services.meta.pixel_id' => 'GLOBAL_PIXEL',
            'services.meta.access_token' => 'GLOBAL_TOKEN_abcdefghijklmnopqrstuvwxyz',
            'services.meta.test_event_code' => null,
            'app.frontend_url' => 'https://front.test',
        ]);
    }

    private function service(): MetaPixelService
    {
        return new MetaPixelService;
    }

    #[Test]
    public function trackEventPostsHashedUserDataToGlobalPixel(): void
    {
        Http::fake([self::GRAPH . '*' => Http::response(['events_received' => 1], 200)]);

        $result = $this->service()->trackEvent('Lead', [
            'event_source_url' => 'https://front.test/page',
            'user_data' => [
                'email' => '  John@Example.COM ',
                'phone' => '+63 (917) 123-4567',
                'first_name' => ' John ',
                'last_name' => 'DOE',
                'external_id' => 'user-uuid-1',
                'client_ip_address' => '203.0.113.5',
                'client_user_agent' => 'Mozilla/5.0',
                'fbp' => 'fb.1.111.222',
                'fbc' => 'fb.1.333.abc',
            ],
            'custom_data' => ['value' => 10, 'currency' => 'PHP'],
        ]);

        $this->assertTrue($result);

        Http::assertSent(function (Request $request) {
            $data = $request->data();
            $event = $data['data'][0];

            return $request->url() === self::GRAPH . 'GLOBAL_PIXEL/events'
                && $request->hasHeader('Content-Type', 'application/json')
                && $data['access_token'] === 'GLOBAL_TOKEN_abcdefghijklmnopqrstuvwxyz'
                && ! array_key_exists('test_event_code', $data)
                && $event['event_name'] === 'Lead'
                && $event['action_source'] === 'website'
                && $event['event_source_url'] === 'https://front.test/page'
                && is_int($event['event_time'])
                && is_string($event['event_id']) && $event['event_id'] !== ''
                && $event['user_data']['em'] === hash('sha256', 'john@example.com')
                && $event['user_data']['ph'] === hash('sha256', '639171234567')
                && $event['user_data']['fn'] === hash('sha256', 'john')
                && $event['user_data']['ln'] === hash('sha256', 'doe')
                && $event['user_data']['external_id'] === 'user-uuid-1'
                && $event['user_data']['client_ip_address'] === '203.0.113.5'
                && $event['user_data']['client_user_agent'] === 'Mozilla/5.0'
                && $event['user_data']['fbp'] === 'fb.1.111.222'
                && $event['user_data']['fbc'] === 'fb.1.333.abc'
                && $event['custom_data'] === ['value' => 10, 'currency' => 'PHP'];
        });
    }

    #[Test]
    public function trackEventWithoutUserOrCustomDataOmitsThoseKeys(): void
    {
        Http::fake([self::GRAPH . '*' => Http::response([], 200)]);

        $this->assertTrue($this->service()->trackEvent('PageView', [
            'event_time' => 1700000000,
            'event_id' => 'fixed-id',
            'action_source' => 'app',
        ]));

        Http::assertSent(function (Request $request) {
            $event = $request->data()['data'][0];

            return $event['event_time'] === 1700000000
                && $event['event_id'] === 'fixed-id'
                && $event['action_source'] === 'app'
                && $event['event_source_url'] === null
                && ! array_key_exists('user_data', $event)
                && ! array_key_exists('custom_data', $event);
        });
    }

    #[Test]
    public function trackEventSkipsWhenNoPixelConfigured(): void
    {
        Http::fake();
        config(['services.meta.pixel_id' => null]);
        Log::spy();

        $this->assertFalse($this->service()->trackEvent('Lead', [
            'custom_data' => ['content_ids' => ['evt-1']],
        ]));

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message, $context) => str_contains($message, 'Missing pixel ID')
                && $context['event_uuid'] === 'evt-1'
                && $context['has_access_token'] === true
        )->once();
    }

    #[Test]
    public function trackEventUsesEventSpecificPixelAndTestEventCode(): void
    {
        Http::fake([self::GRAPH . '*' => Http::response(['events_received' => 1], 200)]);

        $event = $this->createEvent([
            'track_event_meta' => true,
            'meta_pixel_id' => 'EVENT_PIXEL',
            'meta_pixel_key' => 'EVENT_TOKEN',
            'meta_test_event_code' => 'TEST123',
        ]);

        $this->assertTrue($this->service()->trackEvent('Lead', [], $event));

        Http::assertSent(fn (Request $request) => $request->url() === self::GRAPH . 'EVENT_PIXEL/events'
            && $request['access_token'] === 'EVENT_TOKEN'
            && $request['test_event_code'] === 'TEST123');
    }

    #[Test]
    public function trackEventFallsBackToGlobalPixelWhenEventTrackingDisabled(): void
    {
        Http::fake([self::GRAPH . '*' => Http::response([], 200)]);
        config(['services.meta.test_event_code' => 'GLOBALTEST']);

        $event = $this->createEvent([
            'track_event_meta' => false,
            'meta_pixel_id' => 'EVENT_PIXEL',
            'meta_pixel_key' => 'EVENT_TOKEN',
        ]);

        $this->assertTrue($this->service()->trackEvent('Lead', [], $event));

        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/GLOBAL_PIXEL/events')
            && $request['test_event_code'] === 'GLOBALTEST');
    }

    #[Test]
    public function failedResponseIsLoggedAndReturnsFalse(): void
    {
        Http::fake([self::GRAPH . '*' => Http::response([
            'error' => ['message' => 'Invalid parameter', 'code' => 100, 'error_subcode' => 2804003],
        ], 400)]);
        Log::spy();

        $this->assertFalse($this->service()->trackEvent('Lead', []));

        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message, $context) => $message === 'Meta Pixel event tracking failed: Lead'
                && $context['status'] === 400
                && $context['error_code'] === 100
                && $context['error_subcode'] === 2804003
                && $context['error_message'] === 'Invalid parameter'
                && $context['using_event_specific'] === false
        )->once();
        Log::shouldNotHaveReceived('error');
    }

    #[Test]
    public function failedResponseWithNonJsonBodyUsesRawBody(): void
    {
        Http::fake([self::GRAPH . '*' => Http::response('Service Unavailable', 503)]);
        Log::spy();

        $this->assertFalse($this->service()->trackEvent('Lead', []));

        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message, $context) => $context['status'] === 503
                && $context['error_message'] === 'Service Unavailable'
                && $context['error_code'] === null
                && $context['error_subcode'] === null
        )->once();
    }

    #[Test]
    public function permissionErrorWithGlobalTokenLogsGlobalSuggestion(): void
    {
        Http::fake([self::GRAPH . '*' => Http::response([
            'error' => ['message' => 'Unsupported post request', 'code' => 100, 'error_subcode' => 33],
        ], 400)]);
        Log::spy();

        $this->assertFalse($this->service()->trackEvent('Lead', []));

        Log::shouldHaveReceived('error')->withArgs(
            fn ($message, $context) => $message === 'Global Meta Pixel access token is invalid or missing permissions.'
                && $context['pixel_id'] === 'GLOBAL_PIXEL'
                && $context['access_token_prefix'] === 'GLOBAL_TOKEN_abcdefg...'
                && str_contains($context['suggestion'], 'GLOBAL_PIXEL')
        )->once();
    }

    #[Test]
    public function permissionErrorWithEventTokenLogsEventSpecificSuggestion(): void
    {
        Http::fake([self::GRAPH . '*' => Http::response([
            'error' => ['message' => 'Unsupported post request', 'code' => 100, 'error_subcode' => 33],
        ], 400)]);
        Log::spy();

        $event = $this->createEvent([
            'event_name' => 'Pixel Gala',
            'track_event_meta' => true,
            'meta_pixel_id' => 'EVENT_PIXEL',
            'meta_pixel_key' => 'EVENT_TOKEN',
        ]);

        $this->assertFalse($this->service()->trackEvent('Lead', [], $event));

        Log::shouldHaveReceived('error')->withArgs(
            fn ($message, $context) => $message === 'Event-specific Meta Pixel access token is invalid or missing permissions.'
                && $context['event_uuid'] === $event->uuid
                && $context['event_name'] === 'Pixel Gala'
                && $context['event_pixel_id'] === 'EVENT_PIXEL'
                && $context['access_token_prefix'] === 'EVENT_TOKEN...'
        )->once();
    }

    #[Test]
    public function connectionFailureIsCaughtAndLogged(): void
    {
        Http::fake([self::GRAPH . '*' => Http::failedConnection('cURL error 28: timed out')]);
        Log::spy();

        $this->assertFalse($this->service()->trackEvent('Lead', []));

        Log::shouldHaveReceived('error')->withArgs(
            fn ($message, $context) => $message === 'Meta Pixel event tracking error: Lead'
                && $context['pixel_id'] === 'GLOBAL_PIXEL'
                && str_contains($context['error'], 'timed out')
        )->once();
    }

    #[Test]
    public function trackViewContentSendsEventMetadata(): void
    {
        Http::fake([self::GRAPH . '*' => Http::response([], 200)]);
        $category = $this->createCategory('Concerts');
        $event = $this->createEvent(['event_name' => 'Rock Night', 'category_uuid' => $category->uuid]);

        $this->assertTrue($this->service()->trackViewContent($event, ['email' => 'a@b.c'], 'https://front.test/e/1'));

        Http::assertSent(function (Request $request) use ($event) {
            $payload = $request['data'][0];

            return $payload['event_name'] === 'ViewContent'
                && str_starts_with($payload['event_id'], 'view_' . $event->uuid . '_')
                && $payload['event_source_url'] === 'https://front.test/e/1'
                && $payload['user_data']['em'] === hash('sha256', 'a@b.c')
                && $payload['custom_data']['content_name'] === 'Rock Night'
                && $payload['custom_data']['content_category'] === 'Concerts'
                && $payload['custom_data']['content_ids'] === [$event->uuid]
                && $payload['custom_data']['value'] === 0
                && $payload['custom_data']['currency'] === 'PHP';
        });
    }

    #[Test]
    public function trackInitiateCheckoutSendsValueAndDefaultCategory(): void
    {
        Http::fake([self::GRAPH . '*' => Http::response([], 200)]);
        $event = $this->createEvent(['event_name' => 'No Category Fest']);

        $this->assertTrue($this->service()->trackInitiateCheckout($event, 1499.5));

        Http::assertSent(function (Request $request) use ($event) {
            $payload = $request['data'][0];

            return $payload['event_name'] === 'InitiateCheckout'
                && str_starts_with($payload['event_id'], 'initiate_' . $event->uuid . '_')
                && $payload['custom_data']['content_category'] === 'Event'
                && $payload['custom_data']['value'] == 1499.5
                && $payload['user_data'] === [];
        });
    }

    #[Test]
    public function trackAddPaymentInfoSendsValue(): void
    {
        Http::fake([self::GRAPH . '*' => Http::response([], 200)]);
        $event = $this->createEvent(['event_name' => 'Pay Fest']);

        $this->assertTrue($this->service()->trackAddPaymentInfo($event, 300.0, 'gcash', ['phone' => '0917'], 'https://front.test/pay'));

        Http::assertSent(function (Request $request) use ($event) {
            $payload = $request['data'][0];

            return $payload['event_name'] === 'AddPaymentInfo'
                && str_starts_with($payload['event_id'], 'payment_' . $event->uuid . '_')
                && $payload['event_source_url'] === 'https://front.test/pay'
                && $payload['user_data']['ph'] === hash('sha256', '0917')
                && $payload['custom_data']['value'] == 300;
        });
    }

    #[Test]
    public function trackPurchaseSendsOrderContents(): void
    {
        Http::fake([self::GRAPH . '*' => Http::response([], 200)]);
        $user = $this->createCustomer();
        $event = $this->createEvent(['event_name' => 'Purchase Fest']);
        $eventTicket = $this->createEventTicket($event, ['name' => 'VIP', 'code' => 'VIP-1']);
        $tx = $this->createTransaction($user, $event, ['total_amount' => 500, 'paid_at' => '2026-10-01 10:00:00']);
        $this->createOrder($tx, $eventTicket, ['quantity' => 2, 'price' => 250]);

        $this->assertTrue($this->service()->trackPurchase($tx->fresh(), ['email' => 'buyer@test.com']));

        Http::assertSent(function (Request $request) use ($tx, $eventTicket) {
            $payload = $request['data'][0];

            return $payload['event_name'] === 'Purchase'
                && $payload['event_id'] === 'purchase_' . $tx->uuid
                && $payload['event_time'] === $tx->fresh()->paid_at->timestamp
                && $payload['event_source_url'] === 'https://front.test/payment/success/' . $tx->uuid
                && $payload['custom_data']['num_items'] === 2
                && $payload['custom_data']['order_id'] === $tx->uuid
                && $payload['custom_data']['contents'][0]['id'] === $eventTicket->uuid
                && $payload['custom_data']['contents'][0]['name'] === 'VIP'
                && $payload['custom_data']['contents'][0]['category'] === 'VIP-1';
        });
    }

    #[Test]
    public function trackPurchaseSkipsTransactionWithoutEvent(): void
    {
        Http::fake();
        Log::spy();
        $tx = new Transaction;
        $tx->uuid = (string) Str::uuid();

        $this->assertFalse($this->service()->trackPurchase($tx));

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message, $context) => str_contains($message, 'Purchase tracking skipped')
                && $context['transaction_uuid'] === $tx->uuid
        )->once();
    }

    #[Test]
    public function trackPaymentCancellationSendsCancelCheckout(): void
    {
        Http::fake([self::GRAPH . '*' => Http::response([], 200)]);
        $user = $this->createCustomer();
        $event = $this->createEvent();
        $eventTicket = $this->createEventTicket($event);
        $tx = $this->createTransaction($user, $event, ['total_amount' => 250]);
        $this->createOrder($tx, $eventTicket, ['quantity' => 1, 'price' => 250]);

        $this->assertTrue($this->service()->trackPaymentCancellation($tx->fresh()));

        Http::assertSent(function (Request $request) use ($tx) {
            $payload = $request['data'][0];

            return $payload['event_name'] === 'CancelCheckout'
                && $payload['event_id'] === 'cancel_checkout_' . $tx->uuid
                && $payload['event_source_url'] === 'https://front.test/payment/cancel/' . $tx->uuid
                && $payload['custom_data']['num_items'] === 1
                && $payload['custom_data']['value'] == 250;
        });
    }

    #[Test]
    public function trackPaymentCancellationSkipsTransactionWithoutEvent(): void
    {
        Http::fake();
        Log::spy();
        $tx = new Transaction;
        $tx->uuid = (string) Str::uuid();

        $this->assertFalse($this->service()->trackPaymentCancellation($tx));

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message, $context) => str_contains($message, 'cancellation tracking skipped')
                && $context['transaction_uuid'] === $tx->uuid
        )->once();
    }
}
