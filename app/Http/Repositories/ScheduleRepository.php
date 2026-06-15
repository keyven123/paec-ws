<?php

namespace App\Http\Repositories;

use App\Exceptions\NoScheduleFoundException;
use App\Exceptions\UnauthorizedException;
use App\Models\Schedule;
use App\Helpers\GeneralHelper;
use Illuminate\Contracts\Database\Eloquent\Builder;

class ScheduleRepository
{
    /**
     * @param Schedule $schedule
     */
    public function __construct(protected Schedule $schedule)
    {
    }

    /**
     * @param array $filters
     * @return Builder
     */
    public function getAll(array $filters): Builder
    {
        return $this->schedule->with(['event', 'creator', 'updater', 'scheduleTimes'])
            ->filters($filters)
            ->orderBy('date_from', 'asc');
    }

    /**
     * Fetch schedule or throw exception if not found.
     *
     * @param string $key
     * @param string $value
     * @return Schedule
     * @throws NoScheduleFoundException
     */
    public function fetchOrThrow(string $key, string $value): Schedule
    {
        $schedule = $this->schedule->with(['event', 'creator', 'updater'])
            ->where($key, $value)->first();

        if (is_null($schedule)) {
            throw new NoScheduleFoundException();
        }

        return $schedule;
    }

    /**
     * @param array $payload
     * @return Schedule
     */
    public function create(array $payload): Schedule
    {
        $schedulePayload = GeneralHelper::unsetUnknownAndNullFields($payload, Schedule::DATA);
        return $this->schedule->create($schedulePayload);
    }

    /**
     * @param Schedule $schedule
     * @param array $payload
     * @return bool|Schedule
     */
    public function update(Schedule $schedule, array $payload): bool|Schedule
    {
        $schedulePayload = GeneralHelper::unsetUnknownAndNullFields($payload, Schedule::DATA);
        return $schedule->update($schedulePayload);
    }

    /**
     * @param Schedule $schedule
     * @return void
     * @throws UnauthorizedException
     */
    public function delete(Schedule $schedule): void
    {
        if ($schedule->transactions()->exists()) {
            throw new UnauthorizedException('Cannot delete schedule with associated transactions.');
        }

        $schedule->delete();
    }

    public function getEventScheduleByEventUuid(string $uuid): Builder
    {
        return $this->schedule->with(['scheduleTimes'])
            ->where('event_uuid', $uuid)
            ->published()
            ->orderBy('date_from', 'asc');
    }
}
