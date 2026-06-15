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
        Schema::create('otps', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->text('receiver');
            $table->string('otpable_id', 36)->nullable();
            $table->string('otpable_type')->nullable();
            $table->string('secret', 100)->index();
            $table->dateTime('resendable_at');
            $table->dateTime('expires_at');
            $table->timestamps();
            $table->index(['created_at', 'updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('otps');
    }
};
