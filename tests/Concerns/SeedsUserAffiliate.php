<?php

namespace Tests\Concerns;

use App\Constants\GeneralConstants;
use App\Models\User;
use DateTimeInterface;

trait SeedsUserAffiliate
{
    /**
     * Affiliate data lives on user_affiliates; tests must seed this row for approved partners.
     */
    protected function seedApprovedAffiliate(
        User $user,
        string $code,
        ?DateTimeInterface $appliedAt = null,
        ?DateTimeInterface $approvedAt = null,
    ): void {
        $user->userAffiliate()->updateOrCreate(
            ['user_uuid' => $user->uuid],
            [
                'affiliate_status' => GeneralConstants::AFFILIATE_STATUSES['APPROVED'],
                'affiliate_code' => $code,
                'affiliate_applied_at' => $appliedAt ?? now(),
                'affiliate_approved_at' => $approvedAt ?? now(),
            ]
        );
    }
}
