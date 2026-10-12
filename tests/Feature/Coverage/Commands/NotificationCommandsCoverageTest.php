<?php

namespace Tests\Feature\Coverage\Commands;

use App\Console\Commands\SendEventReminders;
use App\Models\EventReminderLog;
use App\Models\TempTransaction;
use App\Models\Transaction;
use App\Notifications\EventReminderNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class NotificationCommandsCoverageTest extends TestCase
{
    use CommandFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-20 12:00:00', config('app.timezone')));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function eventRemindersSkipOccurrencesWithAnUnparsableStartTime(): void
    {
        Notification::fake();
        $occurrence = $this->makeEventOccurrence('2026-06-27', 'not-a-time');
        $this->makeTransaction($this->makeCustomer(), $occurrence);

        $this->artisan('app:send-event-reminders')
            ->expectsOutputToContain('Done. Sent: 0, Skipped: 1, Errored: 0.')
            ->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertDatabaseCount('event_reminder_logs', 0);
    }

    #[Test]
    public function eventRemindersCountAGroupAsErroredWhenSendingThrows(): void
    {
        $occurrence = $this->makeEventOccurrence('2026-06-27', '12:00:00');
        $transaction = $this->makeTransaction($this->makeCustomer(), $occurrence);
        $groupKey = implode('|', [
            $transaction->user_uuid,
            $transaction->event_uuid,
            $transaction->schedule_uuid,
            $transaction->schedule_time_uuid,
        ]);

        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('Mailer down'));
        Log::spy();

        $this->artisan('app:send-event-reminders')
            ->expectsOutputToContain("Error on reminder group {$groupKey}: Mailer down")
            ->expectsOutputToContain('Done. Sent: 0, Skipped: 0, Errored: 1.')
            ->assertSuccessful();

        Log::shouldHaveReceived('error')->once()->with('SendEventReminders: failed to process reminder group', [
            'transaction_uuid' => $transaction->uuid,
            'group_key' => $groupKey,
            'error' => 'Mailer down',
        ]);
        $this->assertDatabaseHas('event_reminder_logs', [
            'group_key' => $groupKey,
            'reminder_type' => EventReminderLog::TYPE_7_DAYS,
        ]);
    }

    #[Test]
    public function eventStartIsUnknownWithoutAScheduleDate(): void
    {
        $resolve = new ReflectionMethod(SendEventReminders::class, 'resolveEventStart');
        $command = new SendEventReminders();

        $withoutSchedule = (new Transaction())->setRelation('schedule', null);
        $this->assertNull($resolve->invoke($command, $withoutSchedule, 'Asia/Manila'));

        $occurrence = $this->makeEventOccurrence('2026-06-27');
        $occurrence['schedule']->date_from = null;
        $withoutDate = (new Transaction())->setRelation('schedule', $occurrence['schedule']);
        $this->assertNull($resolve->invoke($command, $withoutDate, 'Asia/Manila'));
    }

    #[Test]
    public function marketingFollowupSkipsInvalidEmailsAndListsRecipientsOnDryRun(): void
    {
        Notification::fake();
        $occurrence = $this->makeEventOccurrence('2026-06-27');
        $valid = $this->makeHold($this->makeCustomer(['email' => 'buyer@example.com'])->uuid, $occurrence);
        $invalid = $this->makeHold($this->makeCustomer(['email' => 'not-an-email'])->uuid, $occurrence);
        $deletedCustomer = $this->makeCustomer();
        $orphaned = $this->makeHold($deletedCustomer->uuid, $occurrence);
        $deletedCustomer->delete();

        $this->artisan('app:send-temp-transaction-marketing-followup', ['--dry-run' => true])
            ->expectsOutputToContain('[dry-run]')
            ->expectsOutputToContain("Would send to buyer@example.com for temp transaction {$valid->uuid}")
            ->expectsOutputToContain("Skipping {$invalid->uuid}: user missing or invalid email.")
            ->expectsOutputToContain("Skipping {$orphaned->uuid}: user missing or invalid email.")
            ->expectsOutputToContain('Done. Sent: 1, skipped: 2, candidates: 3.')
            ->assertSuccessful();

        Notification::assertNothingSent();
        $this->assertNull($valid->fresh()->marketing_followup_sent_at);
    }

    #[Test]
    public function marketingFollowupLogsAndSkipsWhenSendingFails(): void
    {
        $occurrence = $this->makeEventOccurrence('2026-06-27');
        $customer = $this->makeCustomer(['email' => 'buyer@example.com']);
        $hold = $this->makeHold($customer->uuid, $occurrence);

        Notification::shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP refused'));
        Log::spy();

        $this->artisan('app:send-temp-transaction-marketing-followup')
            ->expectsOutputToContain("Failed for {$hold->uuid}: SMTP refused")
            ->expectsOutputToContain('Done. Sent: 0, skipped: 1, candidates: 1.')
            ->assertSuccessful();

        Log::shouldHaveReceived('error')->once()->with('Temp transaction marketing follow-up failed', [
            'temp_transaction_uuid' => $hold->uuid,
            'user_uuid' => $customer->uuid,
            'error' => 'SMTP refused',
        ]);
        $this->assertNull($hold->fresh()->marketing_followup_sent_at);
    }

    private function makeHold(string $userUuid, array $occurrence): TempTransaction
    {
        $hold = TempTransaction::create([
            'user_uuid' => $userUuid,
            'event_uuid' => $occurrence['event']->uuid,
            'total_amount' => 100.00,
            'sub_total' => 100.00,
            'tax_amount' => 0.00,
            'discount' => 0.00,
        ]);

        DB::table('temp_transactions')->where('uuid', $hold->uuid)->update([
            'created_at' => now()->subMinutes(42),
            'updated_at' => now()->subMinutes(42),
        ]);

        return $hold->fresh();
    }
}
