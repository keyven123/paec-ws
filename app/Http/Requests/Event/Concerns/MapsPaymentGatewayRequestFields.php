<?php

namespace App\Http\Requests\Event\Concerns;

use App\Models\PaymentGateway;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Handles `payment_gateway_uuids` on event create/update.
 *
 * The admin wizard posts multipart form data, which cannot carry an empty array, so the list is
 * sent as a JSON string. When the field is absent the event's gateways are left unchanged.
 */
trait MapsPaymentGatewayRequestFields
{
    protected function preparePaymentGatewayFieldsForValidation(): void
    {
        $value = $this->input('payment_gateway_uuids');
        if (! is_string($value)) {
            return;
        }

        $decoded = json_decode($value, true);
        $this->merge([
            'payment_gateway_uuids' => is_array($decoded) ? array_values($decoded) : $value,
        ]);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function paymentGatewayRules(): array
    {
        return [
            'payment_gateway_uuids' => ['sometimes', 'array'],
            'payment_gateway_uuids.*' => [
                'uuid',
                'distinct',
                Rule::exists('payment_gateways', 'uuid')->whereNull('deleted_at'),
            ],
        ];
    }

    /**
     * Checkout needs exactly one set of keys per provider, so an activity may not use two
     * gateways of the same provider (e.g. a PayMongo test and a PayMongo live gateway).
     */
    protected function validatePaymentGatewayProviders(Validator $validator): void
    {
        $uuids = $this->input('payment_gateway_uuids');
        if (! is_array($uuids) || count($uuids) < 2 || $validator->errors()->has('payment_gateway_uuids*')) {
            return;
        }

        $duplicateProviders = PaymentGateway::query()
            ->whereIn('uuid', $uuids)
            ->pluck('provider')
            ->countBy()
            ->filter(fn (int $count) => $count > 1)
            ->keys();

        if ($duplicateProviders->isNotEmpty()) {
            $validator->errors()->add(
                'payment_gateway_uuids',
                'Select only one payment gateway per provider (' . $duplicateProviders->implode(', ') . ').',
            );
        }
    }
}
