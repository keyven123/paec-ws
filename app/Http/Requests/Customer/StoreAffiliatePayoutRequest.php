<?php

namespace App\Http\Requests\Customer;

use App\Models\AffiliatePayoutRequest;
use App\Constants\GeneralConstants;
use App\Models\User;
use App\Services\AffiliatePartnerStatsService;
use Illuminate\Foundation\Http\FormRequest;

class StoreAffiliatePayoutRequest extends FormRequest
{
    public const MIN_PAYOUT_PHP = 1000.0;

    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:' . self::MIN_PAYOUT_PHP, 'max:999999.99'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.min' => 'Minimum payout request is PHP ' . number_format(self::MIN_PAYOUT_PHP, 2) . '.',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            /** @var User|null $user */
            $user = $this->user();
            $affiliateStatus = $user?->userAffiliate?->affiliate_status ?? GeneralConstants::AFFILIATE_STATUSES['NONE'];
            if (!$user || $affiliateStatus !== GeneralConstants::AFFILIATE_STATUSES['APPROVED']) {
                return;
            }

            $amount = round((float) $this->input('amount'), 2);
            $stats = AffiliatePartnerStatsService::dashboardStatsForUser($user);
            $available = (float) $stats['available_earnings'];

            if ($available + 0.009 < self::MIN_PAYOUT_PHP) {
                $validator->errors()->add(
                    'amount',
                    'You need at least PHP ' . number_format(self::MIN_PAYOUT_PHP, 2)
                    . ' in available commission to request a payout (currently PHP '
                    . number_format(max(0, $available), 2) . ').'
                );

                return;
            }

            if ($amount > $available + 0.009) {
                $validator->errors()->add(
                    'amount',
                    'Amount exceeds available commission (PHP ' . number_format(max(0, $available), 2) . ').'
                );
            }
        });
    }
}
