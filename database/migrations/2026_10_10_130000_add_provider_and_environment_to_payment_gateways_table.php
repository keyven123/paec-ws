<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_gateways', function (Blueprint $table) {
            $table->string('provider')->default('paymongo')->after('name');
            // Detected from the keys; null when the provider's keys don't reveal it.
            $table->string('environment')->nullable()->after('provider');

            $table->index(['provider', 'environment']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_gateways', function (Blueprint $table) {
            $table->dropIndex(['provider', 'environment']);
            $table->dropColumn(['provider', 'environment']);
        });
    }
};
