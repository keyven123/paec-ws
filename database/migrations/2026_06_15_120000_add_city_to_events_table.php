<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (! Schema::hasColumn('events', 'city')) {
                $table->string('city')->nullable()->after('address');
            }
        });

        DB::table('events')
            ->where(function ($query) {
                $query->whereNull('city')->orWhere('city', '');
            })
            ->orderBy('uuid')
            ->chunkById(100, function ($events) {
                foreach ($events as $event) {
                    $city = $this->inferCityFromAddress($event->address);

                    DB::table('events')
                        ->where('uuid', $event->uuid)
                        ->update(['city' => $city]);
                }
            }, 'uuid');
    }

    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            if (Schema::hasColumn('events', 'city')) {
                $table->dropColumn('city');
            }
        });
    }

    private function inferCityFromAddress(?string $address): string
    {
        $address = trim((string) $address);

        if ($address === '') {
            return 'Metro Manila';
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $address))));

        if (count($parts) >= 2) {
            return (string) end($parts);
        }

        return 'Metro Manila';
    }
};
