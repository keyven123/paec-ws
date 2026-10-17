<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_tickets', function (Blueprint $table) {
            $table->string('operation_days', 20)
                ->default('any')
                ->after('visit_policy');
            $table->time('operation_hours_from')->nullable()->after('operation_days');
            $table->time('operation_hours_to')->nullable()->after('operation_hours_from');
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->time('visit_time')->nullable()->after('valid_until');
        });

        Schema::table('transaction_orders', function (Blueprint $table) {
            $table->time('visit_time')->nullable()->after('valid_until');
        });

        if (Schema::hasTable('temp_transaction_orders')) {
            Schema::table('temp_transaction_orders', function (Blueprint $table) {
                $table->time('visit_time')->nullable()->after('valid_until');
            });
        }
    }

    public function down(): void
    {
        Schema::table('event_tickets', function (Blueprint $table) {
            $table->dropColumn([
                'operation_days',
                'operation_hours_from',
                'operation_hours_to',
            ]);
        });

        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn('visit_time');
        });

        Schema::table('transaction_orders', function (Blueprint $table) {
            $table->dropColumn('visit_time');
        });

        if (Schema::hasTable('temp_transaction_orders')) {
            Schema::table('temp_transaction_orders', function (Blueprint $table) {
                $table->dropColumn('visit_time');
            });
        }
    }
};
