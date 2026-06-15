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
        Schema::create('venues', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('place_uuid')->nullable()->index();
            $table->string('name')->index();
            $table->string('code')->unique()->index();
            $table->string('type')->index();
            $table->uuid('image_uuid')->nullable();
            $table->string('status')->default('active')->index();
            $table->json('tickets')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
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
        Schema::dropIfExists('venues');
    }
};
