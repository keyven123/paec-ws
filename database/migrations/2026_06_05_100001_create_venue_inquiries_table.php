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
        Schema::create('venue_inquiries', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('venue_listing_uuid')->index();
            $table->uuid('user_uuid')->nullable()->index();
            $table->string('full_name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->string('event_type')->nullable();
            $table->date('event_date')->nullable();
            $table->unsignedInteger('guest_count')->nullable();
            $table->string('site_visit', 10)->nullable();
            $table->date('visit_scheduled_date')->nullable();
            $table->time('visit_scheduled_time')->nullable();
            $table->text('message')->nullable();
            $table->string('status')->default('new')->index();
            $table->decimal('approved_amount', 12, 4)->nullable();
            $table->date('approved_due_date')->nullable();
            $table->decimal('proposal_amount', 12, 4)->nullable();
            $table->date('proposal_valid_until')->nullable();
            $table->uuid('proposal_upload_uuid')->nullable();
            $table->timestamp('proposal_sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();

            $table->decimal('deposit_amount', 12, 4)->nullable();
            $table->date('deposit_due_date')->nullable();
            $table->timestamp('deposit_paid_at')->nullable();

            $table->decimal('balance_amount', 12, 4)->nullable();
            $table->decimal('additional_charges', 12, 4)->default(0);
            $table->date('balance_due_date')->nullable();
            $table->timestamp('fully_paid_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('venue_listing_uuid')
                ->references('uuid')
                ->on('venue_listings')
                ->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('venue_inquiries');
    }
};
