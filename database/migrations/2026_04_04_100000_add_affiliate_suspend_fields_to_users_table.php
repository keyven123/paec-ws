<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'affiliate_suspend_reason')) {
                $table->text('affiliate_suspend_reason')->nullable();
            }
            if (! Schema::hasColumn('users', 'affiliate_suspended_at')) {
                $table->timestamp('affiliate_suspended_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'affiliate_suspended_at')) {
                $table->dropColumn('affiliate_suspended_at');
            }
            if (Schema::hasColumn('users', 'affiliate_suspend_reason')) {
                $table->dropColumn('affiliate_suspend_reason');
            }
        });
    }
};
