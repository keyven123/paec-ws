<?php

use App\Constants\GeneralConstants;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_compliances', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('activityable_id');
            $table->string('activityable_type');
            $table->string('label');
            $table->decimal('percentage', 5, 2)->default(0);
            $table->decimal('fixed_amount', 12, 2)->nullable();
            $table->string('amount_type')->default('percentage');
            $table->string('status')
                ->default(GeneralConstants::GENERAL_STATUSES['INACTIVE'])
                ->index();
            $table->uuid('updated_by_uuid')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['activityable_type', 'activityable_id'], 'activity_compliances_activityable_idx');
            $table->index(['activityable_type', 'activityable_id', 'status'], 'activity_compliances_activityable_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_compliances');
    }
};
