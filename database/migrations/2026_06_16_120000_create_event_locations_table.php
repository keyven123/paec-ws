<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_locations', function (Blueprint $table) {
            $table->uuid('uuid')->primary();
            $table->uuid('event_uuid');
            $table->string('name')->nullable();
            $table->string('city');
            $table->text('address')->nullable();
            $table->uuid('organization_uuid')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->foreign('event_uuid')
                ->references('uuid')
                ->on('events')
                ->cascadeOnDelete();
            $table->foreign('organization_uuid')
                ->references('uuid')
                ->on('organizations')
                ->nullOnDelete();
            $table->index(['event_uuid', 'is_active']);
        });

        foreach (['temp_transactions', 'transactions', 'tickets'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if (! Schema::hasColumn($tableName, 'event_location_uuid')) {
                    $table->uuid('event_location_uuid')->nullable()->after('event_uuid');
                    $table->foreign('event_location_uuid')
                        ->references('uuid')
                        ->on('event_locations')
                        ->nullOnDelete();
                }
            });
        }

        $this->backfillDefaultLocations();
        $this->seedBariManilaLocation();
    }

    public function down(): void
    {
        foreach (['temp_transactions', 'transactions', 'tickets'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                continue;
            }

            if (Schema::hasColumn($tableName, 'event_location_uuid')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropForeign(['event_location_uuid']);
                    $table->dropColumn('event_location_uuid');
                });
            }
        }

        Schema::dropIfExists('event_locations');
    }

    private function backfillDefaultLocations(): void
    {
        DB::table('events')
            ->orderBy('uuid')
            ->chunkById(100, function ($events) {
                foreach ($events as $event) {
                    $locationUuid = (string) Str::uuid();

                    DB::table('event_locations')->insert([
                        'uuid' => $locationUuid,
                        'event_uuid' => $event->uuid,
                        'name' => null,
                        'city' => $event->city ?: $this->inferCityFromAddress($event->address),
                        'address' => $event->address,
                        'organization_uuid' => $event->organization_uuid,
                        'is_active' => true,
                        'sort_order' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    foreach (['temp_transactions', 'transactions', 'tickets'] as $tableName) {
                        if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'event_location_uuid')) {
                            continue;
                        }

                        DB::table($tableName)
                            ->where('event_uuid', $event->uuid)
                            ->whereNull('event_location_uuid')
                            ->update(['event_location_uuid' => $locationUuid]);
                    }
                }
            }, 'uuid');
    }

    private function seedBariManilaLocation(): void
    {
        $event = DB::table('events')
            ->where('slug', 'bari-the-abandoned-princess')
            ->first();

        if (! $event) {
            return;
        }

        $existingManila = DB::table('event_locations')
            ->where('event_uuid', $event->uuid)
            ->where('city', 'Manila')
            ->exists();

        if ($existingManila) {
            return;
        }

        DB::table('event_locations')->insert([
            'uuid' => (string) Str::uuid(),
            'event_uuid' => $event->uuid,
            'name' => 'Manila Branch',
            'city' => 'Manila',
            'address' => 'Intramuros, Manila',
            'organization_uuid' => $event->organization_uuid,
            'is_active' => true,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
