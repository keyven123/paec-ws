<?php

namespace App\Http\Repositories;

use App\Exceptions\NoScheduleTimeFoundException;
use App\Exceptions\UnauthorizedException;
use App\Models\ScheduleTime;
use App\Helpers\GeneralHelper;
use App\Models\EventTicket;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ScheduleTimeRepository
{
    /**
     * @param ScheduleTime $scheduleTime
     */
    public function __construct(protected ScheduleTime $scheduleTime)
    {
    }

    /**
     * @param array $filters
     * @return Builder
     */
    public function getAll(array $filters): Builder
    {
        return $this->scheduleTime->with(['schedule.event', 'creator', 'updater'])
            ->filters($filters)
            ->orderBy('time_start', 'asc');
    }

    /**
     * Fetch schedule time or throw exception if not found.
     *
     * @param string $key
     * @param string $value
     * @return ScheduleTime
     * @throws NoScheduleTimeFoundException
     */
    public function fetchOrThrow(string $key, string $value): ScheduleTime
    {
        $scheduleTime = $this->scheduleTime->with(['schedule.event', 'creator', 'updater', 'eventTickets'])
            ->where($key, $value)->first();

        if (is_null($scheduleTime)) {
            throw new NoScheduleTimeFoundException();
        }

        return $scheduleTime;
    }

    /**
     * @param array $payload
     * @return ScheduleTime
     */
    public function create(array $payload): ScheduleTime
    {
        DB::beginTransaction();
        $scheduleTimePayload = GeneralHelper::unsetUnknownAndNullFields($payload, ScheduleTime::DATA);
        $scheduleTime = $this->scheduleTime->create($scheduleTimePayload);
        if (!empty($payload['inherit_event_tickets'])) {
            $eventTickets = EventTicket::whereIn('uuid', $payload['event_ticket_uuids'])
                ->where('schedule_uuid', $payload['schedule_uuid'])
                ->whereNull('deleted_at')
                ->get();
            foreach ($eventTickets as $eventTicket) {
                $newEventTicket = $eventTicket->replicate();
                $newEventTicket->schedule_time_uuid = $scheduleTime->uuid;
                $newEventTicket->save();
            }
        }
        DB::commit();
        return $scheduleTime;
    }

    /**
     * @param ScheduleTime $scheduleTime
     * @param array $payload
     * @return bool|ScheduleTime
     */
    public function update(ScheduleTime $scheduleTime, array $payload): bool|ScheduleTime
    {
        $scheduleTimePayload = GeneralHelper::unsetUnknownAndNullFields($payload, ScheduleTime::DATA);
        return $scheduleTime->update($scheduleTimePayload);
    }

    /**
     * @param ScheduleTime $scheduleTime
     * @return void
     * @throws UnauthorizedException
     */
    public function delete(ScheduleTime $scheduleTime): void
    {
        // Check if schedule time has associated event tickets
        if ($scheduleTime->transactions()->exists()) {
            throw new UnauthorizedException('Cannot delete schedule time with associated transactions.');
        }

        $scheduleTime->eventTickets()->delete();
        $scheduleTime->delete();
    }
}
