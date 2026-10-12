<?php

namespace Tests\Feature\Coverage\MiscControllers;

use App\Exceptions\NoResourceFoundException;
use App\Exceptions\NoTransactionFoundException;
use App\Http\Repositories\TransactionRepository;
use App\Http\Repositories\VenueRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class VenueTransactionControllersCoverageTest extends TestCase
{
    use MiscFixtures;
    use RefreshDatabase;

    private const MISSING_UUID = '00000000-0000-0000-0000-000000000000';

    #[Test]
    public function venueUpdateReturns404WhenVenueDisappearsAfterValidation(): void
    {
        $venue = $this->createVenue();

        $this->mock(VenueRepository::class, function ($mock) {
            $mock->shouldReceive('fetchOrThrow')->once()->andThrow(new NoResourceFoundException());
            $mock->shouldNotReceive('update');
        });

        $this->withHeaders($this->adminHeaders())
            ->putJson("/api/v1/venues/{$venue->uuid}", ['name' => 'Renamed Venue'])
            ->assertNotFound()
            ->assertJsonPath('message', 'No venue found');
    }

    #[Test]
    public function venueDestroyReturns404ForUnknownVenue(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->deleteJson('/api/v1/venues/' . self::MISSING_UUID)
            ->assertNotFound()
            ->assertJsonPath('message', 'No venue found');
    }

    #[Test]
    public function venueDestroyIsRejectedWhileEventsUseTheVenue(): void
    {
        $venue = $this->createVenue();
        $this->createEvent(['venue_uuid' => $venue->uuid]);

        $this->withHeaders($this->adminHeaders())
            ->deleteJson("/api/v1/venues/{$venue->uuid}")
            ->assertUnauthorized()
            ->assertJsonPath('message', 'Unauthorized access');

        $this->assertDatabaseHas('venues', ['uuid' => $venue->uuid, 'deleted_at' => null]);
    }

    #[Test]
    public function transactionUpdateChangesStatusFields(): void
    {
        $transaction = $this->createTransaction($this->createCustomer(), $this->createEvent());

        $this->withHeaders($this->adminHeaders())
            ->putJson("/api/v1/transactions/{$transaction->uuid}", [
                'status' => 'refunded',
                'order_status' => 'cancelled',
            ])
            ->assertOk()
            ->assertJsonPath('data.uuid', $transaction->uuid);

        $this->assertDatabaseHas('transactions', [
            'uuid' => $transaction->uuid,
            'status' => 'refunded',
            'order_status' => 'cancelled',
        ]);
    }

    #[Test]
    public function transactionUpdateReturns404WhenTransactionDisappearsAfterValidation(): void
    {
        $transaction = $this->createTransaction($this->createCustomer(), $this->createEvent());

        $this->mock(TransactionRepository::class, function ($mock) {
            $mock->shouldReceive('fetchOrThrow')->once()->andThrow(new NoTransactionFoundException());
            $mock->shouldNotReceive('update');
        });

        $this->withHeaders($this->adminHeaders())
            ->putJson("/api/v1/transactions/{$transaction->uuid}", ['status' => 'refunded'])
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Transaction not found']);
    }

    #[Test]
    public function transactionDestroySoftDeletesTransactionWithoutTickets(): void
    {
        $transaction = $this->createTransaction($this->createCustomer(), $this->createEvent());

        $this->withHeaders($this->adminHeaders())
            ->deleteJson("/api/v1/transactions/{$transaction->uuid}")
            ->assertNoContent();

        $this->assertSoftDeleted('transactions', ['uuid' => $transaction->uuid]);
    }

    #[Test]
    public function transactionDestroyReturns404ForUnknownTransaction(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->deleteJson('/api/v1/transactions/' . self::MISSING_UUID)
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Transaction not found']);
    }

    #[Test]
    public function transactionDestroyReturns403WhenTicketsExist(): void
    {
        $transaction = $this->createTransaction($this->createCustomer(), $this->createEvent());
        $this->createTicket($transaction);

        $this->withHeaders($this->adminHeaders())
            ->deleteJson("/api/v1/transactions/{$transaction->uuid}")
            ->assertForbidden()
            ->assertJson(['success' => false, 'message' => 'Cannot delete transaction with associated tickets.']);

        $this->assertDatabaseHas('transactions', ['uuid' => $transaction->uuid, 'deleted_at' => null]);
    }
}
