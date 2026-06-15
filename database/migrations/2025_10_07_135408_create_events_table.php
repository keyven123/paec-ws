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
        Schema::create('events', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('organization_uuid')->nullable();
            $table->uuid('venue_uuid')->nullable();
            $table->uuid('category_uuid')->nullable();
            $table->uuid('event_section_uuid')->nullable();
            $table->string('event_name');
            $table->text('event_description')->nullable();
            $table->string('contact_email');
            $table->decimal('total_revenue', 18, 2)->default(0);
            $table->bigInteger('ticket_sold')->default(0);
            $table->bigInteger('total_orders')->default(0);
            $table->string('address')->nullable();
            $table->uuid('logo_uuid')->nullable();
            $table->uuid('portrait_image_uuid')->nullable();
            $table->uuid('featured_image_uuid')->nullable();
            $table->json('event_showcase')->nullable(); // videos or images
            $table->string('event_config')->nullable();
            $table->string('event_type');
            $table->string('schedule_type')->nullable();
            $table->string('ticket_prefix')->nullable();
            $table->json('excluded_dates')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->integer('registration_count')->default(0);
            $table->boolean('is_request_for_featured')->default(false);
            $table->boolean('is_featured')->default(false);
            $table->integer('featured_order')->nullable();
            $table->timestamp('featured_from')->nullable();
            $table->timestamp('featured_until')->nullable();
            $table->string('meta_title')->nullable();
            $table->text('meta_description')->nullable();
            $table->json('tags')->nullable();
            $table->boolean('track_event_meta')->default(false);
            $table->string('meta_pixel_id')->nullable();
            $table->text('meta_pixel_key')->nullable();
            $table->string('slug')->unique()->nullable();
            $table->string('meta_test_event_code')->nullable();
            $table->string('status')->default(GeneralConstants::EVENT_STATUSES['DRAFT']);
            $table->json('blocked_seats')->nullable();
            $table->json('other_info')->nullable();
            $table->timestamp('other_info_deadline')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Indexes
            $table->index(['slug']);
            $table->index(['event_name']);
            $table->index(['category_uuid']);
            $table->index(['organization_uuid']);
            $table->index(['event_section_uuid']);
            $table->index(['venue_uuid']);
            $table->index(['event_type']);
            $table->index(['schedule_type']);
            $table->index(['is_request_for_featured']);
            $table->index(['is_featured']);
            $table->index(['featured_from']);
            $table->index(['published_at']);
            $table->index(['cancelled_at']);
            $table->index(['completed_at']);
            $table->index(['status']);
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
        Schema::dropIfExists('events');
    }
};
