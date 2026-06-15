<?php

namespace App\Http\Repositories;

use App\Constants\GeneralConstants;
use App\Exceptions\NoResourceFoundException;
use App\Models\ActivityCompliance;
use App\Models\Event;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Services\ActivityComplianceService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class ActivityComplianceRepository
{
    public function __construct(protected ActivityCompliance $activityCompliance)
    {
    }

    /**
     * @return Collection<int, Event>
     */
    public function eventsWithCompliancesForOrganization(string $organizationUuid): Collection
    {
        return Event::query()
            ->where('organization_uuid', $organizationUuid)
            ->with(['activityCompliances' => function ($query) {
                $query->orderBy('label');
            }])
            ->orderBy('event_name')
            ->get(['uuid', 'event_name', 'status', 'organization_uuid']);
    }

    /**
     * @param  array{
     *   label: string,
     *   percentage?: float|int|string|null,
     *   fixed_amount?: float|int|string|null,
     *   amount_type: string,
     *   status?: string
     * }  $payload
     *
     * @throws ValidationException
     */
    public function createForEvent(Event $event, array $payload): ActivityCompliance
    {
        $status = $payload['status'] ?? GeneralConstants::GENERAL_STATUSES['INACTIVE'];

        $compliance = new ActivityCompliance([
            'activityable_type' => 'event',
            'activityable_id' => $event->uuid,
            'label' => $payload['label'],
            'percentage' => $payload['percentage'] ?? 0,
            'fixed_amount' => $payload['fixed_amount'] ?? null,
            'amount_type' => $payload['amount_type'],
            'status' => $status,
        ]);

        if ($compliance->amount_type === ActivityCompliance::AMOUNT_TYPE['PERCENTAGE']) {
            ActivityComplianceService::assertCandidateActivePercentageTotal($event, $compliance);
        }

        $compliance->save();

        return $compliance->fresh();
    }

    /**
     * @throws NoResourceFoundException
     */
    public function fetchEventForOrganizationOrThrow(string $eventUuid, string $organizationUuid): Event
    {
        try {
            return Event::query()
                ->where('uuid', $eventUuid)
                ->where('organization_uuid', $organizationUuid)
                ->firstOrFail();
        } catch (ModelNotFoundException) {
            throw new NoResourceFoundException('Event not found for this organization');
        }
    }

    /**
     * @throws NoResourceFoundException
     */
    public function fetchOrThrow(string $uuid): ActivityCompliance
    {
        $compliance = $this->activityCompliance->newQuery()->find($uuid);

        if ($compliance === null) {
            throw new NoResourceFoundException('Activity compliance not found');
        }

        return $compliance;
    }

    /**
     * @param  array{label?: string, status?: string, percentage?: float|int|string, fixed_amount?: float|int|string|null, amount_type?: string}  $payload
     *
     * @throws ValidationException
     */
    public function update(ActivityCompliance $compliance, array $payload): ActivityCompliance
    {
        $compliance->fill($payload);

        $event = $this->resolveEvent($compliance);
        if ($event !== null && $compliance->amount_type === ActivityCompliance::AMOUNT_TYPE['PERCENTAGE']) {
            ActivityComplianceService::assertCandidateActivePercentageTotal($event, $compliance);
        }

        $compliance->save();

        return $compliance->fresh();
    }

    /**
     * @throws ValidationException
     */
    public function delete(ActivityCompliance $compliance): void
    {
        if ($compliance->transactionCompliances()->exists()) {
            throw ValidationException::withMessages([
                'activity_compliance' => [
                    'This compliance rule cannot be deleted because it is linked to completed transactions.',
                ],
            ]);
        }

        $deleted = ActivityCompliance::query()
            ->where('uuid', $compliance->uuid)
            ->delete();

        if ($deleted === 0) {
            throw ValidationException::withMessages([
                'activity_compliance' => ['Unable to delete this compliance rule.'],
            ]);
        }
    }

    private function resolveEvent(ActivityCompliance $compliance): ?Event
    {
        if ($compliance->activityable_type !== 'event') {
            return null;
        }

        return Event::query()->find($compliance->activityable_id);
    }
}
