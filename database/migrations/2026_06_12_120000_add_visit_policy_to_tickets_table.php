<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('tickets', 'visit_policy')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->string('visit_policy')->nullable()->after('qr_code');
            });
        }

        if (
            Schema::hasColumn('event_tickets', 'visit_policy')
            && DB::table('tickets')->exists()
        ) {
            DB::table('tickets')
                ->join('event_tickets', 'tickets.event_ticket_uuid', '=', 'event_tickets.uuid')
                ->whereNull('tickets.visit_policy')
                ->update([
                    'tickets.visit_policy' => DB::raw('event_tickets.visit_policy'),
                ]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tickets', 'visit_policy')) {
            Schema::table('tickets', function (Blueprint $table) {
                $table->dropColumn('visit_policy');
            });
        }
    }
};
