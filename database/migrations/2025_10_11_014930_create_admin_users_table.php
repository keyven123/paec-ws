<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('role_uuid')->index();
            $table->uuid('organization_uuid')->index()->nullable();
            $table->string('email')->unique()->index();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password')->default(Hash::make(Str::random(10)));
            $table->string('first_name')->index();
            $table->string('middle_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone_number')->nullable();
            $table->enum('status', ['active', 'inactive'])->default('active')->index();
            $table->boolean('accepted_terms')->default(false);
            $table->date('accepted_terms_at')->nullable();
            $table->boolean('is_first_time_login')->default(true);
            $table->timestamp('last_login_at')->nullable();
            $table->boolean('is_migrated')->default(false);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('created_at');
            $table->index('updated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_users');
    }
};
