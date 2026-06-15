<?php

namespace App\Services;

use App\Models\AffiliateConversion;
use App\Models\AffiliateLinkClick;
use App\Models\Event;
use App\Models\Transaction;
use App\Constants\GeneralConstants;
use App\Models\User;
use App\Models\UserAffiliate;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AffiliateAttributionService
{
    /**
     * Whether affiliate sales are allowed for this event at the given instant (inclusive through end of the end date in app TZ).
     */
    public static function isAffiliateProgramActiveAt(Event $event, \DateTimeInterface $at): bool
    {
        if (!$event->affiliate_enabled) {
            return false;
        }

        if ($event->affiliate_ends_at === null) {
            return true;
        }

        $tz = (string) config('app.timezone', 'UTC');
        $instant = Carbon::parse($at)->timezone($tz);
        $end = Carbon::parse($event->affiliate_ends_at)->timezone($tz)->endOfDay();

        return ! $instant->gt($end);
    }

    public static function resolvePartnerUuid(?string $code, User $buyer, Event $event): ?string
    {
        if (!$event->affiliate_enabled) {
            return null;
        }

        if (! self::isAffiliateProgramActiveAt($event, Carbon::now())) {
            return null;
        }

        $normalized = $code !== null ? strtoupper(trim($code)) : '';
        if ($normalized === '') {
            return null;
        }

        $userAffiliate = UserAffiliate::query()
            ->where('affiliate_code', $normalized)
            ->where('affiliate_status', GeneralConstants::AFFILIATE_STATUSES['APPROVED'])
            ->first();

        if (!$userAffiliate || $userAffiliate->user_uuid === $buyer->uuid) {
            return null;
        }

        return $userAffiliate->user_uuid;
    }

    public static function recordClick(string $refCode, ?string $path, ?string $ip, ?string $userAgent): bool
    {
        $normalized = strtoupper(trim($refCode));
        if ($normalized === '') {
            return false;
        }

        $partner = User::query()
            ->whereHas('userAffiliate', function ($q) use ($normalized) {
                $q->where('affiliate_code', $normalized)
                    ->where('affiliate_status', GeneralConstants::AFFILIATE_STATUSES['APPROVED']);
            })
            ->first();

        if (!$partner) {
            return false;
        }

        AffiliateLinkClick::create([
            'partner_user_uuid' => $partner->uuid,
            'ref_code' => $normalized,
            'path' => $path ? mb_substr($path, 0, 512) : null,
            'ip_address' => $ip ? mb_substr($ip, 0, 45) : null,
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 512) : null,
        ]);

        return true;
    }

    public static function recordConversionFromPaidTransaction(Transaction $transaction): void
    {
        if ($transaction->payment_status !== Transaction::PAYMENT_STATUS['PAID']) {
            return;
        }

        $partnerUuid = $transaction->affiliate_partner_uuid;
        if (!$partnerUuid) {
            return;
        }

        $event = $transaction->event;
        if (!$event || !$event->affiliate_enabled) {
            return;
        }

        $paidAt = $transaction->paid_at ?? $transaction->updated_at ?? Carbon::now();
        if (! self::isAffiliateProgramActiveAt($event, $paidAt)) {
            return;
        }

        $percent = $event->affiliate_commission_percent;
        if ($percent === null || (float) $percent <= 0) {
            return;
        }

        if ($transaction->user_uuid === $partnerUuid) {
            return;
        }

        $partner = User::query()
            ->where('uuid', $partnerUuid)
            ->whereHas('userAffiliate', function ($q) {
                $q->where('affiliate_status', GeneralConstants::AFFILIATE_STATUSES['APPROVED']);
            })
            ->first();

        if (!$partner) {
            return;
        }

        $transaction->loadMissing(['event', 'transactionOrders.eventTicket', 'affiliateConversion']);

        $base = TicketPurchasePricingService::transactionNetSellingTotal($transaction);
        if ($base <= 0) {
            return;
        }

        $pct = (float) $percent;
        $commission = round($base * ($pct / 100), 2);

        if ($commission <= 0) {
            return;
        }

        try {
            AffiliateConversion::firstOrCreate(
                [
                    'transaction_uuid' => $transaction->uuid,
                    'entry_type' => AffiliateConversion::ENTRY_TYPE_CREDIT,
                ],
                [
                    'partner_user_uuid' => $partner->uuid,
                    'event_uuid' => $transaction->event_uuid,
                    'order_total' => $base,
                    'commission_percent' => $pct,
                    'commission_amount' => $commission,
                ]
            );
        } catch (\Throwable $e) {
            Log::error('Affiliate conversion recording failed', [
                'transaction_uuid' => $transaction->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
