<?php

namespace Tests\Feature\Coverage\RVenueCheckout;

use App\Jobs\SuccessPaymentProcess;
use App\Models\Transaction;
use App\Services\Checkout\EventCheckoutHandler;
use App\Services\MetaPixelService;
use App\Services\Platform\TransactionCommissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EventCheckoutHandlerTest extends TestCase
{
    use CreatesVenueCustomers;
    use RefreshDatabase;

    #[Test]
    public function metaPixelFailureForDeletedBuyerIsLoggedWithoutBreakingCheckout(): void
    {
        Queue::fake();
        Log::spy();

        $buyer = $this->createVenueCustomer();
        $transaction = Transaction::create([
            'user_uuid' => $buyer->uuid,
            'transactionable_type' => 'event',
            'order_number' => 'EVT-' . Str::upper(Str::random(8)),
            'sub_total' => 500,
            'tax_amount' => 0,
            'discount' => 0,
            'total_amount' => 500,
            'payment_status' => Transaction::PAYMENT_STATUS['PENDING'],
        ]);
        $buyer->delete();

        $commission = Mockery::mock(TransactionCommissionService::class);
        $commission->shouldReceive('recordPaidTransaction')
            ->once()
            ->with(Mockery::on(fn ($t) => $t instanceof Transaction && $t->uuid === $transaction->uuid));

        $pixel = Mockery::mock(MetaPixelService::class);
        $pixel->shouldReceive('trackPurchase')
            ->once()
            ->with(Mockery::type(Transaction::class), [])
            ->andThrow(new \RuntimeException('pixel offline'));

        $handler = new EventCheckoutHandler($commission, $pixel);
        $handler->handlePaid($transaction);

        $this->assertSame('event', $handler->morphAlias());
        Queue::assertPushed(SuccessPaymentProcess::class);
        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context) => $message === 'Failed to track Meta Pixel purchase event'
                && $context['transaction_uuid'] === $transaction->uuid
                && $context['error'] === 'pixel offline')
            ->once();
    }
}
