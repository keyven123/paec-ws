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
        Schema::table('uploads', function (Blueprint $table) {
            // Polymorphic relationship — links this upload to any model.
            // Nullable so existing rows are valid before the backfill migration runs.
            $table->string('uploadable_type')->nullable()->index()->after('uuid');
            $table->uuid('uploadable_uuid')->nullable()->index()->after('uploadable_type');
            $table->index(['uploadable_type', 'uploadable_uuid'], 'uploads_uploadable_index');

            // Collection name within the owning model.
            // e.g. featured, gallery, avatar, cover, logo, portrait, showcase, document
            $table->string('collection', 64)->nullable()->default('default')->index()->after('uploadable_uuid');

            // Per-image display metadata
            $table->string('dominant_color', 7)->nullable()->after('checksum'); // hex, e.g. #3a2f1e
            $table->string('name')->nullable()->after('dominant_color');        // original filename
            $table->string('alt_text')->nullable()->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('uploads', function (Blueprint $table) {
            $table->dropIndex('uploads_uploadable_index');
            $table->dropIndex(['uploadable_type']);
            $table->dropIndex(['uploadable_uuid']);
            $table->dropIndex(['collection']);
            $table->dropColumn(['uploadable_type', 'uploadable_uuid', 'collection', 'dominant_color', 'name', 'alt_text']);
        });
    }
};
