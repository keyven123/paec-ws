<?php

use App\Models\VenueInquiry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venue_inquiries', function (Blueprint $table) {
            $table->timestamp('viewed_at')->nullable()->after('status');
        });

        // Inquiries already moved past "new" have clearly been opened by the venue team.
        DB::table('venue_inquiries')
            ->where('status', '!=', VenueInquiry::STATUSES['NEW'])
            ->update(['viewed_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('venue_inquiries', function (Blueprint $table) {
            $table->dropColumn('viewed_at');
        });
    }
};
