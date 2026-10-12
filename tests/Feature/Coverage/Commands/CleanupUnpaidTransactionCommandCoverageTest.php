<?php

namespace Tests\Feature\Coverage\Commands;

use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CleanupUnpaidTransactionCommandCoverageTest extends TestCase
{
    use CommandFixtures;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function itCancelsStaleUnpaidTransactionsAndDeletesTheirTickets(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-20 12:00:00'));

        $occurrence = $this->makeEventOccurrence('2026-06-25');
        $customer = $this->makeCustomer();

        $stale = $this->makeTransaction($customer, $occurrence, [
            'payment_status' => Transaction::PAYMENT_STATUS['PENDING'],
            'order_status' => Transaction::ORDER_STATUS['PENDING'],
        ]);
        $staleTickets = [
            $this->makeTicket($stale, $occurrence),
            $this->makeTicket($stale, $occurrence),
        ];

        $recent = $this->makeTransaction($customer, $occurrence, [
            'payment_status' => Transaction::PAYMENT_STATUS['PENDING'],
        ]);
        $recentTicket = $this->makeTicket($recent, $occurrence);

        $stalePaid = $this->makeTransaction($customer, $occurrence, ['paid_at' => now()->subDays(2)]);
        $paidTicket = $this->makeTicket($stalePaid, $occurrence);

        $staleWithoutTickets = $this->makeTransaction($customer, $occurrence, [
            'payment_status' => Transaction::PAYMENT_STATUS['PENDING'],
        ]);

        DB::table('transactions')
            ->whereIn('uuid', [$stale->uuid, $stalePaid->uuid, $staleWithoutTickets->uuid])
            ->update(['created_at' => now()->subHours(25)]);

        $this->artisan('app:cleanup-unpaid-transaction')
            ->expectsOutput('Found 1 unpaid transaction(s) to cleanup.')
            ->expectsOutput("Cleaned up transaction #{$stale->uuid} with 2 ticket(s).")
            ->expectsOutput('Cleanup completed. Processed 1 transaction(s).')
            ->assertSuccessful();

        $stale->refresh();
        $this->assertSame(Transaction::STATUS['CANCELLED'], $stale->status);
        $this->assertSame(Transaction::PAYMENT_STATUS['FAILED'], $stale->payment_status);
        foreach ($staleTickets as $ticket) {
            $this->assertSoftDeleted('tickets', ['uuid' => $ticket->uuid]);
        }

        $this->assertSame(Transaction::PAYMENT_STATUS['PENDING'], $recent->fresh()->payment_status);
        $this->assertNotSoftDeleted('tickets', ['uuid' => $recentTicket->uuid]);
        $this->assertSame(Transaction::PAYMENT_STATUS['PAID'], $stalePaid->fresh()->payment_status);
        $this->assertNotSoftDeleted('tickets', ['uuid' => $paidTicket->uuid]);
        $this->assertSame(Transaction::STATUS['ACTIVE'], $staleWithoutTickets->fresh()->status);
    }

    #[Test]
    public function itReportsZeroWhenNothingIsStale(): void
    {
        $this->artisan('app:cleanup-unpaid-transaction')
            ->expectsOutput('Found 0 unpaid transaction(s) to cleanup.')
            ->expectsOutput('Cleanup completed. Processed 0 transaction(s).')
            ->assertSuccessful();
    }
}
