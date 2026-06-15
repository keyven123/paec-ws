<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private function shouldCopyRow(object $row): bool
    {
        if (! empty($row->affiliate_code)) {
            return true;
        }
        if (isset($row->affiliate_status) && $row->affiliate_status !== 'none') {
            return true;
        }
        if (! empty($row->affiliate_applied_at) || ! empty($row->affiliate_approved_at)) {
            return true;
        }
        if (! empty($row->affiliate_suspend_reason) || ! empty($row->affiliate_suspended_at)) {
            return true;
        }
        foreach ([
            'affiliate_bank_name',
            'affiliate_bank_branch',
            'affiliate_bank_account_name',
            'affiliate_bank_account_number',
            'affiliate_bank_tin',
        ] as $col) {
            if (! empty($row->{$col})) {
                return true;
            }
        }

        return false;
    }

    /**
     * If user_affiliates already existed without the full schema, add missing columns
     * so data copy and application queries do not fail.
     */
    private function ensureUserAffiliatesColumns(): void
    {
        if (! Schema::hasTable('user_affiliates')) {
            return;
        }

        Schema::table('user_affiliates', function (Blueprint $table) {
            if (! Schema::hasColumn('user_affiliates', 'affiliate_status')) {
                $table->string('affiliate_status', 32)->default('none');
            }
            if (! Schema::hasColumn('user_affiliates', 'affiliate_code')) {
                $table->string('affiliate_code', 32)->nullable()->unique();
            }
            if (! Schema::hasColumn('user_affiliates', 'affiliate_applied_at')) {
                $table->timestamp('affiliate_applied_at')->nullable();
            }
            if (! Schema::hasColumn('user_affiliates', 'affiliate_approved_at')) {
                $table->timestamp('affiliate_approved_at')->nullable();
            }
            if (! Schema::hasColumn('user_affiliates', 'affiliate_suspend_reason')) {
                $table->text('affiliate_suspend_reason')->nullable();
            }
            if (! Schema::hasColumn('user_affiliates', 'affiliate_suspended_at')) {
                $table->timestamp('affiliate_suspended_at')->nullable();
            }
            if (! Schema::hasColumn('user_affiliates', 'affiliate_bank_name')) {
                $table->string('affiliate_bank_name', 191)->nullable();
            }
            if (! Schema::hasColumn('user_affiliates', 'affiliate_bank_branch')) {
                $table->string('affiliate_bank_branch', 191)->nullable();
            }
            if (! Schema::hasColumn('user_affiliates', 'affiliate_bank_account_name')) {
                $table->string('affiliate_bank_account_name', 191)->nullable();
            }
            if (! Schema::hasColumn('user_affiliates', 'affiliate_bank_account_number')) {
                $table->string('affiliate_bank_account_number', 100)->nullable();
            }
            if (! Schema::hasColumn('user_affiliates', 'affiliate_bank_tin')) {
                $table->string('affiliate_bank_tin', 32)->nullable();
            }
            if (! Schema::hasColumn('user_affiliates', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }
            if (! Schema::hasColumn('user_affiliates', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }
        });
    }

    public function up(): void
    {
        if (! Schema::hasTable('user_affiliates')) {
            Schema::create('user_affiliates', function (Blueprint $table) {
                $table->uuid('uuid')->primary();
                $table->uuid('user_uuid')->unique()->index();
                $table->string('affiliate_status', 32)->default('none')->index();
                $table->string('affiliate_code', 32)->nullable()->unique()->index();
                $table->timestamp('affiliate_applied_at')->nullable();
                $table->timestamp('affiliate_approved_at')->nullable();
                $table->text('affiliate_suspend_reason')->nullable();
                $table->timestamp('affiliate_suspended_at')->nullable();
                $table->string('affiliate_bank_name', 191)->nullable();
                $table->string('affiliate_bank_branch', 191)->nullable();
                $table->string('affiliate_bank_account_name', 191)->nullable();
                $table->string('affiliate_bank_account_number', 100)->nullable();
                $table->string('affiliate_bank_tin', 32)->nullable();
                $table->timestamps();
            });
        }

        $this->ensureUserAffiliatesColumns();

        if (! Schema::hasColumn('users', 'affiliate_status')) {
            return;
        }

        foreach (DB::table('users')->orderBy('uuid')->cursor() as $row) {
            if (! $this->shouldCopyRow($row)) {
                continue;
            }
            if (DB::table('user_affiliates')->where('user_uuid', $row->uuid)->exists()) {
                continue;
            }
            DB::table('user_affiliates')->insert([
                'uuid' => (string) Str::uuid(),
                'user_uuid' => $row->uuid,
                'affiliate_status' => $row->affiliate_status ?? 'none',
                'affiliate_code' => $row->affiliate_code,
                'affiliate_applied_at' => $row->affiliate_applied_at,
                'affiliate_approved_at' => $row->affiliate_approved_at,
                'affiliate_suspend_reason' => $row->affiliate_suspend_reason ?? null,
                'affiliate_suspended_at' => $row->affiliate_suspended_at ?? null,
                'affiliate_bank_name' => $row->affiliate_bank_name,
                'affiliate_bank_branch' => $row->affiliate_bank_branch,
                'affiliate_bank_account_name' => $row->affiliate_bank_account_name,
                'affiliate_bank_account_number' => $row->affiliate_bank_account_number,
                'affiliate_bank_tin' => $row->affiliate_bank_tin ?? null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        if (Schema::hasColumn('users', 'affiliate_code')) {
            try {
                Schema::table('users', function (Blueprint $table) {
                    $table->dropUnique(['affiliate_code']);
                });
            } catch (\Throwable) {
            }
        }

        $columns = array_values(array_filter([
            Schema::hasColumn('users', 'affiliate_status') ? 'affiliate_status' : null,
            Schema::hasColumn('users', 'affiliate_code') ? 'affiliate_code' : null,
            Schema::hasColumn('users', 'affiliate_applied_at') ? 'affiliate_applied_at' : null,
            Schema::hasColumn('users', 'affiliate_approved_at') ? 'affiliate_approved_at' : null,
            Schema::hasColumn('users', 'affiliate_suspend_reason') ? 'affiliate_suspend_reason' : null,
            Schema::hasColumn('users', 'affiliate_suspended_at') ? 'affiliate_suspended_at' : null,
            Schema::hasColumn('users', 'affiliate_bank_name') ? 'affiliate_bank_name' : null,
            Schema::hasColumn('users', 'affiliate_bank_branch') ? 'affiliate_bank_branch' : null,
            Schema::hasColumn('users', 'affiliate_bank_account_name') ? 'affiliate_bank_account_name' : null,
            Schema::hasColumn('users', 'affiliate_bank_account_number') ? 'affiliate_bank_account_number' : null,
            Schema::hasColumn('users', 'affiliate_bank_tin') ? 'affiliate_bank_tin' : null,
        ]));

        if ($columns !== []) {
            Schema::table('users', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'affiliate_status')) {
                $table->string('affiliate_status', 32)->default('none');
            }
            if (! Schema::hasColumn('users', 'affiliate_code')) {
                $table->string('affiliate_code', 32)->nullable()->unique();
            }
            if (! Schema::hasColumn('users', 'affiliate_applied_at')) {
                $table->timestamp('affiliate_applied_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'affiliate_approved_at')) {
                $table->timestamp('affiliate_approved_at')->nullable();
            }
            if (! Schema::hasColumn('users', 'affiliate_bank_name')) {
                $table->string('affiliate_bank_name', 191)->nullable();
            }
            if (! Schema::hasColumn('users', 'affiliate_bank_branch')) {
                $table->string('affiliate_bank_branch', 191)->nullable();
            }
            if (! Schema::hasColumn('users', 'affiliate_bank_account_name')) {
                $table->string('affiliate_bank_account_name', 191)->nullable();
            }
            if (! Schema::hasColumn('users', 'affiliate_bank_account_number')) {
                $table->string('affiliate_bank_account_number', 100)->nullable();
            }
            if (! Schema::hasColumn('users', 'affiliate_bank_tin')) {
                $table->string('affiliate_bank_tin', 32)->nullable();
            }
            if (! Schema::hasColumn('users', 'affiliate_suspend_reason')) {
                $table->text('affiliate_suspend_reason')->nullable();
            }
            if (! Schema::hasColumn('users', 'affiliate_suspended_at')) {
                $table->timestamp('affiliate_suspended_at')->nullable();
            }
        });

        if (Schema::hasTable('user_affiliates')) {
            foreach (DB::table('user_affiliates')->cursor() as $a) {
                DB::table('users')->where('uuid', $a->user_uuid)->update([
                    'affiliate_status' => $a->affiliate_status,
                    'affiliate_code' => $a->affiliate_code,
                    'affiliate_applied_at' => $a->affiliate_applied_at,
                    'affiliate_approved_at' => $a->affiliate_approved_at,
                    'affiliate_suspend_reason' => $a->affiliate_suspend_reason,
                    'affiliate_suspended_at' => $a->affiliate_suspended_at,
                    'affiliate_bank_name' => $a->affiliate_bank_name,
                    'affiliate_bank_branch' => $a->affiliate_bank_branch,
                    'affiliate_bank_account_name' => $a->affiliate_bank_account_name,
                    'affiliate_bank_account_number' => $a->affiliate_bank_account_number,
                    'affiliate_bank_tin' => $a->affiliate_bank_tin,
                ]);
            }
        }

        Schema::dropIfExists('user_affiliates');
    }
};
