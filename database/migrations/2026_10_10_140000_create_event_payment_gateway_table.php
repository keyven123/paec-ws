<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_payment_gateway', function (Blueprint $table) {
            $table->uuid('event_uuid');
            $table->uuid('payment_gateway_uuid');
            $table->timestamps();

            $table->primary(['event_uuid', 'payment_gateway_uuid']);
            $table->index('payment_gateway_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_payment_gateway');
    }
};
