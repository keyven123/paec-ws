<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Soft-deleted events kept their slug under a unique index, so recreating
     * an activity with the same name failed. Release those slugs for reuse.
     */
    public function up(): void
    {
        $events = DB::table('events')
            ->whereNotNull('deleted_at')
            ->whereNotNull('slug')
            ->where('slug', 'not like', '%--deleted-%')
            ->get(['uuid', 'slug', 'deleted_at']);

        foreach ($events as $event) {
            $timestamp = strtotime((string) $event->deleted_at) ?: time();
            $suffix = '--deleted-' . $timestamp;
            $released = Str::limit((string) $event->slug, 255 - strlen($suffix), '') . $suffix;

            DB::table('events')
                ->where('uuid', $event->uuid)
                ->update(['slug' => $released]);
        }
    }

    public function down(): void
    {
        $events = DB::table('events')
            ->whereNotNull('deleted_at')
            ->where('slug', 'like', '%--deleted-%')
            ->get(['uuid', 'slug']);

        foreach ($events as $event) {
            $original = preg_replace('/--deleted-\d+$/', '', (string) $event->slug) ?: $event->slug;

            DB::table('events')
                ->where('uuid', $event->uuid)
                ->update(['slug' => $original]);
        }
    }
};
