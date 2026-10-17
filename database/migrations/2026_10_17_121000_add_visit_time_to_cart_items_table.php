<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cart_items')) {
            return;
        }

        Schema::table('cart_items', function (Blueprint $table) {
            if (! Schema::hasColumn('cart_items', 'visit_time')) {
                $table->time('visit_time')->nullable()->after('visit_date');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cart_items') || ! Schema::hasColumn('cart_items', 'visit_time')) {
            return;
        }

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropColumn('visit_time');
        });
    }
};
