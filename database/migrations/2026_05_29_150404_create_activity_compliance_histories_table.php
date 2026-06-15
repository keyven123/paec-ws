<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_compliance_histories', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('activity_compliance_uuid')->index();
            $table->json('previous_value')->nullable();
            $table->json('current_value');
            $table->uuid('created_by')->nullable()->index();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('activity_compliance_uuid')
                ->references('uuid')
                ->on('activity_compliances')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_compliance_histories');
    }
};
