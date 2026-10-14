<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_gateways', function (Blueprint $table) {
            // Paynamics Paybiz wallet that receives each sale through settlement_information.
            $table->string('paybiz_wallet_id', 64)->nullable()->after('api_password');
        });
    }

    public function down(): void
    {
        Schema::table('payment_gateways', function (Blueprint $table) {
            $table->dropColumn('paybiz_wallet_id');
        });
    }
};
