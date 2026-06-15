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
        Schema::create('event_sections', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('name')->index();
            $table->string('title')->index();
            $table->text('description')->nullable();
            $table->string('status')->default('active')->index();
            $table->integer('display_order')->default(0);
            $table->boolean('is_hidden')->default(false);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['is_hidden']);
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
        Schema::dropIfExists('event_sections');
    }
};
