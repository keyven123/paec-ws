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
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['created_by', 'updated_by']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->uuid('created_by')->nullable()->after('is_admin');
            $table->uuid('updated_by')->nullable()->after('created_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['created_by', 'updated_by']);
        });

        Schema::table('roles', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->after('is_admin');
            $table->unsignedBigInteger('updated_by')->nullable()->after('created_by');
        });
    }
};
