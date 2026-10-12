<?php

use App\Helpers\GeneralHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('qr_code')->nullable()->unique()->after('order_number');
        });

        DB::table('transactions')
            ->whereNull('qr_code')
            ->select('uuid')
            ->chunkById(500, function ($transactions) {
                foreach ($transactions as $transaction) {
                    DB::table('transactions')
                        ->where('uuid', $transaction->uuid)
                        ->update(['qr_code' => 'ORDER_' . GeneralHelper::shortUuid(12)]);
                }
            }, 'uuid');
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['qr_code']);
            $table->dropColumn('qr_code');
        });
    }
};
