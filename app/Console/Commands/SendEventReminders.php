<?php

namespace App\Console\Commands;

use App\Models\EventReminderLog;
use App\Models\Transaction;
use App\Notifications\EventReminderNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sends pre-event reminder emails to paying customers at three intervals:
 *
 *   - 7 days  before the event start  (gentle reminder)
 *   - 48 hours before the event start (reminder including ticket + voucher attachments)
 *   - 12 hours before the event start (gentle reminder)
 *
 * Each reminder is sent at most once per customer/event occurrence (deduped via event_reminder_logs).
 * Designed to run every hour; missed runs are tolerated by using catch-up windows.
 */
class SendEventReminders extends Command
{
    protected $signature = 'app:send-event-reminders
                            {--dry-run : List the reminders that would be sent without dispatching anything}
                            {--type= : Only consider reminders of a specific type (7d, 48h, 12h)}';

    protected $description = 'Send pre-event reminder emails 7 days, 48 hours, and 12 hours before the event';

    /**
     * Catch-up windows expressed in minutes-until-event (lower exclusive, upper inclusive).
     * The "lower" boundaries do not overlap so a transaction can only fall into one
     * reminder bucket per run; the upper bound matches the reminder name.
     */
    private const REMINDER_WINDOWS = [
        EventReminderLog::TYPE_7_DAYS => [
            'min_minutes_exclusive' => 6 * 24 * 60,   // > 6 days
            'max_minutes_inclusive' => 7 * 24 * 60,   // <= 7 days
            'sql_days_lower' => 6,
            'sql_days_upper' => 8,
        ],
        EventReminderLog::TYPE_48_HOURS => [
            'min_minutes_exclusive' => 24 * 60,        // > 24h
            'max_minutes_inclusive' => 48 * 60,        // <= 48h
            'sql_days_lower' => 0,
            'sql_days_upper' => 3,
        ],
        EventReminderLog::TYPE_12_HOURS => [
            'min_minutes_exclusive' => 0,              // > 0
            'max_minutes_inclusive' => 12 * 60,        // <= 12h
            'sql_days_lower' => 0,
            'sql_days_upper' => 2,
        ],
    ];

    public function handle(): int
    {
        $timezone = config('app.timezone', 'Asia/Manila');
        $now = Carbon::now($timezone);
        $isDryRun = (bool) $this->option('dry-run');

        $typeFilter = $this->option('type');
        if ($typeFilter !== null && ! in_array($typeFilter, EventReminderLog::TYPES, true)) {
            $this->error("Invalid --type. Allowed values: " . implode(', ', EventReminderLog::TYPES));

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Scanning event reminders at %s (%s)%s',
            $now->toDateTimeString(),
            $timezone,
            $isDryRun ? ' [dry-run]' : '',
        ));

        $totalSent = 0;
        $totalSkipped = 0;
        $totalErrored = 0;

        $candidates = $this->loadCandidateTransactions($now, $timezone, $typeFilter);

        $groups = $candidates->groupBy(fn (Transaction $transaction) => $this->groupKey($transaction));

        $this->line("Found {$candidates->count()} candidate transaction(s) across {$groups->count()} reminder group(s).");

