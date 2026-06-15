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
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('uuid')->primary()->unique()->index();
            $table->uuid('role_uuid')->index();
            $table->uuid('profile_image_uuid')->nullable()->index();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('first_name')->index();
            $table->string('middle_name')->nullable();
            $table->string('last_name')->index();
            $table->string('phone_number')->nullable();
            $table->date('birth_date')->index()->nullable();
            $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country')->nullable();
            $table->string('status')->default(GeneralConstants::GENERAL_STATUSES['ACTIVE']);
            $table->boolean('is_first_time_login')->default(true);
            $table->string('qr_code')->nullable();
            $table->string('affiliate_status', 32)->default('none');
            $table->string('affiliate_code', 32)->nullable()->unique();
            $table->timestamp('affiliate_applied_at')->nullable();
            $table->timestamp('affiliate_approved_at')->nullable();
            $table->string('affiliate_bank_name', 191)->nullable();
            $table->string('affiliate_bank_branch', 191)->nullable();
            $table->string('affiliate_bank_account_name', 191)->nullable();
            $table->string('affiliate_bank_account_number', 100)->nullable();
            $table->string('affiliate_bank_tin', 32)->nullable();
            $table->text('affiliate_suspend_reason')->nullable();
            $table->timestamp('affiliate_suspended_at')->nullable();
            $table->string('marketing_consent')->nullable();
            $table->date('marketing_consent_date')->nullable();
            $table->string('provider')->nullable()->index();
            $table->string('provider_id')->nullable()->index();
            $table->string('avatar')->nullable();
            $table->date('terms_accepted_at')->nullable();
            $table->boolean('is_migrated')->default(false);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['created_at', 'updated_at']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
