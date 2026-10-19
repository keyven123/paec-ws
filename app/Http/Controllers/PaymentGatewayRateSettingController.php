<?php

namespace App\Http\Controllers;

use App\Constants\GeneralConstants;
use App\Http\Repositories\PaymentGatewayRateSettingRepository;
use App\Http\Requests\Admin\UpdatePaymentGatewayRateSettingRequest;
use App\Models\Dataset;
use App\Models\PaymentGateway;
use App\Support\PaymentChannelFee;
use Illuminate\Http\JsonResponse;

class PaymentGatewayRateSettingController extends Controller
{
    public function __construct(
        protected PaymentGatewayRateSettingRepository $paymentGatewayRateSettingRepository
    ) {
    }

    public function show(): JsonResponse
    {
        $this->paymentGatewayRateSettingRepository->getPaymongoDataset();
        $this->paymentGatewayRateSettingRepository->getPaypalDataset();
        $this->paymentGatewayRateSettingRepository->getPaynamicsDataset();
        $this->paymentGatewayRateSettingRepository->getVisibilityDataset();

        return response()->json([
            'data' => $this->buildApiData(),
        ]);
    }

    public function update(UpdatePaymentGatewayRateSettingRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $visibility = [];

        $paymongoRates = [];
        foreach (self::FRONTEND_TO_STORAGE as $frontendKey => $storageKey) {
            if (! array_key_exists($frontendKey, $validated['paymongo'] ?? [])) {
                continue;
            }
            $v = $validated['paymongo'][$frontendKey];
            if (is_array($v)) {
                $fee = PaymentChannelFee::normalize($v, PaymentChannelFee::MODE_PERCENTAGE);
                $paymongoRates[$storageKey] = $fee['percentage'];
                $visibility['paymongo.'.$frontendKey] = $fee['show_in_sales'];
                continue;
            }
            $paymongoRates[$storageKey] = $v !== null ? (float) $v : null;
        }

        if (array_key_exists('dob', $validated['paymongo'] ?? []) && is_array($validated['paymongo']['dob'])) {
            $dob = $validated['paymongo']['dob'];
            if (array_key_exists('fee_mode', $dob) || array_key_exists('fixed_fee', $dob) || array_key_exists('show_in_sales', $dob)) {
                $fee = PaymentChannelFee::normalize($dob, PaymentChannelFee::MODE_HIGHER_OF);
                $paymongoRates['dob'] = $fee['percentage'];
                $paymongoRates['dob_fixed_minimum'] = $fee['fixed_fee'];
                $visibility['paymongo.dob'] = $fee['show_in_sales'];
            } else {
                $paymongoRates['dob'] = isset($dob['percentage']) && $dob['percentage'] !== null
                    ? (float) $dob['percentage']
                    : null;
                $paymongoRates['dob_fixed_minimum'] = isset($dob['fixed_minimum']) && $dob['fixed_minimum'] !== null
                    ? (float) $dob['fixed_minimum']
                    : null;
            }
        }

        $paypalRates = [];
        foreach (PaymentGatewayRateSettingRepository::PAYPAL_FIELDS as $field) {
            if (array_key_exists($field, $validated['paypal'] ?? [])) {
                $v = $validated['paypal'][$field];
                $paypalRates[$field] = $v !== null ? (float) $v : null;
            }
        }
        if (isset($validated['paypal']['show_in_sales'])) {
            $visibility['paypal.default'] = (bool) $validated['paypal']['show_in_sales'];
        }

        $this->paymentGatewayRateSettingRepository->updatePaymongoRates($paymongoRates);
        $this->paymentGatewayRateSettingRepository->updatePaypalRates($paypalRates);

        if (isset($validated['paynamics']) && is_array($validated['paynamics'])) {
            foreach ($validated['paynamics'] as $channel => $fee) {
                if (is_array($fee) && array_key_exists('show_in_sales', $fee)) {
                    $visibility['paynamics.'.$channel] = (bool) $fee['show_in_sales'];
                }
            }
            $this->paymentGatewayRateSettingRepository->updatePaynamicsRates($validated['paynamics']);
        }

        if (isset($validated['sales_visibility']) && is_array($validated['sales_visibility'])) {
            foreach ($validated['sales_visibility'] as $key => $value) {
                $visibility[(string) $key] = (bool) $value;
            }
        }

        if ($visibility !== []) {
            $this->paymentGatewayRateSettingRepository->updateSalesVisibility($visibility);
        }

        return response()->json([
            'data' => $this->buildApiData(),
        ]);
    }

    /**
     * Frontend key → storage key for simple Paymongo methods.
     * DOB is handled separately as a nested object.
     *
     * @var array<string, string>
     */
    private const FRONTEND_TO_STORAGE = [
        'qrph' => 'qrph',
        'card' => 'card',
        'gcash' => 'gcash',
        'grab_pay' => 'grab_pay',
        'shopee_pay' => 'shopee_pay',
        'billease' => 'billease',
        'paymaya' => 'paymaya',
        'brankas' => 'brankas',
    ];

