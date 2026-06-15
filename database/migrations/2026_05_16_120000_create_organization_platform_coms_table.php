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
        Schema::create('organization_platform_coms', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('organization_uuid')->nullable()->index();
            $table->decimal('previous_coms', 5, 2)->nullable();
            $table->decimal('current_coms', 5, 2);
            $table->uuid('created_by')->nullable()->index();
            $table->timestamps();

            $table->foreign('organization_uuid')
                ->references('uuid')
                ->on('organizations')
                ->nullOnDelete();
            $table->foreign('created_by')
                ->references('uuid')
                ->on('admin_users')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_platform_coms');
    }
};
