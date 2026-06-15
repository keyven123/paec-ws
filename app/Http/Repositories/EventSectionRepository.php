<?php

namespace App\Http\Repositories;

use App\Exceptions\NoEventSectionFoundException;
use App\Exceptions\UnauthorizedException;
use App\Models\EventSection;
use App\Helpers\GeneralHelper;
use Illuminate\Contracts\Database\Eloquent\Builder;

class EventSectionRepository
{
    /**
     * @param EventSection $eventSection
     */
    public function __construct(protected EventSection $eventSection)
    {
    }

    /**
     * @param array $filters
     * @return Builder
     */
    public function getAll(array $filters): Builder
    {
        $eventSection = $this->eventSection->filters($filters);

        if (!request()->user('admin')->role->is_admin) {
            $eventSection->where('name', '!=', EventSection::FEATURED_SECTION);
        }

        $eventSection->where('is_hidden', false);

        return $eventSection
            ->orderBy('created_at', 'desc');
    }

    /**
     * Fetch event section or throw exception if not found.
     *
     * @param string $key
     * @param string $value
     * @return EventSection
     * @throws NoEventSectionFoundException
     */
    public function fetchOrThrow(string $key, string $value): EventSection
    {
        $eventSection = $this->eventSection->where($key, $value)->first();

        if (is_null($eventSection)) {
            throw new NoEventSectionFoundException();
        }

        return $eventSection;
    }

    /**
     * @param array $payload
     * @return EventSection
     */
    public function create(array $payload): EventSection
    {
        $eventSectionPayload = GeneralHelper::unsetUnknownAndNullFields($payload, EventSection::DATA);
        return $this->eventSection->create($eventSectionPayload);
    }

    /**
     * @param EventSection $eventSection
     * @param array $payload
     * @return bool|EventSection
     */
    public function update(EventSection $eventSection, array $payload): bool|EventSection
    {
        $eventSectionPayload = GeneralHelper::unsetUnknownAndNullFields($payload, EventSection::DATA);
        return $eventSection->update($eventSectionPayload);
    }

    /**
     * @param EventSection $eventSection
     * @return void
     * @throws UnauthorizedException
     */
    public function delete(EventSection $eventSection): void
    {
        // Add any business logic for deletion here
        // For example, prevent deletion if event section is in use
        if ($eventSection->events()->count() > 0) {
            throw new UnauthorizedException('Cannot delete event section that is being used by events.');
        }
        
        $eventSection->delete();
    }
}
