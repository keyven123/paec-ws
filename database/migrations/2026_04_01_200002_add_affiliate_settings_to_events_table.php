<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->boolean('affiliate_enabled')->default(false)->after('status');
            $table->decimal('affiliate_commission_percent', 5, 2)->nullable()->after('affiliate_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn(['affiliate_enabled', 'affiliate_commission_percent']);
        });
    }
};
