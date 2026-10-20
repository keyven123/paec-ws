<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_tickets', function (Blueprint $table) {
            $table->string('ticket_kind', 30)->default('regular')->index()->after('name');
            $table->text('terms_and_conditions')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('event_tickets', function (Blueprint $table) {
            $table->dropColumn(['ticket_kind', 'terms_and_conditions']);
        });
    }
};
