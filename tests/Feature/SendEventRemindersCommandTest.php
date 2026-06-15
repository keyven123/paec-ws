<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\Event;
use App\Models\EventReminderLog;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\EventReminderNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Feature tests for `app:send-event-reminders`.
 *
 * Notification::fake() is used so the toMail() method is never invoked, isolating the
 * command's routing and dedup logic from PDF generation, HTTP image fetches, and the queue.
 */
class SendEventRemindersCommandTest extends TestCase
{
    use RefreshDatabase;

    /** Frozen "now" for the entire test suite (Asia/Manila). */
    private const FROZEN_NOW = '2026-06-20 12:00:00';

    private Role $role;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::FROZEN_NOW, config('app.timezone')));
        Notification::fake();

        $this->role = Role::create([
            'name' => 'Customer',
            'code' => 'customer-reminder-test-' . Str::uuid()->toString(),
            'is_admin' => false,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // -------------------------------------------------------------------
    // Each reminder type fires exactly once when its window is hit.
    // -------------------------------------------------------------------

    #[Test]
    public function it_sends_7_day_reminder_when_event_is_seven_days_away(): void
    {
        $tx = $this->makeTransactionForEventStart(now()->copy()->addDays(7));

        $this->artisan('app:send-event-reminders')->assertSuccessful();

        Notification::assertSentTo(
            $tx->user,
            EventReminderNotification::class,
            fn ($n) => $n->transactionUuid === $tx->uuid
                && $n->reminderType === EventReminderLog::TYPE_7_DAYS,
        );

        $this->assertDatabaseHas('event_reminder_logs', [
            'transaction_uuid' => $tx->uuid,
            'reminder_type' => EventReminderLog::TYPE_7_DAYS,
        ]);
    }

    #[Test]
    public function it_sends_48_hour_reminder_when_event_is_two_days_away(): void
    {
        $tx = $this->makeTransactionForEventStart(now()->copy()->addHours(47));

        $this->artisan('app:send-event-reminders')->assertSuccessful();

        Notification::assertSentTo(
            $tx->user,
            EventReminderNotification::class,
            fn ($n) => $n->reminderType === EventReminderLog::TYPE_48_HOURS,
        );

        $this->assertDatabaseHas('event_reminder_logs', [
            'transaction_uuid' => $tx->uuid,
            'reminder_type' => EventReminderLog::TYPE_48_HOURS,
        ]);
    }

    #[Test]
    public function it_sends_only_one_7_day_reminder_per_user_event_and_schedule_time(): void
    {
        $firstTx = $this->makeTransactionForEventStart(now()->copy()->addDays(7));
        $secondTx = $this->makeAdditionalTransactionForSameOccurrence($firstTx);
        $thirdTx = $this->makeAdditionalTransactionForSameOccurrence($firstTx);

        $this->artisan('app:send-event-reminders')->assertSuccessful();

        Notification::assertSentToTimes($firstTx->user, EventReminderNotification::class, 1);
        Notification::assertSentTo(
            $firstTx->user,
            EventReminderNotification::class,
            fn ($n) => $n->reminderType === EventReminderLog::TYPE_7_DAYS
                && $n->transactionUuids === [$firstTx->uuid, $secondTx->uuid, $thirdTx->uuid],
        );

        $this->assertDatabaseCount('event_reminder_logs', 1);
        $this->assertDatabaseHas('event_reminder_logs', [
            'user_uuid' => $firstTx->user_uuid,
            'event_uuid' => $firstTx->event_uuid,
            'schedule_uuid' => $firstTx->schedule_uuid,
            'schedule_time_uuid' => $firstTx->schedule_time_uuid,
            'reminder_type' => EventReminderLog::TYPE_7_DAYS,
        ]);
    }

    #[Test]
    public function it_sends_only_one_48_hour_reminder_per_user_event_and_schedule_time(): void
    {
        $firstTx = $this->makeTransactionForEventStart(now()->copy()->addHours(47));
        $secondTx = $this->makeAdditionalTransactionForSameOccurrence($firstTx);

        $this->artisan('app:send-event-reminders')->assertSuccessful();

        Notification::assertSentToTimes($firstTx->user, EventReminderNotification::class, 1);
        Notification::assertSentTo(
            $firstTx->user,
            EventReminderNotification::class,
            fn ($n) => $n->reminderType === EventReminderLog::TYPE_48_HOURS
                && $n->transactionUuids === [$firstTx->uuid, $secondTx->uuid],
        );

        $this->assertDatabaseCount('event_reminder_logs', 1);
    }

    #[Test]
    public function it_sends_only_one_12_hour_reminder_per_user_event_and_schedule_time(): void
    {
        $firstTx = $this->makeTransactionForEventStart(now()->copy()->addHours(11));
        $secondTx = $this->makeAdditionalTransactionForSameOccurrence($firstTx);

        $this->artisan('app:send-event-reminders')->assertSuccessful();

        Notification::assertSentToTimes($firstTx->user, EventReminderNotification::class, 1);
        Notification::assertSentTo(
            $firstTx->user,
            EventReminderNotification::class,
            fn ($n) => $n->reminderType === EventReminderLog::TYPE_12_HOURS
                && $n->transactionUuids === [$firstTx->uuid, $secondTx->uuid],
        );

        $this->assertDatabaseCount('event_reminder_logs', 1);
    }

    #[Test]
    public function it_sends_12_hour_reminder_when_event_is_twelve_hours_away(): void
    {
        $tx = $this->makeTransactionForEventStart(now()->copy()->addHours(11));

        $this->artisan('app:send-event-reminders')->assertSuccessful();

        Notification::assertSentTo(
            $tx->user,
            EventReminderNotification::class,
            fn ($n) => $n->reminderType === EventReminderLog::TYPE_12_HOURS,
        );

        $this->assertDatabaseHas('event_reminder_logs', [
            'transaction_uuid' => $tx->uuid,
            'reminder_type' => EventReminderLog::TYPE_12_HOURS,
        ]);
    }

    // -------------------------------------------------------------------
    // Outside any window — nothing is sent.
    // -------------------------------------------------------------------

    #[Test]
    public function it_does_not_send_anything_when_event_is_in_the_dead_zone_between_48h_and_7d(): void
    {
        // 5 days from now → outside both 48h (24-48h) and 7d (6-7d) windows.
        $tx = $this->makeTransactionForEventStart(now()->copy()->addDays(5));

        $this->artisan('app:send-event-reminders')->assertSuccessful();

        Notification::assertNothingSentTo($tx->user);
        $this->assertDatabaseCount('event_reminder_logs', 0);
    }

    #[Test]
    public function it_does_not_send_anything_when_event_is_in_the_dead_zone_between_12h_and_48h(): void
    {
        // 18 hours from now → outside both 12h (0-12h) and 48h (24-48h) windows.
        $tx = $this->makeTransactionForEventStart(now()->copy()->addHours(18));

        $this->artisan('app:send-event-reminders')->assertSuccessful();

        Notification::assertNothingSentTo($tx->user);
        $this->assertDatabaseCount('event_reminder_logs', 0);
    }

    #[Test]
    public function it_does_not_send_anything_when_event_is_more_than_seven_days_away(): void
    {
        $tx = $this->makeTransactionForEventStart(now()->copy()->addDays(10));

        $this->artisan('app:send-event-reminders')->assertSuccessful();

        Notification::assertNothingSentTo($tx->user);
        $this->assertDatabaseCount('event_reminder_logs', 0);
    }

    #[Test]
    public function it_does_not_send_anything_when_event_has_already_started(): void
    {
        $tx = $this->makeTransactionForEventStart(now()->copy()->subHour());

        $this->artisan('app:send-event-reminders')->assertSuccessful();

        Notification::assertNothingSentTo($tx->user);
        $this->assertDatabaseCount('event_reminder_logs', 0);
    }

    // -------------------------------------------------------------------
    // Filters: payment status, event status, missing email.
    // -------------------------------------------------------------------

    #[Test]
    public function it_skips_unpaid_transactions(): void
    {
        $tx = $this->makeTransactionForEventStart(
            now()->copy()->addHours(11),
            ['payment_status' => Transaction::PAYMENT_STATUS['PENDING']],
        );

        $this->artisan('app:send-event-reminders')->assertSuccessful();

        Notification::assertNothingSentTo($tx->user);
        $this->assertDatabaseCount('event_reminder_logs', 0);
    }

    #[Test]
    public function it_skips_cancelled_events(): void
    {
        $tx = $this->makeTransactionForEventStart(now()->copy()->addHours(11));
        $tx->event->update(['cancelled_at' => now()->subHour()]);

        $this->artisan('app:send-event-reminders')->assertSuccessful();

        Notification::assertNothingSentTo($tx->user);
        $this->assertDatabaseCount('event_reminder_logs', 0);
    }

    #[Test]
    public function it_skips_completed_events(): void
    {
        $tx = $this->makeTransactionForEventStart(now()->copy()->addHours(11));
        $tx->event->update(['completed_at' => now()->subHour()]);

        $this->artisan('app:send-event-reminders')->assertSuccessful();

        Notification::assertNothingSentTo($tx->user);
        $this->assertDatabaseCount('event_reminder_logs', 0);
    }

    #[Test]
    public function it_skips_users_without_an_email_but_does_not_create_a_log_row(): void
    {
        $tx = $this->makeTransactionForEventStart(now()->copy()->addHours(11));
        // The DB column is NOT NULL, so blank it out instead of nulling it.
        $tx->user->forceFill(['email' => ''])->save();

        $this->artisan('app:send-event-reminders')->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertDatabaseCount('event_reminder_logs', 0);
    }

    // -------------------------------------------------------------------
    // Idempotency.
    // -------------------------------------------------------------------

    #[Test]
    public function it_does_not_resend_a_reminder_that_was_already_sent(): void
    {
        $tx = $this->makeTransactionForEventStart(now()->copy()->addHours(11));

        $this->artisan('app:send-event-reminders')->assertSuccessful();
        $this->artisan('app:send-event-reminders')->assertSuccessful();
        $this->artisan('app:send-event-reminders')->assertSuccessful();

        Notification::assertSentToTimes($tx->user, EventReminderNotification::class, 1);
        $this->assertSame(
            1,
            EventReminderLog::query()
                ->where('transaction_uuid', $tx->uuid)
                ->where('reminder_type', EventReminderLog::TYPE_12_HOURS)
                ->count(),
        );
    }

    #[Test]
    public function it_can_send_each_reminder_type_for_the_same_transaction_at_different_points_in_time(): void
    {
        $eventStart = now()->copy()->addDays(7);
        $tx = $this->makeTransactionForEventStart($eventStart);

        // T-7d: 7-day reminder fires.
        $this->artisan('app:send-event-reminders')->assertSuccessful();

        // Jump forward to T-47h.
        Carbon::setTestNow($eventStart->copy()->subHours(47));
        $this->artisan('app:send-event-reminders')->assertSuccessful();

        // Jump forward to T-11h.
        Carbon::setTestNow($eventStart->copy()->subHours(11));
        $this->artisan('app:send-event-reminders')->assertSuccessful();

        Notification::assertSentToTimes($tx->user, EventReminderNotification::class, 3);

        foreach (EventReminderLog::TYPES as $type) {
            $this->assertDatabaseHas('event_reminder_logs', [
                'transaction_uuid' => $tx->uuid,
                'reminder_type' => $type,
            ]);
        }
    }

    // -------------------------------------------------------------------
    // CLI options: --dry-run and --type.
    // -------------------------------------------------------------------

    #[Test]
    public function dry_run_does_not_dispatch_or_log_anything(): void
    {
        $tx = $this->makeTransactionForEventStart(now()->copy()->addHours(11));

        $this->artisan('app:send-event-reminders', ['--dry-run' => true])
            ->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertDatabaseCount('event_reminder_logs', 0);

        // After a real run, the same transaction is processed normally.
        $this->artisan('app:send-event-reminders')->assertSuccessful();
        Notification::assertSentTo($tx->user, EventReminderNotification::class);
        $this->assertDatabaseCount('event_reminder_logs', 1);
    }

    #[Test]
    public function type_filter_only_processes_the_requested_reminder_type(): void
    {
        $sevenDayTx = $this->makeTransactionForEventStart(now()->copy()->addDays(7));
        $twelveHourTx = $this->makeTransactionForEventStart(now()->copy()->addHours(11));

        $this->artisan('app:send-event-reminders', ['--type' => EventReminderLog::TYPE_12_HOURS])
            ->assertSuccessful();

        Notification::assertNothingSentTo($sevenDayTx->user);
        Notification::assertSentTo(
            $twelveHourTx->user,
            EventReminderNotification::class,
            fn ($n) => $n->reminderType === EventReminderLog::TYPE_12_HOURS,
        );

        $this->assertDatabaseMissing('event_reminder_logs', [
            'transaction_uuid' => $sevenDayTx->uuid,
        ]);
        $this->assertDatabaseHas('event_reminder_logs', [
            'transaction_uuid' => $twelveHourTx->uuid,
            'reminder_type' => EventReminderLog::TYPE_12_HOURS,
        ]);
    }

    #[Test]
    public function type_filter_rejects_unknown_values(): void
    {
        $this->artisan('app:send-event-reminders', ['--type' => 'never'])
            ->assertFailed();

        Notification::assertNothingSent();
    }

    // -------------------------------------------------------------------
    // Test helpers.
    // -------------------------------------------------------------------

    /**
     * Create a paid transaction whose schedule + scheduleTime resolve to the given event-start datetime.
     *
     * @param  array<string, mixed>  $transactionOverrides
     */
    private function makeTransactionForEventStart(Carbon $eventStart, array $transactionOverrides = []): Transaction
    {
        $user = User::factory()->create(['role_uuid' => $this->role->uuid]);

        $event = Event::create([
            'event_name' => 'Reminder Test Event ' . Str::uuid()->toString(),
            'event_description' => 'Reminder test',
            'contact_email' => 'event@example.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['SEAT_SELECTION'],
            'tags' => [],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
        ]);

        $schedule = Schedule::create([
            'event_uuid' => $event->uuid,
            'date_from' => $eventStart->toDateString(),
            'date_to' => $eventStart->toDateString(),
            'status' => Schedule::PUBLISHED_STATUS,
        ]);

        $scheduleTime = ScheduleTime::create([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => $eventStart->format('H:i:s'),
            'time_end' => $eventStart->copy()->addHours(2)->format('H:i:s'),
            'status' => ScheduleTime::PUBLISHED_STATUS,
        ]);

        return Transaction::create(array_merge([
            'user_uuid' => $user->uuid,
            'event_uuid' => $event->uuid,
            'schedule_uuid' => $schedule->uuid,
            'schedule_time_uuid' => $scheduleTime->uuid,
            'order_number' => 'ORD-' . Str::uuid()->toString(),
            'sub_total' => 100,
            'tax_amount' => 0,
            'discount' => 0,
            'total_amount' => 100,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
            'paid_at' => now()->subHour(),
        ], $transactionOverrides))->fresh(['user', 'event']);
    }

    private function makeAdditionalTransactionForSameOccurrence(Transaction $base, array $transactionOverrides = []): Transaction
    {
        return Transaction::create(array_merge([
            'user_uuid' => $base->user_uuid,
            'event_uuid' => $base->event_uuid,
            'schedule_uuid' => $base->schedule_uuid,
            'schedule_time_uuid' => $base->schedule_time_uuid,
            'order_number' => 'ORD-' . Str::uuid()->toString(),
            'sub_total' => 100,
            'tax_amount' => 0,
            'discount' => 0,
            'total_amount' => 100,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
            'paid_at' => now()->subHour(),
        ], $transactionOverrides))->fresh(['user', 'event']);
    }
}
