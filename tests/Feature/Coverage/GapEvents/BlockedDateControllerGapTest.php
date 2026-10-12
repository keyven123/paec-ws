<?php

namespace Tests\Feature\Coverage\GapEvents;

use App\Http\Controllers\BlockedDateController;
use App\Http\Repositories\BlockedDateRepository;
use App\Models\BlockedDate;
use App\Models\Event;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class BlockedDateControllerGapTest extends GapEventsTestCase
{
    private Event $event;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->token] = $this->createAdmin(['events' => ['view', 'update']]);
        $this->event = $this->createEvent('Blockable Event');
    }

    private function url(): string
    {
        return '/api/v1/events/' . $this->event->uuid . '/blocked-dates';
    }

    private function failCreateWith(\PDOException $previous): void
    {
        $repository = Mockery::mock(BlockedDateRepository::class, [new BlockedDate()])->makePartial();
        $repository->shouldReceive('create')
            ->once()
            ->andThrow(new QueryException('sqlite', 'insert into blocked_dates', [], $previous));
        $this->app->instance(BlockedDateRepository::class, $repository);
    }

    #[Test]
    public function rangesLongerThanTheMaximumAreRejected(): void
    {
        $this->withHeaders($this->bearer($this->token))
            ->postJson($this->url(), [
                'blocked_date' => '2031-01-01',
                'blocked_date_end' => '2032-01-02',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'You can block up to 366 days at a time.');

        $this->assertDatabaseCount('blocked_dates', 0);
    }

    #[Test]
    public function rangeSkipsDaysThatHavePaidTickets(): void
    {
        $buyer = $this->createCustomer('ticket-holder@test.com');
        $eventTicket = $this->createEventTicket($this->event, 'Priority', 100);
        $transaction = $this->createTransaction($this->event, $buyer, 100);
        $this->createTicket($transaction, $eventTicket, [
            'visit_policy' => 'priority',
            'valid_until' => '2031-03-02 23:59:59',
        ]);

        $this->withHeaders($this->bearer($this->token))
            ->postJson($this->url(), [
                'blocked_date' => '2031-03-01',
                'blocked_date_end' => '2031-03-03',
            ])
            ->assertStatus(201)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('skipped.0.date', '2031-03-02')
            ->assertJsonPath('skipped.0.reason', 'Has existing tickets');

        $this->assertDatabaseCount('blocked_dates', 2);
    }

    #[Test]
    public function duplicateKeyRaceIsReportedAsAlreadyBlocked(): void
    {
        $previous = new \PDOException('SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry');
        $previous->errorInfo = ['23000', 1062, 'Duplicate entry'];
        $this->failCreateWith($previous);

        $this->withHeaders($this->bearer($this->token))
            ->postJson($this->url(), ['blocked_date' => '2031-04-01'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This date is already blocked.');
    }

    #[Test]
    public function sqliteUniqueViolationIsReportedAsAlreadyBlocked(): void
    {
        $this->failCreateWith(new \PDOException('UNIQUE constraint failed: blocked_dates.blocked_date'));

        $this->withHeaders($this->bearer($this->token))
            ->postJson($this->url(), [
                'blocked_date' => '2031-04-01',
                'blocked_date_end' => '2031-04-01',
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'This date is already blocked.');
    }

    #[Test]
    public function otherQueryErrorsAreRethrown(): void
    {
        $this->failCreateWith(new \PDOException('no such table: blocked_dates'));
        $controller = app(BlockedDateController::class);
        $blockDate = new \ReflectionMethod($controller, 'blockDate');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('no such table: blocked_dates');

        $blockDate->invoke($controller, $this->event, '2031-04-02', null);
    }

    #[Test]
    public function otherQueryErrorsResultInAServerError(): void
    {
        $this->failCreateWith(new \PDOException('no such table: blocked_dates'));

        $this->withHeaders($this->bearer($this->token))
            ->postJson($this->url(), ['blocked_date' => '2031-04-02'])
            ->assertStatus(500);

        $this->assertDatabaseCount('blocked_dates', 0);
    }

    #[Test]
    public function unknownBlockableTypeAborts404(): void
    {
        $this->expectException(NotFoundHttpException::class);

        app(BlockedDateController::class)->destroy(Request::create('/'), $this->event->uuid, 'missing');
    }
}