        foreach ($groups as $groupKey => $transactions) {
            $transaction = $transactions->first();
            try {
                if ($transaction === null) {
                    $totalSkipped++;
                    continue;
                }

                $eventStart = $this->resolveEventStart($transaction, $timezone);
                if ($eventStart === null) {
                    $totalSkipped++;
                    continue;
                }

                $minutesUntil = $now->diffInMinutes($eventStart, false);
                if ($minutesUntil <= 0) {
                    $totalSkipped++;
                    continue;
                }

                $reminderType = $this->bucketFor($minutesUntil, $typeFilter);
                if ($reminderType === null) {
                    $totalSkipped++;
                    continue;
                }

                $alreadySent = EventReminderLog::query()
                    ->where('group_key', $groupKey)
                    ->where('reminder_type', $reminderType)
                    ->exists();

                if ($alreadySent) {
                    $totalSkipped++;
                    continue;
                }

                if ($transaction->user === null || empty($transaction->user->email)) {
                    Log::warning('Skipping reminder: missing user/email on transaction', [
                        'transaction_uuid' => $transaction->uuid,
                        'group_key' => $groupKey,
                    ]);
                    $totalSkipped++;
                    continue;
                }

                if ($isDryRun) {
                    $this->line(sprintf(
                        '[dry-run] Would send %s reminder for group %s with %d transaction(s) (event in %d min)',
                        $reminderType,
                        $groupKey,
                        $transactions->count(),
                        $minutesUntil,
                    ));
                    $totalSent++;
                    continue;
                }

                EventReminderLog::query()->create([
                    'transaction_uuid' => $transaction->uuid,
                    'user_uuid' => $transaction->user_uuid,
                    'event_uuid' => $transaction->event_uuid,
                    'schedule_uuid' => $transaction->schedule_uuid,
                    'schedule_time_uuid' => $transaction->schedule_time_uuid,
                    'group_key' => $groupKey,
                    'reminder_type' => $reminderType,
                    'sent_at' => now(),
                ]);

                $transaction->user->notify(
                    new EventReminderNotification($transactions->pluck('uuid')->all(), $reminderType),
                );

                Log::info('Event reminder dispatched', [
                    'transaction_uuid' => $transaction->uuid,
                    'transaction_uuids' => $transactions->pluck('uuid')->all(),
                    'group_key' => $groupKey,
                    'reminder_type' => $reminderType,
                    'minutes_until_event' => $minutesUntil,
                ]);

                $totalSent++;
            } catch (\Throwable $e) {
                $totalErrored++;
                Log::error('SendEventReminders: failed to process reminder group', [
                    'transaction_uuid' => $transaction->uuid ?? null,
                    'group_key' => $groupKey,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("Error on reminder group {$groupKey}: {$e->getMessage()}");
            }
        }

        $this->info(sprintf(
            'Done. Sent: %d, Skipped: %d, Errored: %d.',
            $totalSent,
            $totalSkipped,
            $totalErrored,
        ));

        return self::SUCCESS;
    }

    /**
     * Coarsely-filtered candidate transactions whose schedule date overlaps any active reminder window.
     */
    private function loadCandidateTransactions(Carbon $now, string $timezone, ?string $typeFilter)
    {
        $types = $typeFilter !== null ? [$typeFilter] : EventReminderLog::TYPES;

        $minDays = null;
        $maxDays = null;
        foreach ($types as $type) {
            $window = self::REMINDER_WINDOWS[$type];
            $minDays = $minDays === null ? $window['sql_days_lower'] : min($minDays, $window['sql_days_lower']);
            $maxDays = $maxDays === null ? $window['sql_days_upper'] : max($maxDays, $window['sql_days_upper']);
        }

        $today = $now->copy()->startOfDay();
        $lower = $today->copy()->addDays($minDays)->toDateString();
        $upper = $today->copy()->addDays($maxDays)->toDateString();

        return Transaction::query()
            ->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->whereHas('schedule', function ($q) use ($lower, $upper) {
                $q->whereBetween('date_from', [$lower, $upper]);
            })
            ->whereHas('event', function ($q) {
                $q->whereNull('cancelled_at')->whereNull('completed_at');
            })
            ->with([
                'user',
                'schedule',
                'scheduleTime',
                'event',
            ])
            ->get();
    }

    private function groupKey(Transaction $transaction): string
    {
        return implode('|', [
            $transaction->user_uuid,
            $transaction->event_uuid,
            $transaction->schedule_uuid,
            $transaction->schedule_time_uuid ?: 'none',
        ]);
    }

    private function resolveEventStart(Transaction $transaction, string $timezone): ?Carbon
    {
        $schedule = $transaction->schedule;
        if ($schedule === null || $schedule->date_from === null) {
            return null;
        }

        $dateString = $schedule->date_from instanceof Carbon
            ? $schedule->date_from->format('Y-m-d')
            : Carbon::parse($schedule->date_from)->format('Y-m-d');

        $timeString = $transaction->scheduleTime?->time_start ?? '00:00:00';

        try {
            return Carbon::parse($dateString . ' ' . $timeString, $timezone);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Map a "minutes until event" value to its reminder bucket, if any.
     * Only buckets allowed by $typeFilter (or all, when null) are considered.
     */
    private function bucketFor(int $minutesUntil, ?string $typeFilter): ?string
    {
        $candidates = $typeFilter !== null ? [$typeFilter] : EventReminderLog::TYPES;

        foreach ($candidates as $type) {
            $window = self::REMINDER_WINDOWS[$type];
            if ($minutesUntil > $window['min_minutes_exclusive']
                && $minutesUntil <= $window['max_minutes_inclusive']) {
                return $type;
            }
        }

        return null;
    }
}
