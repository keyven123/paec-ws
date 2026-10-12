<?php

namespace Tests\Feature\Coverage\Commands;

use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ExpiryCommandFailureCoverageTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function expirePromoCodesRollsBackAndFailsWhenTheUpdateErrors(): void
    {
        Schema::drop('promo_codes');
        Log::spy();

        $this->artisan('app:expire-promo-codes')
            ->expectsOutputToContain('Error expiring promo codes:')
            ->assertExitCode(Command::FAILURE);

        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message, array $context) => str_starts_with($message, 'ExpirePromoCodes command failed: ')
                && $context['exception'] instanceof \Throwable,
        );
    }

    #[Test]
    public function expireUnusedTicketsRollsBackAndFailsWhenTheQueryErrors(): void
    {
        Schema::drop('tickets');
        Log::spy();

        $this->artisan('app:expire-unused-tickets')
            ->expectsOutputToContain('Error expiring tickets:')
            ->assertExitCode(Command::FAILURE);

        Log::shouldHaveReceived('error')->once()->withArgs(
            fn (string $message) => str_starts_with($message, 'ExpireTickets command failed: '),
        );
    }
}
