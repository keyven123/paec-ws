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
        Schema::create('organizations', function (Blueprint $table) {
            $table->uuid('uuid')->primary()->unique()->index();
            $table->uuid('image_uuid')->nullable()->index();
            $table->string('business_type', 50)->index();
            $table->string('name')->index();
            $table->string('representative_first_name')->index();
            $table->string('representative_last_name')->nullable()->index();
            $table->string('address')->nullable();
            $table->string('contact_number')->nullable();
            $table->string('email')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_branch')->nullable();
            $table->string('bank_address')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->string('bank_account_number')->nullable();
            $table->string('tin', 50)->nullable();
            $table->text('description')->nullable();
            $table->decimal('commission_percentage', 5, 2)->nullable();
            $table->string('secret')->nullable();
            $table->dateTime('secret_expired_at')->nullable();
            $table->uuid('approved_by')->nullable()->index();
            $table->timestamp('approved_at')->nullable();
            $table->integer('send_invite_count')->default(0);
            $table->string('status')->default(GeneralConstants::ORGANIZER_STATUSES['PENDING'])->index();
            $table->json('payment_methods')->nullable();
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
        Schema::dropIfExists('organizations');
    }
};
