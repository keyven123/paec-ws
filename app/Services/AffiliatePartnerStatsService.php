<?php

namespace App\Services;

use App\Constants\GeneralConstants;
use App\Models\AffiliateConversion;
use App\Models\AffiliateLinkClick;
use App\Models\AffiliatePayoutRequest;
use App\Models\User;
use Carbon\Carbon;

class AffiliatePartnerStatsService
{
    /**
     * Stored conversion commission_amount is gross (order × %).
     * Matches customer commission history: INT = 10% of gross (2 dp); net = gross − INT.
     */
    public static function netFromStoredGross(float $grossCommission): float
    {
        $intAmount = round($grossCommission * 10) / 100.0;

        return round($grossCommission - $intAmount, 2);
    }

    /**
     * @return array{all_net: float, matured_net: float}
     */
    private static function partnerNetTotals(string $partnerUserUuid, Carbon $asOfStartOfDay): array
    {
        $allNet = 0.0;
        $maturedNet = 0.0;
        foreach (AffiliateConversion::query()->where('partner_user_uuid', $partnerUserUuid)->cursor() as $row) {
            $net = self::netFromStoredGross((float) $row->commission_amount);
            $allNet += $net;
            if (AffiliateCommissionAvailabilityService::isMaturedAsOf($row->created_at, $asOfStartOfDay)) {
                $maturedNet += $net;
            }
        }

        return [
            'all_net' => round($allNet, 2),
            'matured_net' => round($maturedNet, 2),
        ];
    }

    /**
     * @return array{total_clicks: int, total_conversions: float, matured_commission_net: float, pending_earnings: float, paid_earnings: float, available_earnings: float}
     */
    public static function dashboardStatsForUser(User $user): array
    {
        $status = $user->userAffiliate?->affiliate_status ?? GeneralConstants::AFFILIATE_STATUSES['NONE'];
        $canViewAffiliateEconomics = in_array($status, [
            GeneralConstants::AFFILIATE_STATUSES['APPROVED'],
            GeneralConstants::AFFILIATE_STATUSES['SUSPENDED'],
        ], true);

        if (! $canViewAffiliateEconomics) {
            return [
                'total_clicks' => 0,
                'total_conversions' => 0.0,
                'matured_commission_net' => 0.0,
                'pending_earnings' => 0.0,
                'paid_earnings' => 0.0,
                'available_earnings' => 0.0,
            ];
        }

        $uid = $user->uuid;
        $today = Carbon::now(AffiliateCommissionAvailabilityService::timezone())->startOfDay();
        $nets = self::partnerNetTotals($uid, $today);
        $totalEarned = $nets['all_net'];
        $maturedNet = $nets['matured_net'];
        $approved = (float) AffiliatePayoutRequest::query()
            ->where('user_uuid', $uid)
            ->where('status', AffiliatePayoutRequest::STATUS_APPROVED)
            ->sum('amount_requested');
        $pending = (float) AffiliatePayoutRequest::query()
            ->where('user_uuid', $uid)
            ->where('status', AffiliatePayoutRequest::STATUS_PENDING)
            ->sum('amount_requested');

        return [
            'total_clicks' => AffiliateLinkClick::query()->where('partner_user_uuid', $uid)->count(),
            'total_conversions' => round($totalEarned, 2),
            'matured_commission_net' => round($maturedNet, 2),
            'pending_earnings' => $pending,
            'paid_earnings' => $approved,
            'available_earnings' => max(0, round($maturedNet - $approved - $pending, 2)),
        ];
    }

    public static function maturedCommissionNetForPartner(string $partnerUserUuid, Carbon $asOfStartOfDay): float
    {
        return self::partnerNetTotals($partnerUserUuid, $asOfStartOfDay)['matured_net'];
    }
}
