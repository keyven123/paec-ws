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
        Schema::create('uploads', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->string('uploadable_type')->nullable()->index();
            $table->uuid('uploadable_uuid')->nullable()->index();

            $table->string('collection', 64)->nullable()->default('default')->index();

            // file classification
            $table->string('type', 32)->index(); // image, csv, xlsx, pdf, video, audio, other
            $table->string('mime_type', 128)->nullable();
            $table->string('extension', 16)->nullable();

            // storage location
            $table->string('disk', 64)->default('public');
            $table->string('path'); // e.g. uploads/2025/10/uuid.ext

            // metadata
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('checksum', 64)->nullable(); // sha256
            $table->string('dominant_color', 7)->nullable();
            $table->string('name')->nullable();
            $table->string('alt_text')->nullable();
            $table->integer('order_number')->nullable();

            $table->uuid('created_by')->nullable()->index();
            $table->index(['uploadable_type', 'uploadable_uuid'], 'uploads_uploadable_index');
            $table->timestamps();
            $table->index(['created_at']);
            $table->index(['updated_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('uploads');
    }
};
