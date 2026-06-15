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
        Schema::create('venue_seats', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('venue_uuid')->index();
            $table->integer('col')->index();
            $table->string('row')->index();
            $table->integer('seat_no')->index();
            $table->string('category')->index();
            $table->string('color');
            $table->integer('order')->nullable();
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
        Schema::dropIfExists('venue_seats');
    }
};
