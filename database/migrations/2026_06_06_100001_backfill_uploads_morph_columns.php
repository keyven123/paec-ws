<?php

use App\Helpers\GeneralHelper;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Map of: parent_table => [ fk_column, short_model_name, collection_name ]
     *
     * Short model names are resolved via GeneralHelper::resolveModelClass() so
     * any alias added there is automatically picked up here too.
     */
    private array $singleFkMap = [
        'events' => [
            ['logo_uuid',           'Event', 'logo'],
            ['portrait_image_uuid', 'Event', 'portrait'],
            ['featured_image_uuid', 'Event', 'featured'],
        ],
        'organizations' => [
            ['image_uuid', 'Organization', 'avatar'],
        ],
        'users' => [
            ['profile_image_uuid', 'User', 'avatar'],
        ],
        'venues' => [
            ['image_uuid', 'Venue', 'featured'],
        ],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // ── Single-FK relationships ─────────────────────────────────────────────
        foreach ($this->singleFkMap as $table => $mappings) {
            foreach ($mappings as [$fkColumn, $shortModel, $collection]) {
                if (!Schema::hasColumn($table, $fkColumn)) {
                    continue;
                }

                $modelClass = GeneralHelper::resolveModelClass($shortModel);

                DB::table($table)
                    ->whereNotNull($fkColumn)
                    ->orderBy('uuid')
                    ->chunk(500, function ($rows) use ($fkColumn, $modelClass, $collection) {
                        foreach ($rows as $row) {
                            DB::table('uploads')
                                ->where('uuid', $row->$fkColumn)
                                ->whereNull('uploadable_type') // skip already-set rows
                                ->update([
                                    'uploadable_type' => $modelClass,
                                    'uploadable_uuid' => $row->uuid,
                                    'collection'      => $collection,
                                ]);
                        }
                    });
            }
        }

        // ── Event showcase (JSON array of upload UUIDs stored on events) ────────
        if (!Schema::hasColumn('events', 'event_showcase')) {
            return;
        }

        $eventClass = GeneralHelper::resolveModelClass('Event');

        DB::table('events')
            ->whereNotNull('event_showcase')
            ->where('event_showcase', '!=', '[]')
            ->orderBy('uuid')
            ->chunk(200, function ($events) use ($eventClass) {
                foreach ($events as $event) {
                    $uuids = json_decode($event->event_showcase, true);
                    if (!is_array($uuids) || empty($uuids)) {
                        continue;
                    }

                    foreach (array_values($uuids) as $position => $uploadUuid) {
                        DB::table('uploads')
                            ->where('uuid', $uploadUuid)
                            ->whereNull('uploadable_type')
                            ->update([
                                'uploadable_type' => $eventClass,
                                'uploadable_uuid' => $event->uuid,
                                'collection'      => 'showcase',
                                'order_number'    => $position,
                            ]);
                    }
                }
            });
    }

    /**
     * Reverse the migrations — clears the morph columns added by this backfill.
     * Source-of-truth FK columns on parent tables are untouched.
     */
    public function down(): void
    {
        DB::table('uploads')->update([
            'uploadable_type' => null,
            'uploadable_uuid' => null,
            'collection'      => null,
        ]);
    }
};
