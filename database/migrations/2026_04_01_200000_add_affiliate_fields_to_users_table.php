<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('affiliate_status', 32)->default('none')->after('qr_code');
            $table->string('affiliate_code', 32)->nullable()->unique()->after('affiliate_status');
            $table->timestamp('affiliate_applied_at')->nullable()->after('affiliate_code');
            $table->timestamp('affiliate_approved_at')->nullable()->after('affiliate_applied_at');
            $table->string('affiliate_bank_name', 191)->nullable()->after('affiliate_approved_at');
            $table->string('affiliate_bank_branch', 191)->nullable()->after('affiliate_bank_name');
            $table->string('affiliate_bank_account_name', 191)->nullable()->after('affiliate_bank_branch');
            $table->string('affiliate_bank_account_number', 100)->nullable()->after('affiliate_bank_account_name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'affiliate_status',
                'affiliate_code',
                'affiliate_applied_at',
                'affiliate_approved_at',
                'affiliate_bank_name',
                'affiliate_bank_branch',
                'affiliate_bank_account_name',
                'affiliate_bank_account_number',
            ]);
        });
    }
};
