<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_conversions', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('partner_user_uuid')->index();
            $table->uuid('transaction_uuid')->index();
            $table->uuid('event_uuid')->index();
            $table->uuid('ticket_uuid')->nullable()->index();
            $table->string('entry_type', 16)->default('credit')->index();
            $table->decimal('order_total', 12, 2);
            $table->decimal('commission_percent', 5, 2);
            $table->decimal('commission_amount', 12, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_conversions');
    }
};
