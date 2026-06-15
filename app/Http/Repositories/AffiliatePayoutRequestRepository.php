<?php

namespace App\Http\Repositories;

use App\Models\AffiliatePayoutRequest;
use Illuminate\Contracts\Database\Eloquent\Builder;

class AffiliatePayoutRequestRepository
{
    public function __construct(protected AffiliatePayoutRequest $affiliatePayoutRequest)
    {
    }

    public function getAll(array $filters): Builder
    {
        $query = $this->affiliatePayoutRequest->with(['user.userAffiliate'])
            ->orderByDesc('created_at');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query;
    }

    public function fetchOrThrow(string $key, string $value): AffiliatePayoutRequest
    {
        $record = $this->affiliatePayoutRequest->where($key, $value)->first();

        if (is_null($record)) {
            abort(404, 'Affiliate payout request not found.');
        }

        return $record;
    }

    public function create(array $payload): AffiliatePayoutRequest
    {
        return $this->affiliatePayoutRequest->create($payload);
    }

    public function update(AffiliatePayoutRequest $record, array $payload): bool
    {
        return $record->update($payload);
    }

    public function getByUser(string $userUuid, int $perPage = 15): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return $this->affiliatePayoutRequest
            ->where('user_uuid', $userUuid)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function hasPending(string $userUuid): bool
    {
        return $this->affiliatePayoutRequest
            ->where('user_uuid', $userUuid)
            ->where('status', AffiliatePayoutRequest::STATUS_PENDING)
            ->exists();
    }
}
