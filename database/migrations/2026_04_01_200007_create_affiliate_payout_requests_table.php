<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_payout_requests', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('user_uuid')->index();
            $table->decimal('amount_requested', 12, 2);
            $table->string('currency', 8)->default('PHP');
            $table->string('status', 32)->default('pending')->index();
            $table->text('admin_notes')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->uuid('processed_by_uuid')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_payout_requests');
    }
};
