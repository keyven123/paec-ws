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
        Schema::create('event_tickets', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('event_uuid')->index();
            $table->uuid('schedule_uuid')->index()->nullable();
            $table->uuid('schedule_time_uuid')->index()->nullable();
            $table->string('code')->index();
            $table->string('name')->index();
            $table->text('description')->nullable();
            $table->decimal('price', 8, 2);
            $table->boolean('is_bundle')->default(false);
            $table->integer('bundle_quantity')->nullable();
            $table->string('discount_type')->nullable();
            $table->decimal('discount_value', 8, 2)->nullable();
            $table->string('bg_color')->nullable();
            $table->json('bundle_tickets')->nullable(); // array of ticket_types
            $table->dateTime('available_from')->nullable();
            $table->dateTime('available_to')->nullable();
            $table->string('visit_policy')->nullable();
            $table->integer('validity_days')->nullable();
            $table->integer('display_order')->default(1);
            $table->integer('max_ticket')->nullable();
            $table->integer('sold_ticket')->default(0);
            $table->integer('ticket_limit_per_user')->nullable();
            $table->boolean('is_virtual')->default(false);
            $table->text('virtual_event_url')->nullable();
            $table->boolean('is_unlimited')->default(false);
            $table->string('status')->default(GeneralConstants::GENERAL_STATUSES['ACTIVE'])->index();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

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
        Schema::dropIfExists('event_tickets');
    }
};
