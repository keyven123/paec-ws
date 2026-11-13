<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('event_tickets')) {
            return;
        }

        Schema::table('event_tickets', function (Blueprint $table) {
            if (! Schema::hasColumn('event_tickets', 'operation_allowed_dates')) {
                $table->json('operation_allowed_dates')
                    ->nullable()
                    ->after('operation_custom_days');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('event_tickets')
            || ! Schema::hasColumn('event_tickets', 'operation_allowed_dates')) {
            return;
        }

        Schema::table('event_tickets', function (Blueprint $table) {
            $table->dropColumn('operation_allowed_dates');
        });
    }
};
