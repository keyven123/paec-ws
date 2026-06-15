<?php

namespace App\Console\Commands;

use App\Constants\GeneralConstants;
use App\Models\Ticket;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpireTickets extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:expire-tickets';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire tickets where valid_until date has passed';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting ticket expiration process...');

        try {
            DB::beginTransaction();

            $expiredTickets = Ticket::where('status', GeneralConstants::TICKET_STATUSES['ACTIVE'])
                ->whereNotNull('valid_until')
                ->where('valid_until', '<', now()->timezone('Asia/Manila'))
                ->get();

            $count = $expiredTickets->count();

            if ($count > 0) {
                Ticket::where('status', GeneralConstants::TICKET_STATUSES['ACTIVE'])
                    ->whereNotNull('valid_until')
                    ->where('valid_until', '<', now())
                    ->update(['status' => GeneralConstants::TICKET_STATUSES['EXPIRED']]);

                $this->info("Successfully expired {$count} ticket(s).");
                Log::info("ExpireTickets command: Expired {$count} ticket(s)");
            } else {
                $this->info('No tickets to expire.');
                Log::info('ExpireTickets command: No tickets to expire');
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
