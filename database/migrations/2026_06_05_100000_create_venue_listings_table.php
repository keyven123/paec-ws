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
        Schema::create('venue_listings', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('organization_uuid')->nullable()->index();
            $table->string('slug')->unique();
            $table->string('name')->index();
            $table->text('description')->nullable();
            $table->string('address')->nullable();
            $table->string('location')->nullable();
            $table->string('city')->index();
            $table->string('region')->default('Metro Manila');
            $table->string('area')->nullable();
            $table->string('capacity_label')->nullable();
            $table->unsignedInteger('capacity_min')->nullable();
            $table->unsignedInteger('capacity_max')->nullable();
            $table->string('venue_type')->index();
            $table->string('category')->index();
            $table->decimal('price_per_event', 12, 2)->default(0);
            $table->string('currency', 10)->default('PHP');
            $table->string('status')->default('draft')->index();
            $table->boolean('is_featured')->default(false);
            $table->string('badge')->nullable();
            $table->decimal('rating', 3, 1)->default(0);
            $table->unsignedInteger('review_count')->default(0);
            $table->unsignedInteger('inquiries_count')->default(0);
            $table->unsignedInteger('bookings_count')->default(0);
            $table->string('image_color', 7)->nullable(); // dominant color of the featured image (cached)
            $table->boolean('verified')->default(true);
            $table->string('responds_in')->nullable();
            $table->json('packages')->nullable();
            $table->string('default_package_id')->nullable();
            $table->string('min_capacity_note')->nullable();
            $table->string('max_capacity_note')->nullable();
            $table->json('setups')->nullable();
            $table->json('specs')->nullable();
            $table->json('best_for')->nullable();
            $table->json('amenities')->nullable();
            $table->json('reviews')->nullable();
            $table->uuid('created_by')->nullable()->index();
            $table->uuid('updated_by')->nullable()->index();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('organization_uuid')
                ->references('uuid')
                ->on('organizations')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('venue_listings');
    }
};
