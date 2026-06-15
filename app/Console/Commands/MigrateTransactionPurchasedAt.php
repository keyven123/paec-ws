<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Helpers\CsvHelper;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class MigrateTransactionPurchasedAt extends Command
{
    use CsvHelper;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:migrate-transaction-purchased-at';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Migrate transaction purchased at';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        DB::beginTransaction();
        $this->info('Migrating transaction purchased at...');
        $purchases = $this->csvToArray(app_path('Console/data/purchases.csv'));
        foreach ($purchases as $purchase) {
            $transaction = Transaction::where('order_number', $purchase['order_number'])->first();
            if (!$transaction) {
                $this->error('Transaction not found: ' . $purchase['order_number']);
                continue;
            }
            $transaction->update([
                'created_at' => Carbon::parse($purchase['created_at'])->toDateTimeString(),
                'paid_at' => Carbon::parse($purchase['created_at'])->toDateTimeString(),
                'payment_order_id' => $purchase['paypal_order_id']
            ]);
            $this->info('Transaction updated: ' . $purchase['order_number'] . ' with purchased at: ' . $purchase['created_at']);
        }
        $this->info('Transaction purchased at migrated successfully');
        DB::commit();
    }
}
