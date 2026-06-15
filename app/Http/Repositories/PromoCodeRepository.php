<?php

namespace App\Http\Repositories;

use App\Exceptions\NoPromoCodeFoundException;
use App\Exceptions\UnauthorizedException;
use App\Models\Event;
use App\Models\PromoCode;
use App\Helpers\GeneralHelper;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class PromoCodeRepository
{
    /**
     * @param PromoCode $promoCode
     */
    public function __construct(protected PromoCode $promoCode)
    {
    }

    /**
     * @param array $filters
     * @return Builder
     */
    public function getAll(array $filters): Builder
    {
        $promoCode = $this->promoCode->query();
        if (!request()->user('admin')->role->is_admin) {
            $promoCode = $promoCode->where('organization_uuid', request()->user('admin')->organization_uuid);
        }
        return $promoCode->with('activityable', 'organization')
            ->filters($filters)
            ->orderBy('created_at', 'desc');
    }

    /**
     * Fetch promo code or throw exception if not found.
     *
     * @param string $key
     * @param string $value
     * @return PromoCode
     * @throws NoPromoCodeFoundException
     */
    public function fetchOrThrow(string $key, string $value): PromoCode
    {
        $promoCode = $this->promoCode->with('activityable')
            ->where($key, $value)
            ->first();

        if (is_null($promoCode)) {
            throw new NoPromoCodeFoundException();
        }

        return $promoCode;
    }

    /**
     * @param array $payload
     * @return PromoCode
     */
    public function create(array $payload): PromoCode
    {
        $payload['organization_uuid'] = $this->resolveOrganizationUuid($payload);
        $promoCodePayload = GeneralHelper::unsetUnknownAndNullFields($payload, PromoCode::DATA);
        return $this->promoCode->create($promoCodePayload);
    }

    /**
     * @param PromoCode $promoCode
     * @param array $payload
     * @return bool|PromoCode
     */
    public function update(PromoCode $promoCode, array $payload): bool|PromoCode
    {
        if (!isset($payload['organization_uuid']) || empty($payload['organization_uuid'])) {
            $payload['organization_uuid'] = $this->resolveOrganizationUuid([
                ...$payload,
                'activityable_type' => $payload['activityable_type'] ?? $promoCode->activityable_type,
                'activityable_id' => $payload['activityable_id'] ?? $promoCode->activityable_id,
            ]);
        }
        $promoCodePayload = GeneralHelper::unsetUnknownAndNullFields($payload, PromoCode::DATA);
        return $promoCode->update($promoCodePayload);
    }

    /**
     * @param PromoCode $promoCode
     * @return void
     * @throws UnauthorizedException (if needed for business logic)
     */
    public function delete(PromoCode $promoCode): void
    {
        // Add any business logic for deletion here
        // For example, prevent deletion if promo code is in use

        $promoCode->delete();
    }

    private function resolveOrganizationUuid(array $payload): string
    {
        if (!empty($payload['organization_uuid'])) {
            return $payload['organization_uuid'];
        }

        $adminOrganizationUuid = auth('admin')->user()?->organization_uuid;
        if (!empty($adminOrganizationUuid)) {
            return $adminOrganizationUuid;
        }

        $activityableId = $payload['activityable_id'] ?? null;
        if (!empty($activityableId) && $this->isEventActivityable($payload['activityable_type'] ?? null)) {
            $eventOrganizationUuid = Event::query()
                ->where('uuid', $activityableId)
                ->value('organization_uuid');

            if (!empty($eventOrganizationUuid)) {
                return $eventOrganizationUuid;
            }
        }

        throw ValidationException::withMessages([
            'organization_uuid' => ['Unable to determine organization for this promo code.'],
        ]);
    }

    private function isEventActivityable(?string $activityableType): bool
    {
        if (empty($activityableType)) {
            return true;
        }

        return in_array($activityableType, [
            Event::class,
            'App\\Models\\Event',
            'event',
        ], true);
    }
}
