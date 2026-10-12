<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_gateways', function (Blueprint $table) {
            // Paynamics' HTTP Basic auth credentials; stored encrypted like the keys.
            $table->text('api_username')->nullable()->after('secret_key');
            $table->text('api_password')->nullable()->after('api_username');
        });
    }

    public function down(): void
    {
        Schema::table('payment_gateways', function (Blueprint $table) {
            $table->dropColumn(['api_username', 'api_password']);
        });
    }
};
