<?php

namespace Tests\Feature\Coverage\Commands;

use App\Constants\GeneralConstants;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExpireTicketsCommandCoverageTest extends TestCase
{
    use CommandFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-20 12:00:00', 'Asia/Manila'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function itReactivatesPrematurelyExpiredTicketsAndExpiresPastDueOnes(): void
    {
        $occurrence = $this->makeEventOccurrence('2026-06-25');
        $transaction = $this->makeTransaction($this->makeCustomer(), $occurrence);

        $stillValid = $this->makeTicket($transaction, $occurrence, [
            'status' => GeneralConstants::TICKET_STATUSES['EXPIRED'],
            'valid_until' => '2026-06-22 00:00:00',
        ]);
        $pastDue = $this->makeTicket($transaction, $occurrence, [
            'valid_until' => '2026-06-18 00:00:00',
        ]);
        $pendingPastDue = $this->makeTicket($transaction, $occurrence, [
            'status' => GeneralConstants::TICKET_STATUSES['PENDING'],
            'valid_until' => '2026-06-19 00:00:00',
        ]);
        $usedPastDue = $this->makeTicket($transaction, $occurrence, [
            'status' => GeneralConstants::TICKET_STATUSES['USED'],
            'valid_until' => '2026-06-18 00:00:00',
            'used_at' => '2026-06-18 10:00:00',
        ]);

        $this->artisan('app:expire-tickets')
            ->expectsOutput('Reactivated 1 prematurely expired ticket(s).')
            ->expectsOutput('Successfully expired 2 ticket(s).')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(GeneralConstants::TICKET_STATUSES['ACTIVE'], $stillValid->fresh()->status);
        $this->assertSame(GeneralConstants::TICKET_STATUSES['EXPIRED'], $pastDue->fresh()->status);
        $this->assertSame(GeneralConstants::TICKET_STATUSES['EXPIRED'], $pendingPastDue->fresh()->status);
        $this->assertSame(GeneralConstants::TICKET_STATUSES['USED'], $usedPastDue->fresh()->status);
    }

    #[Test]
    public function itReportsWhenThereIsNothingToExpire(): void
    {
        $occurrence = $this->makeEventOccurrence('2026-06-25');
        $ticket = $this->makeTicket($this->makeTransaction($this->makeCustomer(), $occurrence), $occurrence, [
            'valid_until' => '2026-06-25 00:00:00',
        ]);

        $this->artisan('app:expire-tickets')
            ->expectsOutput('No tickets to expire.')
            ->doesntExpectOutputToContain('Reactivated')
            ->assertExitCode(Command::SUCCESS);

        $this->assertSame(GeneralConstants::TICKET_STATUSES['ACTIVE'], $ticket->fresh()->status);
    }

    #[Test]
    public function itRollsBackAndFailsWhenTheQueryErrors(): void
    {
        Schema::drop('tickets');
        Log::spy();

        $this->artisan('app:expire-tickets')
            ->expectsOutputToContain('Error expiring tickets:')
            ->assertExitCode(Command::FAILURE);

        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message) => str_starts_with($message, 'ExpireTickets command failed: '),
        );
    }
}
