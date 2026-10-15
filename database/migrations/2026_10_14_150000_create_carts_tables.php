<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('user_uuid')->unique();
            $table->timestamp('last_changed_at')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->timestamps();

            $table->foreign('user_uuid')->references('uuid')->on('users')->cascadeOnDelete();
            $table->index(['reminder_sent_at', 'last_changed_at']);
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('cart_uuid');
            $table->uuid('event_uuid');
            $table->uuid('event_location_uuid')->nullable();
            $table->date('visit_date');
            $table->uuid('promo_code_uuid')->nullable();
            $table->json('tickets');
            $table->json('details')->nullable();
            $table->timestamps();

            $table->foreign('cart_uuid')->references('uuid')->on('carts')->cascadeOnDelete();
            $table->foreign('event_uuid')->references('uuid')->on('events')->cascadeOnDelete();
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->uuid('payment_group_uuid')->nullable()->after('payment_id')->index();
            $table->uuid('cart_item_uuid')->nullable()->after('payment_group_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['payment_group_uuid']);
            $table->dropColumn(['payment_group_uuid', 'cart_item_uuid']);
        });
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
