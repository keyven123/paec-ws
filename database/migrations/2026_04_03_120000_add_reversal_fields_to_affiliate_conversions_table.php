<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('affiliate_conversions', function (Blueprint $table) {
            $table->dropUnique(['transaction_uuid']);
            $table->string('entry_type', 16)->default('credit')->index()->after('transaction_uuid');
            $table->uuid('ticket_uuid')->nullable()->after('entry_type');
            $table->unique('ticket_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('affiliate_conversions', function (Blueprint $table) {
            $table->dropUnique(['ticket_uuid']);
            $table->dropColumn(['entry_type', 'ticket_uuid']);
            $table->unique('transaction_uuid');
        });
    }
};
