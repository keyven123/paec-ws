<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateways', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('name');
            $table->string('gateway_id');
            // Encrypted at rest via the model casts, so the columns need room for the ciphertext.
            $table->text('public_key');
            $table->text('secret_key');
            $table->string('status')->default('active');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('gateway_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateways');
    }
};