    /**
     * @return array<string, mixed>
     */
    private function buildApiData(): array
    {
        $paymongoDataset = $this->paymentGatewayRateSettingRepository->getPaymongoDataset();
        $paypalDataset = $this->paymentGatewayRateSettingRepository->getPaypalDataset();
        $paynamicsDataset = $this->paymentGatewayRateSettingRepository->getPaynamicsDataset();

        $paymongoRates = Dataset::paymongoRates();
        $paypalRates = Dataset::paypalRates();
        $paynamicsRates = Dataset::paynamicsRates();

        $repo = $this->paymentGatewayRateSettingRepository;

        $paymongoChannels = [];
        foreach (self::FRONTEND_TO_STORAGE as $frontendKey => $storageKey) {
            $paymongoChannels[$frontendKey] = [
                'fee_mode' => PaymentChannelFee::MODE_PERCENTAGE,
                'percentage' => isset($paymongoRates[$storageKey]) ? (float) $paymongoRates[$storageKey] : null,
                'fixed_fee' => null,
                'show_in_sales' => $repo->isChannelVisibleInSales('paymongo', $frontendKey),
            ];
        }
        $paymongoChannels['dob'] = [
            'fee_mode' => PaymentChannelFee::MODE_HIGHER_OF,
            'percentage' => isset($paymongoRates['dob']) ? (float) $paymongoRates['dob'] : null,
            'fixed_fee' => isset($paymongoRates['dob_fixed_minimum'])
                ? (float) $paymongoRates['dob_fixed_minimum']
                : null,
            // Legacy nested keys kept for older clients / tests.
            'fixed_minimum' => isset($paymongoRates['dob_fixed_minimum'])
                ? (float) $paymongoRates['dob_fixed_minimum']
                : null,
            'show_in_sales' => $repo->isChannelVisibleInSales('paymongo', 'dob'),
        ];

        // Ensure paynamics rates carry show_in_sales from visibility map when present.
        foreach ($paynamicsRates as $channel => $fee) {
            $paynamicsRates[$channel]['show_in_sales'] = $repo->isChannelVisibleInSales('paynamics', (string) $channel);
        }

        // Flat legacy paymongo keys for existing clients.
        $paymongoFlat = [];
        foreach (self::FRONTEND_TO_STORAGE as $frontendKey => $storageKey) {
            $paymongoFlat[$frontendKey] = isset($paymongoRates[$storageKey])
                ? (float) $paymongoRates[$storageKey]
                : null;
        }
        $paymongoFlat['dob'] = [
            'percentage' => $paymongoChannels['dob']['percentage'],
            'fixed_minimum' => $paymongoChannels['dob']['fixed_fee'],
        ];

        $gateways = PaymentGateway::query()
            ->where('status', GeneralConstants::GENERAL_STATUSES['ACTIVE'])
            ->orderBy('provider')
            ->orderBy('name')
            ->get(['uuid', 'name', 'provider', 'environment', 'status', 'payment_channels'])
            ->map(function (PaymentGateway $gateway) {
                return [
                    'uuid' => $gateway->uuid,
                    'name' => $gateway->name,
                    'provider' => $gateway->provider,
                    'environment' => $gateway->environment,
                    'status' => $gateway->status,
                    'channels' => $gateway->provider === 'paynamics'
                        ? $gateway->enabledPaynamicsChannels()
                        : null,
                ];
            })
            ->values()
            ->all();

        return [
            'gateways' => $gateways,
            'channel_catalog' => [
                'paynamics' => $this->paymentGatewayRateSettingRepository->paynamicsChannelCatalog(),
                'paymongo' => $this->paymentGatewayRateSettingRepository->paymongoChannelCatalog(),
                'paypal' => [
                    ['key' => 'default', 'label' => 'PayPal', 'group' => 'PayPal'],
                ],
            ],
            'channels' => [
                'paymongo' => $paymongoChannels,
                'paypal' => [
                    'default' => [
                        'fee_mode' => PaymentChannelFee::MODE_PERCENTAGE_PLUS_FIXED,
                        'percentage' => isset($paypalRates['paypal_fee'])
                            ? (float) $paypalRates['paypal_fee']
                            : null,
                        'fixed_fee' => isset($paypalRates['additional_fee'])
                            ? (float) $paypalRates['additional_fee']
                            : null,
                        'show_in_sales' => $repo->isChannelVisibleInSales('paypal', 'default'),
                    ],
                ],
                'paynamics' => $paynamicsRates,
            ],
            // Legacy shape (flat rates) for existing integrations / tests.
            'paymongo' => $paymongoFlat,
            'paypal' => [
                'paypal_fee' => isset($paypalRates['paypal_fee'])
                    ? (float) $paypalRates['paypal_fee']
                    : null,
                'additional_fee' => isset($paypalRates['additional_fee'])
                    ? (float) $paypalRates['additional_fee']
                    : null,
            ],
            'paynamics' => $paynamicsRates,
            'updated_at' => collect([
                $paymongoDataset->updated_at,
                $paypalDataset->updated_at,
                $paynamicsDataset->updated_at,
            ])->filter()->max()?->toISOString(),
            'paymongo_updated_at' => $paymongoDataset->updated_at?->toISOString(),
            'paypal_updated_at' => $paypalDataset->updated_at?->toISOString(),
            'paynamics_updated_at' => $paynamicsDataset->updated_at?->toISOString(),
        ];
    }
}
