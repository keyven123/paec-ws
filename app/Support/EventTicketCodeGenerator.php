<?php

namespace App\Support;

use App\Models\EventTicket;
use Illuminate\Support\Str;

final class EventTicketCodeGenerator
{
    /**
     * Unique ticket code for an event (uppercase alphanumerics + hyphen suffix).
     */
    public static function generate(string $eventUuid, ?string $name = null): string
    {
        $base = 'TKT';
        if ($name !== null && trim($name) !== '') {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
            $from = $ascii !== false ? $ascii : $name;
            $slug = strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '', (string) $from));
            if ($slug !== '') {
                $base = substr($slug, 0, 24);
            }
        }

        for ($attempt = 0; $attempt < 50; $attempt++) {
            $code = $base . '-' . strtoupper(Str::random(4));
            if (strlen($code) > 255) {
                $code = substr($code, 0, 255);
            }
            $exists = EventTicket::query()
                ->where('event_uuid', $eventUuid)
                ->where('code', $code)
                ->whereNull('deleted_at')
                ->exists();
            if (! $exists) {
                return $code;
            }
        }

        $fallback = $base . '-' . strtoupper(bin2hex(random_bytes(4)));

        return strlen($fallback) > 255 ? substr($fallback, 0, 255) : $fallback;
    }
}
