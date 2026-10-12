<?php

namespace Tests\Feature\Coverage\GapTickets;

use App\Models\Transaction;
use App\Models\User;
use App\Models\VenueInquiry;
use App\Services\Checkout\VenueBookingCheckoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CustomerVenueInquiryControllerTest extends TestCase
{
    use GapTicketsFixtures;
    use RefreshDatabase;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = $this->createCustomer(['email' => 'owner@test.com']);
    }

    private function inquiry(array $attributes = []): VenueInquiry
    {
        return VenueInquiry::factory()->create(array_merge([
            'user_uuid' => $this->customer->uuid,
            'email' => $this->customer->email,
        ], $attributes));
    }

    private function mockCheckout(callable $expectation): void
    {
        $this->mock(VenueBookingCheckoutService::class, $expectation);
    }

    private function pay(VenueInquiry $inquiry, string $provider = 'paymongo')
    {
        return $this->withHeaders($this->customerHeaders($this->customer))
            ->postJson("/api/v1/customer/venue-inquiries/{$inquiry->uuid}/pay", [
                'payment_provider' => $provider,
            ]);
    }

    #[Test]
    public function payReturnsCheckoutUrlAsRedirect(): void
    {
        $inquiry = $this->inquiry(['status' => VenueInquiry::STATUSES['DEPOSIT_REQUESTED']]);
        $this->mockCheckout(function ($mock) use ($inquiry) {
            $mock->shouldReceive('startPayment')
                ->once()
                ->withArgs(fn ($passed, $userUuid, $payload) => $passed->is($inquiry)
                    && $userUuid === $this->customer->uuid
                    && $payload['payment_provider'] === 'paymongo')
                ->andReturn([
                    'transaction' => ['uuid' => 'tx-1'],
                    'payment_result' => [
                        'payment_id' => 'pi_123',
                        'status' => 'awaiting_payment',
                        'checkout_url' => 'https://pay.test/checkout',
                    ],
                ]);
        });

        $this->pay($inquiry)
            ->assertCreated()
            ->assertExactJson([
                'success' => true,
                'transaction' => ['uuid' => 'tx-1'],
                'payment' => [
                    'provider' => 'paymongo',
                    'payment_id' => 'pi_123',
                    'status' => 'awaiting_payment',
                    'checkout_url' => 'https://pay.test/checkout',
                ],
                'redirect_url' => 'https://pay.test/checkout',
            ]);
    }

    #[Test]
    public function payReturnsApprovalUrlAsRedirect(): void
    {
        $inquiry = $this->inquiry();
        $this->mockCheckout(fn ($mock) => $mock->shouldReceive('startPayment')->once()->andReturn([
            'transaction' => ['uuid' => 'tx-2'],
            'payment_result' => ['approval_url' => 'https://paypal.test/approve'],
        ]));

        $this->pay($inquiry, 'paypal')
            ->assertCreated()
            ->assertJsonPath('payment.provider', 'paypal')
            ->assertJsonPath('payment.payment_id', null)
            ->assertJsonPath('payment.status', 'pending')
            ->assertJsonPath('payment.approval_url', 'https://paypal.test/approve')
            ->assertJsonPath('redirect_url', 'https://paypal.test/approve');
    }

    #[Test]
    public function payWithoutRedirectUrlsOmitsRedirect(): void
    {
        $inquiry = $this->inquiry();
        $this->mockCheckout(fn ($mock) => $mock->shouldReceive('startPayment')->once()->andReturn([
            'transaction' => ['uuid' => 'tx-3'],
            'payment_result' => ['payment_id' => 'free-1', 'status' => 'paid'],
        ]));

        $response = $this->pay($inquiry, 'free')->assertCreated();

        $response->assertJsonPath('payment.status', 'paid');
        $response->assertJsonMissingPath('redirect_url');
        $response->assertJsonMissingPath('payment.checkout_url');
        $response->assertJsonMissingPath('payment.approval_url');
    }

    #[Test]
    public function payRethrowsValidationErrorsFromCheckout(): void
    {
        $inquiry = $this->inquiry(['status' => VenueInquiry::STATUSES['NEW']]);

        $this->pay($inquiry)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['inquiry']);

        $this->assertSame(0, Transaction::count());
    }

    #[Test]
    public function payReturns500AndLogsOnUnexpectedFailure(): void
    {
        Log::spy();
        $inquiry = $this->inquiry();
        $this->mockCheckout(fn ($mock) => $mock->shouldReceive('startPayment')
            ->once()
            ->andThrow(new \RuntimeException('gateway down')));

        $this->pay($inquiry)
            ->assertStatus(500)
            ->assertExactJson([
                'success' => false,
                'message' => 'Failed to start venue payment. Please try again.',
            ]);

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context) => $message === 'Venue inquiry payment failed'
                && $context === ['venue_inquiry_uuid' => $inquiry->uuid, 'error' => 'gateway down'])
            ->once();
    }

    public static function workflowEndpoints(): array
    {
        return [
            'accept proposal' => ['accept-proposal', []],
            'decline proposal' => ['decline-proposal', []],
            'accept visit' => ['accept-visit', []],
            'decline visit' => ['decline-visit', []],
            'suggest visit date' => ['suggest-visit-date', [
                'suggested_date' => '2030-01-10',
                'suggested_time' => '10:00',
            ]],
        ];
    }

    #[Test]
    #[DataProvider('workflowEndpoints')]
    public function workflowEndpointsRejectInquiryOwnedByAnotherUser(string $action, array $body): void
    {
        $other = $this->createCustomer();
        $inquiry = $this->inquiry([
            'user_uuid' => $other->uuid,
            'email' => $this->customer->email,
            'status' => VenueInquiry::STATUSES['PROPOSAL_SENT'],
        ]);

        $this->withHeaders($this->customerHeaders($this->customer))
            ->postJson("/api/v1/customer/venue-inquiries/{$inquiry->uuid}/{$action}", $body)
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have access to this inquiry.');

        $this->assertSame(VenueInquiry::STATUSES['PROPOSAL_SENT'], $inquiry->fresh()->status);
    }

    #[Test]
    #[DataProvider('workflowEndpoints')]
    public function workflowEndpointsRejectGuestInquiryWithDifferentEmail(string $action, array $body): void
    {
        $inquiry = $this->inquiry([
            'user_uuid' => null,
            'email' => 'someone-else@test.com',
            'status' => VenueInquiry::STATUSES['PROPOSAL_SENT'],
        ]);

        $this->withHeaders($this->customerHeaders($this->customer))
            ->postJson("/api/v1/customer/venue-inquiries/{$inquiry->uuid}/{$action}", $body)
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have access to this inquiry.');

        $this->assertSame(VenueInquiry::STATUSES['PROPOSAL_SENT'], $inquiry->fresh()->status);
    }
}
