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
    protected $description = 'Expire unused tickets after visit date or event schedule has ended';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting ticket expiration process...');

        try {
            DB::beginTransaction();

            $today = now('Asia/Manila')->toDateString();

            $reactivated = Ticket::where('status', GeneralConstants::TICKET_STATUSES['EXPIRED'])
                ->whereNull('used_at')
                ->where(function ($query) use ($today) {
                    $query->where(function ($inner) use ($today) {
                        $inner->whereNotNull('valid_until')
                            ->whereRaw('DATE(valid_until) >= ?', [$today]);
                    })->orWhereExists(function ($sub) use ($today) {
                        $sub->selectRaw('1')
                            ->from('transaction_orders')
                            ->whereColumn('transaction_orders.transaction_uuid', 'tickets.transaction_uuid')
                            ->whereColumn('transaction_orders.event_ticket_uuid', 'tickets.event_ticket_uuid')
                            ->whereNotNull('transaction_orders.valid_until')
                            ->whereRaw('DATE(transaction_orders.valid_until) >= ?', [$today]);
                    });
                })
                ->update(['status' => GeneralConstants::TICKET_STATUSES['ACTIVE']]);

            if ($reactivated > 0) {
                $this->info("Reactivated {$reactivated} prematurely expired ticket(s).");
            }

            $expiredTickets = Ticket::whereIn('status', [
                    GeneralConstants::TICKET_STATUSES['PENDING'],
                    GeneralConstants::TICKET_STATUSES['ACTIVE'],
                ])
                ->whereNull('used_at')
                ->schedulePastDue()
                ->get();

            $count = $expiredTickets->count();

            if ($count > 0) {
                Ticket::whereIn('status', [
                    GeneralConstants::TICKET_STATUSES['PENDING'],
                    GeneralConstants::TICKET_STATUSES['ACTIVE'],
                ])
                    ->whereNull('used_at')
                    ->schedulePastDue()
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
