<?php

use App\Constants\GeneralConstants;
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
        Schema::create('promo_codes', function (Blueprint $table) {
            $table->uuid('uuid')->primary()->unique()->index();
            $table->uuid('organization_uuid')->index();
            $table->string('code')->index();
            $table->text('description')->nullable();
            $table->string('activityable_id', 36)->nullable()->index();
            $table->string('activityable_type')->nullable()->index();
            $table->string('discount_type')->index();
            $table->decimal('discount_value', 8, 2);
            $table->boolean('is_unlimited')->default(true);
            $table->integer('max_use')->nullable();
            $table->integer('used_count')->default(0);
            $table->dateTime('usable_from');
            $table->dateTime('usable_to');
            $table->string('status')->default(GeneralConstants::GENERAL_STATUSES['ACTIVE'])->index();
            $table->uuid('created_by')->nullable()->index();
            $table->uuid('updated_by')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['created_at']);
            $table->index(['updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promo_codes');
    }
};
