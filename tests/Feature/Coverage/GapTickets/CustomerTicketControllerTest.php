<?php

namespace Tests\Feature\Coverage\GapTickets;

use App\Constants\GeneralConstants;
use App\Http\Repositories\TicketRepository;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\User;
use App\Models\VenueSeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CustomerTicketControllerTest extends TestCase
{
    use GapTicketsFixtures;
    use RefreshDatabase;

    private User $customer;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = $this->createCustomer();
        $event = $this->createEvent();
        $this->ticket = $this->createTicket(
            $this->createTransaction($this->customer, $event),
            $this->createEventTicket($event),
        );
    }

    /**
     * Partial mock so ownership lookup stays real while the write step fails.
     */
    private function failingRepository(string $method, \Throwable $exception): void
    {
        $repository = Mockery::mock(TicketRepository::class, [
            new Ticket(), new User(), new Transaction(), new Event(), new VenueSeat(), new EventTicket(),
        ])->makePartial();
        $repository->shouldReceive($method)->once()->andThrow($exception);
        $this->app->instance(TicketRepository::class, $repository);
    }

    #[Test]
    public function downloadRejectsAlreadyDownloadedTicket(): void
    {
        $this->ticket->update(['is_downloaded' => true]);

        $this->withHeaders($this->customerHeaders($this->customer))
            ->patchJson("/api/v1/customer/tickets/{$this->ticket->uuid}/download")
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'message' => 'Ticket has already been downloaded']);
    }

    #[Test]
    public function downloadReturns500AndLogsWhenMarkingFails(): void
    {
        config(['app.debug' => true]);
        Log::spy();
        $this->failingRepository('downloadTicket', new \RuntimeException('disk full'));

        $this->withHeaders($this->customerHeaders($this->customer))
            ->patchJson("/api/v1/customer/tickets/{$this->ticket->uuid}/download")
            ->assertStatus(500)
            ->assertExactJson([
                'success' => false,
                'message' => 'Failed to mark ticket as downloaded.',
                'error' => 'disk full',
            ]);

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context) => $message === 'Ticket download failed: disk full'
                && $context['uuid'] === $this->ticket->uuid)
            ->once();
        $this->assertFalse((bool) $this->ticket->fresh()->is_downloaded);
    }

    #[Test]
    public function useTicketReturns403WhenTicketAlreadyUsed(): void
    {
        $this->ticket->update(['used_at' => now()]);

        $this->withHeaders($this->customerHeaders($this->customer))
            ->patchJson("/api/v1/customer/tickets/{$this->ticket->uuid}/use")
            ->assertStatus(403)
            ->assertExactJson(['success' => false, 'message' => 'Ticket has already been used.']);

        $this->assertSame(GeneralConstants::TICKET_STATUSES['ACTIVE'], $this->ticket->fresh()->status);
    }

    #[Test]
    public function useTicketReturns500AndLogsOnUnexpectedFailure(): void
    {
        config(['app.debug' => false]);
        Log::spy();
        $this->failingRepository('markAsUsedForCustomer', new \RuntimeException('database offline'));

        $this->withHeaders($this->customerHeaders($this->customer))
            ->patchJson("/api/v1/customer/tickets/{$this->ticket->uuid}/use")
            ->assertStatus(500)
            ->assertExactJson([
                'success' => false,
                'message' => 'database offline',
                'error' => null,
            ]);

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($message, $context) => $message === 'Ticket use failed: database offline'
                && $context['uuid'] === $this->ticket->uuid)
            ->once();
        $this->assertNull($this->ticket->fresh()->used_at);
    }

    #[Test]
    public function downloadAndUseReturn404ForAnotherCustomersTicket(): void
    {
        $headers = $this->customerHeaders($this->createCustomer());
        $notAccessible = ['success' => false, 'message' => 'Ticket not found or not accessible'];

        $this->withHeaders($headers)
            ->patchJson("/api/v1/customer/tickets/{$this->ticket->uuid}/download")
            ->assertNotFound()
            ->assertExactJson($notAccessible);
        $this->withHeaders($headers)
            ->patchJson("/api/v1/customer/tickets/{$this->ticket->uuid}/use")
            ->assertNotFound()
            ->assertExactJson($notAccessible);

        $ticket = $this->ticket->fresh();
        $this->assertFalse((bool) $ticket->is_downloaded);
        $this->assertNull($ticket->used_at);
    }
}
