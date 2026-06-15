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
        Schema::create('ticket_seats', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('ticket_uuid')->index();
            $table->uuid('venue_uuid')->index();
            $table->uuid('venue_seat_uuid')->index();
            $table->string('col')->index();
            $table->string('row')->index();
            $table->string('seat_no')->index();
            $table->string('category')->index();
            $table->string('color');
            $table->string('status')->default('active')->index();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['created_by']);
            $table->index(['updated_by']);
            $table->index(['created_at']);
            $table->index(['updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ticket_seats');
    }
};
