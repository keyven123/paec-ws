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
        Schema::create('organization_banks', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('organization_uuid');
            $table->string('account_type', 50);
            $table->string('bank_name');
            $table->string('bank_branch');
            $table->string('bank_address')->nullable();
            $table->string('bank_account_name');
            $table->string('bank_account_number');
            $table->boolean('is_default')->default(false);
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->foreign('organization_uuid')
                ->references('uuid')
                ->on('organizations')
                ->cascadeOnDelete();

            $table->index(['organization_uuid', 'is_default']);
            $table->index(['organization_uuid', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_banks');
    }
};
