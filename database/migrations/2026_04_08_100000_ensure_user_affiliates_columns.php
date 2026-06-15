<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repairs user_affiliates when the table was created empty or by an older script so that
 * the main migration's Schema::create was skipped and no affiliate columns exist.
 */
return new class extends Migration
{
    public function up(): void
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

    public function down(): void
    {
        // Schema repair: forward-only.
    }
};
