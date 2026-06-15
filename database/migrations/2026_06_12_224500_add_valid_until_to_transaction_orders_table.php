<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaction_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('transaction_orders', 'valid_until')) {
                $table->dateTime('valid_until')->nullable()->after('total_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('transaction_orders', function (Blueprint $table) {
            if (Schema::hasColumn('transaction_orders', 'valid_until')) {
                $table->dropColumn('valid_until');
            }
        });
    }
};
