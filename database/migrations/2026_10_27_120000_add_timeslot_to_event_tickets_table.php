<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_tickets', function (Blueprint $table) {
            // How long each purchase visit window lasts (e.g. 180 = 3-hour slots).
            $table->unsignedSmallInteger('timeslot_duration_minutes')->nullable()->after('operation_hours_to');
            // How often a new slot starts within operation hours (e.g. 60 = hourly).
            $table->unsignedSmallInteger('timeslot_interval_minutes')->nullable()->after('timeslot_duration_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('event_tickets', function (Blueprint $table) {
            $table->dropColumn(['timeslot_duration_minutes', 'timeslot_interval_minutes']);
        });
    }
};
