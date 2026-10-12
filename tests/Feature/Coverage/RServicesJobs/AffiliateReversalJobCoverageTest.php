<?php

namespace Tests\Feature\Coverage\RServicesJobs;

use App\Jobs\RecordAffiliateCommissionReversalForCancelledTicketJob;
use App\Models\AffiliateConversion;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SeedsUserAffiliate;
use Tests\Feature\Coverage\RServicesJobs\Concerns\CreatesRServicesJobsFixtures;
use Tests\TestCase;

class AffiliateReversalJobCoverageTest extends TestCase
{
    use CreatesRServicesJobsFixtures;
    use RefreshDatabase;
    use SeedsUserAffiliate;

    private User $partner;

    private User $buyer;

    private Event $event;

    private EventTicket $eventTicket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->partner = $this->createRsjUser('Partner');
        $this->seedApprovedAffiliate($this->partner, 'RSJREV');
        $this->buyer = $this->createRsjUser('Buyer');
        $this->event = $this->createRsjEvent(['affiliate_enabled' => true, 'affiliate_commission_percent' => 10]);
        $this->eventTicket = $this->createRsjEventTicket($this->event);
    }

    private function referredTransaction(array $attributes = []): Transaction
    {
        return $this->createRsjTransaction($this->event, $this->buyer, array_merge([
            'affiliate_partner_uuid' => $this->partner->uuid,
        ], $attributes));
    }

    private function credit(Transaction $transaction, float $amount): AffiliateConversion
    {
        return AffiliateConversion::create([
            'partner_user_uuid' => $this->partner->uuid,
            'transaction_uuid' => $transaction->uuid,
            'entry_type' => AffiliateConversion::ENTRY_TYPE_CREDIT,
            'event_uuid' => $this->event->uuid,
            'order_total' => $amount * 10,
            'commission_percent' => 10,
            'commission_amount' => $amount,
        ]);
    }

    private function reversal(Transaction $transaction, Ticket $ticket, float $amount): AffiliateConversion
    {
        return AffiliateConversion::create([
            'partner_user_uuid' => $this->partner->uuid,
            'transaction_uuid' => $transaction->uuid,
            'entry_type' => AffiliateConversion::ENTRY_TYPE_REVERSAL,
            'ticket_uuid' => $ticket->uuid,
            'event_uuid' => $this->event->uuid,
            'order_total' => 0,
            'commission_percent' => 10,
            'commission_amount' => $amount,
        ]);
    }

    private function reversalsFor(Ticket $ticket)
    {
        return AffiliateConversion::where('ticket_uuid', $ticket->uuid)
            ->where('entry_type', AffiliateConversion::ENTRY_TYPE_REVERSAL)
            ->get();
    }

    private function runJob(Ticket $ticket): void
    {
        (new RecordAffiliateCommissionReversalForCancelledTicketJob($ticket->uuid))->handle();
    }

    #[Test]
    public function itDoesNothingForUnknownTicket(): void
    {
        (new RecordAffiliateCommissionReversalForCancelledTicketJob((string) Str::uuid()))->handle();

        $this->assertDatabaseCount('affiliate_conversions', 0);
    }

    #[Test]
    public function itSkipsUnpaidTransactions(): void
    {
        $transaction = $this->referredTransaction(['payment_status' => Transaction::PAYMENT_STATUS['PENDING']]);
        $this->credit($transaction, 100);
        $ticket = $this->createRsjTicket($transaction, $this->eventTicket);

        $this->runJob($ticket);

        $this->assertCount(0, $this->reversalsFor($ticket));
    }

    #[Test]
    public function itSkipsTransactionsWithoutAffiliatePartner(): void
    {
        $transaction = $this->createRsjTransaction($this->event, $this->buyer);
        $this->credit($transaction, 100);
        $ticket = $this->createRsjTicket($transaction, $this->eventTicket);

        $this->runJob($ticket);

        $this->assertCount(0, $this->reversalsFor($ticket));
    }

    #[Test]
    public function itSkipsWhenNoCreditWasRecorded(): void
    {
        $transaction = $this->referredTransaction();
        $ticket = $this->createRsjTicket($transaction, $this->eventTicket);

        $this->runJob($ticket);

        $this->assertDatabaseCount('affiliate_conversions', 0);
    }

    #[Test]
    public function itSkipsWhenCreditIsAlreadyFullyReversed(): void
    {
        $transaction = $this->referredTransaction();
        $this->credit($transaction, 100);
        $ticketA = $this->createRsjTicket($transaction, $this->eventTicket);
        $ticketB = $this->createRsjTicket($transaction, $this->eventTicket);
        $this->reversal($transaction, $ticketB, -100);

        $this->runJob($ticketA);

        $this->assertCount(0, $this->reversalsFor($ticketA));
    }

    #[Test]
    public function itSplitsEvenlyWhenTicketHasNoNetValue(): void
    {
        $transaction = $this->referredTransaction(['total_amount' => 900]);
        $this->credit($transaction, 90);
        $freeTicket = $this->createRsjTicket($transaction, $this->eventTicket, ['price' => 0]);
        $this->createRsjTicket($transaction, $this->eventTicket, ['price' => 450]);
        $this->createRsjTicket($transaction, $this->eventTicket, ['price' => 450]);

        $this->runJob($freeTicket);

        $reversals = $this->reversalsFor($freeTicket);
        $this->assertCount(1, $reversals);
        $this->assertEquals(-30, (float) $reversals->first()->commission_amount);
        $this->assertEquals(300, (float) $reversals->first()->order_total);
    }

    #[Test]
    public function itCapsReversalAtRemainingCredit(): void
    {
        $transaction = $this->referredTransaction();
        $this->credit($transaction, 100);
        $ticketA = $this->createRsjTicket($transaction, $this->eventTicket, ['price' => 100]);
        $ticketB = $this->createRsjTicket($transaction, $this->eventTicket, ['price' => 100]);
        $this->reversal($transaction, $ticketB, -80);

        $this->runJob($ticketA);

        $reversals = $this->reversalsFor($ticketA);
        $this->assertCount(1, $reversals);
        $this->assertEquals(-20, (float) $reversals->first()->commission_amount);
    }

    #[Test]
    public function itSkipsReversalsBelowOneCent(): void
    {
        $transaction = $this->referredTransaction();
        $this->credit($transaction, 0.01);
        $ticket = $this->createRsjTicket($transaction, $this->eventTicket, ['price' => 100]);
        $this->createRsjTicket($transaction, $this->eventTicket, ['price' => 100]);
        $this->createRsjTicket($transaction, $this->eventTicket, ['price' => 100]);

        $this->runJob($ticket);

        $this->assertCount(0, $this->reversalsFor($ticket));
    }

    #[Test]
    public function itLogsFailuresWhileRecordingReversal(): void
    {
        $transaction = $this->referredTransaction();
        $this->credit($transaction, 100);
        $ticket = $this->createRsjTicket($transaction, $this->eventTicket, ['price' => 100]);
        AffiliateConversion::creating(function () {
            throw new \RuntimeException('ledger locked');
        });
        Log::spy();

        $this->runJob($ticket);

        $this->assertCount(0, $this->reversalsFor($ticket));
        Log::shouldHaveReceived('error')->once()->with('Affiliate commission reversal failed', [
            'ticket_uuid' => $ticket->uuid,
            'transaction_uuid' => $transaction->uuid,
            'error' => 'ledger locked',
        ]);
    }
}
