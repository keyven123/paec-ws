<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_link_clicks', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('partner_user_uuid')->index();
            $table->string('ref_code', 32)->index();
            $table->string('path', 512)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_link_clicks');
    }
};
