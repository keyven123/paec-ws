<?php

use App\Constants\GeneralConstants;
use App\Models\Ticket;
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
        Schema::create('tickets', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('user_uuid')->index();
            $table->uuid('organization_uuid')->nullable()->index();
            $table->uuid('transaction_uuid')->index();
            $table->uuid('event_uuid')->index();
            $table->uuid('event_ticket_uuid')->index();
            $table->uuid('venue_seat_uuid')->nullable()->index();
            $table->string('ticket_number')->nullable()->index();
            $table->string('col')->nullable()->index();
            $table->string('row')->nullable()->index();
            $table->string('status')->default(GeneralConstants::TICKET_STATUSES['PENDING'])->index();
            $table->string('attendee_name')->index();
            $table->string('attendee_email')->index();
            $table->string('attendee_contact')->nullable();
            $table->string('visit_policy')->nullable()->index();
            $table->string('qr_code')->nullable()->index();
            $table->dateTime('valid_until')->nullable();
            $table->dateTime('used_at')->nullable();
            $table->dateTime('transferred_at')->nullable();
            $table->uuid('transferred_to')->nullable()->index();
            $table->uuid('transferred_by')->nullable()->index();
            $table->integer('transfer_count')->default(0);
            $table->boolean('is_downloaded')->default(false);
            $table->decimal('price', 8, 2)->nullable();
            $table->decimal('discount', 8, 2)->nullable();
            $table->string('type')->default(Ticket::TYPES['PAID'])->index()->nullable();
            $table->json('other_info')->nullable();
            $table->text('remarks')->nullable();
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
        Schema::dropIfExists('tickets');
    }
};
