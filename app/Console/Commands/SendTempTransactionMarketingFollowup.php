<?php

namespace App\Console\Commands;

use App\Models\TempTransaction;
use App\Models\User;
use App\Notifications\TempTransactionMarketingFollowupNotification;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sends a marketing follow-up email when a temp transaction is ~40 minutes old
 * and checkout has not been completed. Runs every 5 minutes; uses a 5-minute
 * window so each hold is emailed at most once.
 */
class SendTempTransactionMarketingFollowup extends Command
{
    protected $signature = 'app:send-temp-transaction-marketing-followup
                            {--dry-run : List emails that would be sent without dispatching}';

    protected $description = 'Email users with incomplete checkout temp transactions created ~40 minutes ago';

    private const FOLLOWUP_AGE_MINUTES = 40;

    /** Matches the scheduler interval (every 5 minutes). */
    private const WINDOW_MINUTES = 5;

    public function handle(): int
    {
        $timezone = config('app.timezone', 'Asia/Manila');
        $now = Carbon::now($timezone);
        $isDryRun = (bool) $this->option('dry-run');

        $windowStart = $now->copy()->subMinutes(self::FOLLOWUP_AGE_MINUTES + self::WINDOW_MINUTES);
        $windowEnd = $now->copy()->subMinutes(self::FOLLOWUP_AGE_MINUTES);

        $this->info(sprintf(
            'Scanning temp transactions created between %s and %s (%s)%s',
            $windowStart->toDateTimeString(),
            $windowEnd->toDateTimeString(),
            $timezone,
            $isDryRun ? ' [dry-run]' : '',
        ));

        $candidates = TempTransaction::query()
            ->with(['user', 'event'])
            ->whereNull('marketing_followup_sent_at')
            ->where('created_at', '>=', $windowStart)
            ->where('created_at', '<', $windowEnd)
            ->orderBy('created_at')
            ->get();

        $sent = 0;
        $skipped = 0;

        foreach ($candidates as $tempTransaction) {
            $user = $tempTransaction->user;

            if (! $user instanceof User || ! filter_var($user->email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                $this->warn("Skipping {$tempTransaction->uuid}: user missing or invalid email.");

                continue;
            }

            if ($isDryRun) {
                $this->line("Would send to {$user->email} for temp transaction {$tempTransaction->uuid}");
                $sent++;

                continue;
            }

            try {
                $user->notify(new TempTransactionMarketingFollowupNotification($tempTransaction));
                $tempTransaction->update(['marketing_followup_sent_at' => now()]);
                $sent++;
            } catch (\Throwable $e) {
                $skipped++;
                Log::error('Temp transaction marketing follow-up failed', [
                    'temp_transaction_uuid' => $tempTransaction->uuid,
                    'user_uuid' => $user->uuid,
                    'error' => $e->getMessage(),
                ]);
                $this->error("Failed for {$tempTransaction->uuid}: {$e->getMessage()}");
            }
        }

        $this->info("Done. Sent: {$sent}, skipped: {$skipped}, candidates: {$candidates->count()}.");

        return self::SUCCESS;
    }
}
