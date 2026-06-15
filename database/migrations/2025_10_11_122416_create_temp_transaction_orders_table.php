<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('temp_transaction_orders', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('user_uuid')->index();
            $table->uuid('temp_transaction_uuid')->index();
            $table->uuid('event_ticket_uuid')->index();
            $table->integer('quantity');
            $table->decimal('price', 8, 4);
            $table->string('markup_type')->nullable();
            $table->decimal('markup_value', 8, 4)->nullable();
            $table->decimal('markup', 12, 4)->default(0);
            $table->decimal('markup_discount', 12, 4)->default(0);
            $table->decimal('discount', 8, 4)->default(0);
            $table->decimal('total_amount', 8, 4);
            $table->dateTime('valid_until')->nullable();
            $table->json('seats')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('temp_transaction_orders');
    }
};
