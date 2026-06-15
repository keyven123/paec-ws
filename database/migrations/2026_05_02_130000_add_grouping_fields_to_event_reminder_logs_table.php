<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_reminder_logs', function (Blueprint $table) {
            $table->uuid('user_uuid')->nullable()->after('transaction_uuid')->index();
            $table->uuid('event_uuid')->nullable()->after('user_uuid')->index();
            $table->uuid('schedule_uuid')->nullable()->after('event_uuid')->index();
            $table->uuid('schedule_time_uuid')->nullable()->after('schedule_uuid')->index();
            $table->string('group_key', 160)->nullable()->after('schedule_time_uuid')->index();
        });

        DB::table('event_reminder_logs')
            ->whereNull('group_key')
            ->orderBy('created_at')
            ->chunkById(100, function ($logs): void {
                foreach ($logs as $log) {
                    $transaction = DB::table('transactions')
                        ->where('uuid', $log->transaction_uuid)
                        ->first();

                    if ($transaction === null) {
                        DB::table('event_reminder_logs')
                            ->where('uuid', $log->uuid)
                            ->update(['group_key' => 'transaction:' . $log->transaction_uuid]);

                        continue;
                    }

                    DB::table('event_reminder_logs')
                        ->where('uuid', $log->uuid)
                        ->update([
                            'user_uuid' => $transaction->user_uuid,
                            'event_uuid' => $transaction->event_uuid,
                            'schedule_uuid' => $transaction->schedule_uuid,
                            'schedule_time_uuid' => $transaction->schedule_time_uuid,
                            'group_key' => implode('|', [
                                $transaction->user_uuid,
                                $transaction->event_uuid,
                                $transaction->schedule_uuid,
                                $transaction->schedule_time_uuid ?: 'none',
                            ]),
                        ]);
                }
            }, 'uuid');

        Schema::table('event_reminder_logs', function (Blueprint $table) {
            $table->unique(['reminder_type', 'group_key'], 'event_reminder_logs_group_unique_idx');
        });
    }

    public function down(): void
    {
        Schema::table('event_reminder_logs', function (Blueprint $table) {
            $table->dropUnique('event_reminder_logs_group_unique_idx');
            $table->dropColumn([
                'user_uuid',
                'event_uuid',
                'schedule_uuid',
                'schedule_time_uuid',
                'group_key',
            ]);
        });
    }
};
