<?php

namespace Tests\Feature\Coverage\RModelsCore;

use App\Constants\GeneralConstants;
use App\Models\Schedule;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\TransactionOrder;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TicketModelTest extends TestCase
{
    use RefreshDatabase;
    use RModelsCoreFixtures;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'Asia/Manila'));
        $this->customer = $this->makeCustomer();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        auth('admin')->logout();

        parent::tearDown();
    }

    private function ticketFor(string $date, array $transactionAttributes = [], array $ticketAttributes = []): Ticket
    {
        $occurrence = $this->makeEventOccurrence($date);
        $transaction = $this->makeTransaction($this->customer, $occurrence, $transactionAttributes);

        return $this->makeTicket($transaction, $occurrence, $ticketAttributes);
    }

    private function addOrder(Ticket $ticket, ?string $validUntil): TransactionOrder
    {
        return TransactionOrder::create([
            'user_uuid' => $ticket->user_uuid,
            'transaction_uuid' => $ticket->transaction_uuid,
            'event_ticket_uuid' => $ticket->event_ticket_uuid,
            'quantity' => 1,
            'price' => 100,
            'total_amount' => 100,
            'valid_until' => $validUntil,
        ]);
    }

    /**
     * Builds one ticket per past-due rule; keys describe why each one is upcoming or past.
     *
     * @return array<string, Ticket>
     */
    private function buildTabScenario(): array
    {
        $tickets = [];
        $tickets['visitTomorrow'] = $this->ticketFor('2026-06-20', [], ['valid_until' => '2026-06-16 00:00:00']);
        $tickets['visitYesterday'] = $this->ticketFor('2026-06-20', [], ['valid_until' => '2026-06-14 00:00:00']);

        $tickets['orderFuture'] = $this->ticketFor('2026-06-01');
        $this->addOrder($tickets['orderFuture'], '2026-06-20 00:00:00');
        $tickets['orderPast'] = $this->ticketFor('2026-06-20');
        $this->addOrder($tickets['orderPast'], '2026-06-10 00:00:00');

        $tickets['scheduleFuture'] = $this->ticketFor('2026-06-20');
        $tickets['scheduleDatePast'] = $this->ticketFor('2026-06-10', ['schedule_time_uuid' => null]);

        $tickets['scheduleTimePast'] = $this->ticketFor('2026-06-15');
        $transaction = $tickets['scheduleTimePast']->transaction;
        DB::table('schedules')->where('uuid', $transaction->schedule_uuid)
            ->update(['date_from' => '2026-06-15', 'date_to' => '2026-06-15']);
        DB::table('schedule_times')->where('uuid', $transaction->schedule_time_uuid)
            ->update(['time_end' => '10:00:00']);

        $tickets['noSchedule'] = $this->ticketFor('2026-06-01', ['schedule_uuid' => null, 'schedule_time_uuid' => null]);
        $tickets['used'] = $this->ticketFor('2026-06-20', [], ['status' => GeneralConstants::TICKET_STATUSES['USED']]);
        $tickets['transferred'] = $this->ticketFor('2026-06-20', [], ['status' => GeneralConstants::TICKET_STATUSES['TRANSFERRED']]);

        return $tickets;
    }

    #[Test]
    public function upcomingTabKeepsOnlyActiveTicketsWhoseVisitOrScheduleIsNotOver(): void
    {
        $t = $this->buildTabScenario();

        $result = Ticket::filters(['tab' => 'upcoming'])->get();

        $this->assertSameModels(
            [$t['visitTomorrow'], $t['orderFuture'], $t['scheduleFuture'], $t['noSchedule']],
            $result,
        );
    }

    #[Test]
    public function pastTabReturnsUsedTicketsAndActiveTicketsWhoseScheduleIsOver(): void
    {
        $t = $this->buildTabScenario();

        $result = Ticket::filters(['tab' => 'past'])->get();

        $this->assertSameModels(
            [$t['visitYesterday'], $t['orderPast'], $t['scheduleDatePast'], $t['scheduleTimePast'], $t['used']],
            $result,
        );
    }

    #[Test]
    public function transferredTabAndUnknownTabFilterAsExpected(): void
    {
        $t = $this->buildTabScenario();

        $this->assertSameModels([$t['transferred']], Ticket::filters(['tab' => 'transferred'])->get());
        $this->assertCount(count($t), Ticket::filters(['tab' => 'anything-else'])->get());
    }

    #[Test]
    public function filtersByKeywordAcrossTicketAndEventColumns(): void
    {
        $byName = $this->ticketFor('2026-06-20', [], ['attendee_name' => 'Zelda Keyword']);
        $byEvent = $this->ticketFor('2026-06-20', [], ['attendee_name' => 'Other']);
        $byEvent->event->update(['event_name' => 'Zelda Concert']);
        $this->ticketFor('2026-06-20', [], ['attendee_name' => 'Nobody']);

        $this->assertSameModels([$byName, $byEvent], Ticket::filters(['q' => 'Zelda'])->get());
    }

    #[Test]
    public function filtersByOwnerTransactionTicketTypeAndStatus(): void
    {
        $target = $this->ticketFor('2026-06-20', [], ['status' => GeneralConstants::TICKET_STATUSES['PENDING']]);
        $otherUser = $this->makeCustomer();
        $otherOccurrence = $this->makeEventOccurrence('2026-06-21');
        $other = $this->makeTicket($this->makeTransaction($otherUser, $otherOccurrence), $otherOccurrence);

        $this->assertSameModels([$target], Ticket::filters(['user_uuid' => $this->customer->uuid])->get());
        $this->assertSameModels([$other], Ticket::filters(['transaction_uuid' => $other->transaction_uuid])->get());
        $this->assertSameModels([$target], Ticket::filters(['event_ticket_uuid' => $target->event_ticket_uuid])->get());
        $this->assertSameModels([$target], Ticket::filters(['status' => GeneralConstants::TICKET_STATUSES['PENDING']])->get());
    }

    #[Test]
    public function filtersByUsedFlag(): void
    {
        $used = $this->ticketFor('2026-06-20', [], ['used_at' => '2026-06-14 09:00:00']);
        $unused = $this->ticketFor('2026-06-20');

        $this->assertSameModels([$used], Ticket::filters(['is_used' => true])->get());
        $this->assertSameModels([$unused], Ticket::filters(['is_used' => false])->get());
    }

    #[Test]
    public function scheduleEndTimestampSqlUsesSqliteOrMysqlSyntaxByDriver(): void
    {
        $this->assertStringStartsWith('datetime(', Ticket::scheduleEndTimestampSql());

        $default = config('database.default');
        config(['database.connections.models_core_mysql' => ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'unused']]);
        DB::setDefaultConnection('models_core_mysql');

        try {
            $sql = Ticket::scheduleEndTimestampSql();
        } finally {
            DB::setDefaultConnection($default);
            DB::purge('models_core_mysql');
        }

        $this->assertSame('TIMESTAMP(COALESCE(schedules.date_to, schedules.date_from), schedule_times.time_end)', $sql);
    }

    #[Test]
    public function isSchedulePastDueLoadsTheScheduleAndComparesEndTime(): void
    {
        $t = $this->buildTabScenario();

        $this->assertFalse(Ticket::find($t['scheduleFuture']->uuid)->isSchedulePastDue());
        $this->assertTrue(Ticket::find($t['scheduleTimePast']->uuid)->isSchedulePastDue());
        $this->assertTrue(Ticket::find($t['scheduleDatePast']->uuid)->isSchedulePastDue());
        $this->assertFalse(Ticket::find($t['noSchedule']->uuid)->isSchedulePastDue());
    }

    #[Test]
    public function isSchedulePastDueUsesVisitDateWhenTicketHasOne(): void
    {
        $t = $this->buildTabScenario();

        $this->assertTrue(Ticket::find($t['orderPast']->uuid)->isSchedulePastDue());
        $this->assertFalse(Ticket::find($t['orderFuture']->uuid)->isSchedulePastDue());
        $this->assertTrue(Ticket::find($t['visitYesterday']->uuid)->isSchedulePastDue());
    }

    #[Test]
    public function isSchedulePastDueIsFalseWhenScheduleIsMissingOrHasNoDate(): void
    {
        $deletedSchedule = $this->ticketFor('2026-06-01');
        Schedule::whereKey($deletedSchedule->transaction->schedule_uuid)->delete();
        $this->assertFalse(Ticket::find($deletedSchedule->uuid)->isSchedulePastDue());

        $undated = Ticket::find($this->ticketFor('2026-06-01')->uuid);
        $undated->transaction->setRelation('schedule', new Schedule());
        $this->assertFalse($undated->isSchedulePastDue());
    }

    #[Test]
    public function ticketWithoutTransactionHasNoVisitDateAndIsNeverPastDue(): void
    {
        $ticket = new Ticket();

        $this->assertFalse($ticket->hasVisitDate());
        $this->assertNull($ticket->resolveDateOfVisit());
        $this->assertFalse($ticket->isVisitDatePastDue());
        $this->assertFalse($ticket->isSchedulePastDue());
    }

    #[Test]
    public function resolveDateOfVisitFallsBackFromOrderToScheduleDate(): void
    {
        $t = $this->buildTabScenario();

        $this->assertSame('2026-06-20', Ticket::find($t['orderFuture']->uuid)->resolveDateOfVisit()->toDateString());
        $this->assertSame('2026-06-20', Ticket::find($t['scheduleFuture']->uuid)->resolveDateOfVisit()->toDateString());
        $this->assertSame('2026-06-16', Ticket::find($t['visitTomorrow']->uuid)->resolveDateOfVisit()->toDateString());
        $this->assertNull(Ticket::find($t['noSchedule']->uuid)->resolveDateOfVisit());
    }

    #[Test]
    public function byOrganizationScopeIsUnfilteredForPlatformAdmins(): void
    {
        $organization = \App\Models\Organization::factory()->create();
        $own = $this->ticketFor('2026-06-20', [], ['organization_uuid' => $organization->uuid]);
        $foreign = $this->ticketFor('2026-06-20');

        auth('admin')->login($this->makeAdmin(true));
        $this->assertSameModels([$own, $foreign], Ticket::byOrganization()->get());

        auth('admin')->login($this->makeAdmin(false, $organization->uuid));
        $this->assertSameModels([$own], Ticket::byOrganization()->get());
    }

    #[Test]
    public function creatorAndCreatedByResolveTheCreatingUser(): void
    {
        $creator = $this->makeCustomer();
        $ticket = $this->ticketFor('2026-06-20');
        // TicketObserver overwrites created_by with the admin guard user on create.
        DB::table('tickets')->where('uuid', $ticket->uuid)->update(['created_by' => $creator->uuid]);
        $ticket = $ticket->fresh();

        foreach (['creator', 'createdBy'] as $relation) {
            $this->assertInstanceOf(BelongsTo::class, $ticket->{$relation}());
            $this->assertSame('created_by', $ticket->{$relation}()->getForeignKeyName());
            $this->assertTrue($ticket->{$relation}->is($creator));
        }
    }
}
