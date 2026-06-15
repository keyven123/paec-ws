<?php

namespace App\Http\Repositories;

use App\Models\AffiliateConversion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class AffiliateConversionRepository
{
    public function __construct(protected AffiliateConversion $affiliateConversion)
    {
    }

    public function getByUser(string $userUuid, int $perPage = 15): LengthAwarePaginator
    {
        return $this->affiliateConversion
            ->with(['event', 'transaction', 'ticket'])
            ->where('partner_user_uuid', $userUuid)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function getByUserForPage(string $userUuid, int $perPage, int $page, string $pageName = 'conversions_page'): LengthAwarePaginator
    {
        $page = max(1, $page);

        return $this->affiliateConversion
            ->with(['event', 'transaction', 'ticket'])
            ->where('partner_user_uuid', $userUuid)
            ->orderByDesc('created_at')
            ->paginate($perPage, ['*'], $pageName, $page);
    }
}
