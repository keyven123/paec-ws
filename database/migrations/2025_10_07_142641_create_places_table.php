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
        Schema::create('places', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('name')->index();
            $table->string('code')->unique()->index();
            $table->string('address')->nullable();
            $table->longText('image_url')->nullable();
            $table->boolean('is_visible')->default(true);
            $table->string('status')->default(GeneralConstants::GENERAL_STATUSES['ACTIVE'])->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('places');
    }
};
