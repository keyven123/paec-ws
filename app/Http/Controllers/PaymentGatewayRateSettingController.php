<?php

namespace App\Http\Controllers;

use App\Http\Repositories\PaymentGatewayRateSettingRepository;
use App\Http\Requests\Admin\UpdatePaymentGatewayRateSettingRequest;
use App\Models\Dataset;
use Illuminate\Http\JsonResponse;

class PaymentGatewayRateSettingController extends Controller
{
    public function __construct(
        protected PaymentGatewayRateSettingRepository $paymentGatewayRateSettingRepository
    ) {
    }

    public function show(): JsonResponse
    {
        $paymongoDataset = $this->paymentGatewayRateSettingRepository->getPaymongoDataset();
        $paypalDataset = $this->paymentGatewayRateSettingRepository->getPaypalDataset();

        return response()->json([
            'data' => $this->buildApiData($paymongoDataset, $paypalDataset),
        ]);
    }

    public function update(UpdatePaymentGatewayRateSettingRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $paymongoRates = [];
        foreach (self::FRONTEND_TO_STORAGE as $frontendKey => $storageKey) {
            if (array_key_exists($frontendKey, $validated['paymongo'])) {
                $v = $validated['paymongo'][$frontendKey];
                $paymongoRates[$storageKey] = $v !== null ? (float) $v : null;
            }
        }

        if (array_key_exists('dob', $validated['paymongo']) && is_array($validated['paymongo']['dob'])) {
            $dob = $validated['paymongo']['dob'];
            $paymongoRates['dob']             = isset($dob['percentage'])    && $dob['percentage']    !== null ? (float) $dob['percentage']    : null;
            $paymongoRates['dob_fixed_minimum'] = isset($dob['fixed_minimum']) && $dob['fixed_minimum'] !== null ? (float) $dob['fixed_minimum'] : null;
        }

        $paypalRates = [];
        foreach (PaymentGatewayRateSettingRepository::PAYPAL_FIELDS as $field) {
            if (array_key_exists($field, $validated['paypal'])) {
                $v = $validated['paypal'][$field];
                $paypalRates[$field] = $v !== null ? (float) $v : null;
            }
        }

        $paymongoDataset = $this->paymentGatewayRateSettingRepository->updatePaymongoRates($paymongoRates);
        $paypalDataset = $this->paymentGatewayRateSettingRepository->updatePaypalRates($paypalRates);

        return response()->json([
            'data' => $this->buildApiData($paymongoDataset, $paypalDataset),
        ]);
    }

    /**
     * Frontend key → storage key for simple Paymongo methods.
     * DOB is handled separately as a nested object.
     *
     * @var array<string, string>
     */
    private const FRONTEND_TO_STORAGE = [
        'qrph'       => 'qrph',
        'card'       => 'card',
        'gcash'      => 'gcash',
        'grab_pay'   => 'grab_pay',
        'shopee_pay' => 'shopee_pay',
        'billease'   => 'billease',
        'paymaya'    => 'paymaya',
        'brankas'    => 'brankas',
    ];

    /**
     * @return array<string, mixed>
     */
    private function buildApiData(
        \App\Models\Dataset $paymongoDataset,
        \App\Models\Dataset $paypalDataset
    ): array {
        $paymongoRates = Dataset::paymongoRates();
        $paypalRates   = Dataset::paypalRates();

        // Build response using frontend keys; DOB is returned as a nested object
        $paymongo = [];
        foreach (self::FRONTEND_TO_STORAGE as $frontendKey => $storageKey) {
            $paymongo[$frontendKey] = isset($paymongoRates[$storageKey]) ? (float) $paymongoRates[$storageKey] : null;
        }
        $paymongo['dob'] = [
            'percentage'    => isset($paymongoRates['dob'])               ? (float) $paymongoRates['dob']               : null,
            'fixed_minimum' => isset($paymongoRates['dob_fixed_minimum']) ? (float) $paymongoRates['dob_fixed_minimum'] : null,
        ];

        return [
            'paymongo'            => $paymongo,
            'paypal'              => [
                'paypal_fee'     => isset($paypalRates['paypal_fee'])     ? (float) $paypalRates['paypal_fee']     : null,
                'additional_fee' => isset($paypalRates['additional_fee']) ? (float) $paypalRates['additional_fee'] : null,
            ],
            'updated_at'          => $paymongoDataset->updated_at?->toISOString(),
            'paymongo_updated_at' => $paymongoDataset->updated_at?->toISOString(),
            'paypal_updated_at'   => $paypalDataset->updated_at?->toISOString(),
        ];
    }
}
