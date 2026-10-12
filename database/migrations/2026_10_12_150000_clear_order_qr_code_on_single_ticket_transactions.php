<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('transactions')
            ->whereNotNull('qr_code')
            ->where('payment_status', 'paid')
            ->whereRaw('(select count(*) from tickets where tickets.transaction_uuid = transactions.uuid and tickets.deleted_at is null) <= 1')
            ->update(['qr_code' => null]);
    }

    public function down(): void
    {
        //
    }
};
