<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (! Schema::hasColumn('events', 'today_cutoff_time')) {
                $table->time('today_cutoff_time')->nullable()->after('blocked_seats');
            }
        });
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (Schema::hasColumn('events', 'today_cutoff_time')) {
                $table->dropColumn('today_cutoff_time');
            }
        });
    }
};
