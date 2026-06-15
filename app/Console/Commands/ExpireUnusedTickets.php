<?php

namespace App\Console\Commands;

use App\Constants\GeneralConstants;
use App\Models\Ticket;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpireUnusedTickets extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:expire-unused-tickets';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire tickets when schedule date_to is before today (Asia/Manila)';

    /**
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    protected function wherePastScheduleEnd(Builder $query): Builder
    {
        $manilaDate = now()->timezone('Asia/Manila')->toDateString();

        return $query->whereHas('eventTicket', function ($et) use ($manilaDate) {
            $et->whereHas('schedule', function ($s) use ($manilaDate) {
                $s->where('date_to', '<', $manilaDate);
            });
        });
    }

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting ticket expiration process...');

        try {
            DB::beginTransaction();

            $expiredTickets = $this->wherePastScheduleEnd(
                Ticket::where('status', GeneralConstants::TICKET_STATUSES['ACTIVE'])
            )->get();

            $count = $expiredTickets->count();

            if ($count > 0) {
                $this->wherePastScheduleEnd(
                    Ticket::where('status', GeneralConstants::TICKET_STATUSES['ACTIVE'])
                )->update(['status' => GeneralConstants::TICKET_STATUSES['EXPIRED']]);

                $this->info("Successfully expired {$count} ticket(s).");
            } else {
                $this->info('No tickets to expire.');
            }

            DB::commit();

            return Command::SUCCESS;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('Error expiring tickets: ' . $e->getMessage());
            Log::error('ExpireTickets command failed: ' . $e->getMessage(), [
                'exception' => $e
            ]);

            return Command::FAILURE;
        }
    }
}
