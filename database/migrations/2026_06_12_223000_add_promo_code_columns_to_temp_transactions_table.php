<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temp_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('temp_transactions', 'promo_code_uuid')) {
                $table->uuid('promo_code_uuid')->nullable()->after('discount');
            }
            if (! Schema::hasColumn('temp_transactions', 'promo_code_discount')) {
                $table->decimal('promo_code_discount', 8, 2)->default(0)->after('promo_code_uuid');
            }
            if (! Schema::hasColumn('temp_transactions', 'valid_until')) {
                $table->dateTime('valid_until')->nullable()->after('promo_code_discount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('temp_transactions', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('temp_transactions', 'valid_until') ? 'valid_until' : null,
                Schema::hasColumn('temp_transactions', 'promo_code_discount') ? 'promo_code_discount' : null,
                Schema::hasColumn('temp_transactions', 'promo_code_uuid') ? 'promo_code_uuid' : null,
            ]);

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
