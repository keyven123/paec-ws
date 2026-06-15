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
        Schema::create('blocked_dates', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('blockable_type');
            $table->uuid('blockable_uuid');
            $table->date('blocked_date');
            $table->string('reason')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['blockable_type', 'blockable_uuid'], 'blocked_dates_blockable_index');
            $table->unique(
                ['blockable_type', 'blockable_uuid', 'blocked_date'],
                'blocked_dates_blockable_date_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blocked_dates');
    }
};
