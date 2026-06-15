<?php

namespace App\Console\Commands;

use App\Constants\GeneralConstants;
use App\Models\PromoCode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpirePromoCodes extends Command
{
    protected $signature = 'app:expire-promo-codes';

    protected $description = 'Set promo codes to inactive when usable_to has passed';

    public function handle(): int
    {
        $this->info('Starting promo code expiration process...');

        try {
            DB::beginTransaction();

            $count = PromoCode::query()
                ->where('status', GeneralConstants::GENERAL_STATUSES['ACTIVE'])
                ->where('usable_to', '<', now())
                ->update([
                    'status' => GeneralConstants::GENERAL_STATUSES['INACTIVE'],
                ]);

            if ($count > 0) {
                $this->info("Successfully deactivated {$count} promo code(s).");
            } else {
                $this->info('No promo codes to deactivate.');
            }

            DB::commit();

            return Command::SUCCESS;
        } catch (\Exception $e) {
            DB::rollBack();
            $this->error('Error expiring promo codes: ' . $e->getMessage());
            Log::error('ExpirePromoCodes command failed: ' . $e->getMessage(), [
                'exception' => $e,
            ]);

            return Command::FAILURE;
        }
    }
}
