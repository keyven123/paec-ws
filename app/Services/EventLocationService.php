<?php

namespace App\Services;

use App\Models\Event;
use App\Models\EventLocation;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class EventLocationService
{
    public static function activeLocationsForEvent(string $eventUuid): Collection
    {
        return EventLocation::query()
            ->where('event_uuid', $eventUuid)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();
    }

    public static function resolveForCheckout(Event $event, ?string $eventLocationUuid): EventLocation
    {
        $locations = self::activeLocationsForEvent($event->uuid);

        if ($locations->isEmpty()) {
            throw ValidationException::withMessages([
                'event_location_uuid' => 'No active locations are configured for this activity.',
            ]);
        }

        if ($eventLocationUuid) {
            $location = $locations->firstWhere('uuid', $eventLocationUuid);
            if (! $location) {
                throw ValidationException::withMessages([
                    'event_location_uuid' => 'Selected location is not valid for this activity.',
                ]);
            }

            return $location;
        }

        if ($locations->count() === 1) {
            return $locations->first();
        }

        throw ValidationException::withMessages([
            'event_location_uuid' => 'Please select a location for this activity.',
        ]);
    }

    public static function resolveOrganizationUuid(EventLocation $location, Event $event): ?string
    {
        return $location->organization_uuid ?? $event->organization_uuid;
    }

    public static function ensureDefaultLocation(Event $event): EventLocation
    {
        $existing = EventLocation::query()
            ->where('event_uuid', $event->uuid)
            ->orderBy('sort_order')
            ->first();

        if ($existing) {
            return $existing;
        }

        return EventLocation::create([
            'event_uuid' => $event->uuid,
            'city' => $event->city ?: 'Metro Manila',
            'address' => $event->address,
            'organization_uuid' => $event->organization_uuid,
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }
}
