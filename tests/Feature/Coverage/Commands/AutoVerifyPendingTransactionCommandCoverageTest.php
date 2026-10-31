<?php

namespace Tests\Feature\Coverage\Commands;

use App\Http\Controllers\Customer\TempTransactionController;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class AutoVerifyPendingTransactionCommandCoverageTest extends TestCase
{
    use CommandFixtures;
    use RefreshDatabase;

    #[Test]
    public function itVerifiesEveryRecentPendingTransactionAndReportsEachOutcome(): void
    {
        $occurrence = $this->makeEventOccurrence('2026-06-25');
        $customer = $this->makeCustomer();
        $pending = fn (string $paymentId, array $overrides = []) => $this->makeTransaction($customer, $occurrence, array_merge([
            'payment_id' => $paymentId,
            'payment_provider' => 'paymongo',
            'payment_status' => Transaction::PAYMENT_STATUS['PENDING'],
            'order_status' => Transaction::ORDER_STATUS['PENDING'],
        ], $overrides));

        $verified = $pending('cs_verified');
        $stillPending = $pending('cs_pending');
        $failed = $pending('cs_failed');
        $unexpected = $pending('cs_unexpected');
        $throws = $pending('cs_throws');

        $tooOld = $pending('cs_old');
        DB::table('transactions')->where('uuid', $tooOld->uuid)->update(['created_at' => now()->subHours(25)]);
        $pending('', ['payment_id' => null]);
        $pending('cs_confirmed', ['order_status' => Transaction::ORDER_STATUS['CONFIRMED']]);

        $this->mock(TempTransactionController::class, function (MockInterface $mock) use ($verified, $stillPending, $failed, $unexpected, $throws): void {
            $mock->shouldReceive('verifyPendingPayment')->once()->with($verified->uuid)
                ->andReturn(response()->json(['success' => true, 'message' => 'Payment completed']));
            $mock->shouldReceive('verifyPendingPayment')->once()->with($stillPending->uuid)
                ->andReturn(response()->json(['success' => false, 'message' => 'Payment is still pending'], 202));
            $mock->shouldReceive('verifyPendingPayment')->once()->with($failed->uuid)
                ->andReturn(response()->json(['success' => false, 'message' => 'Payment failed'], 400));
            $mock->shouldReceive('verifyPendingPayment')->once()->with($unexpected->uuid)
                ->andReturn(response()->json(['success' => false], 422));
            $mock->shouldReceive('verifyPendingPayment')->once()->with($throws->uuid)
                ->andThrow(new RuntimeException('Gateway timeout'));
        });
        Log::spy();

        $this->artisan('app:auto-verify-pending-transaction')
            ->expectsOutputToContain('Found 5 transaction(s) to auto verify.')
            ->expectsOutputToContain("Verified transaction #{$verified->uuid}.")
            ->expectsOutputToContain("Transaction #{$stillPending->uuid} is still pending on the gateway.")
            ->expectsOutputToContain("Transaction #{$failed->uuid} was cancelled after gateway reported failure.")
            ->expectsOutputToContain("Verification did not complete for transaction #{$unexpected->uuid}. HTTP 422 - Unknown response from payment service.")
            ->expectsOutputToContain("Exception while verifying transaction #{$throws->uuid}: Gateway timeout")
            ->expectsOutputToContain('Verified 1, still pending 1, cancelled after gateway failure 1, gateway unpaid 0.')
            ->assertSuccessful();

        Log::shouldHaveReceived('error')->once()->with('Auto verify pending transaction failed', [
            'transaction_uuid' => $throws->uuid,
            'payment_provider' => 'paymongo',
            'error' => 'Gateway timeout',
        ]);
    }

    #[Test]
    public function itFinishesCleanlyWhenNothingIsPending(): void
    {
        $this->mock(TempTransactionController::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('verifyPendingPayment');
        });

        $this->artisan('app:auto-verify-pending-transaction')
            ->expectsOutputToContain('Found 0 transaction(s) to auto verify.')
            ->expectsOutputToContain('Verified 0, still pending 0, cancelled after gateway failure 0, gateway unpaid 0.')
            ->assertSuccessful();
    }
}
