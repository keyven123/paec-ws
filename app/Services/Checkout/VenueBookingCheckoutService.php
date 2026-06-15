<?php

namespace App\Services\Checkout;

use App\Helpers\GeneralHelper;
use App\Models\Transaction;
use App\Models\VenueInquiry;
use App\Services\Payments\PaymentServiceFactory;
use Illuminate\Validation\ValidationException;

class VenueBookingCheckoutService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function startPayment(VenueInquiry $inquiry, string $userUuid, array $payload): array
    {
        $phase = $payload['payment_phase'] ?? VenueInquiry::PAYMENT_PHASE_DEPOSIT;

        if ($phase === VenueInquiry::PAYMENT_PHASE_BALANCE) {
            if ($inquiry->status !== VenueInquiry::STATUSES['BALANCE_DUE']) {
                throw ValidationException::withMessages([
                    'inquiry' => ['This inquiry is not ready for final balance payment.'],
                ]);
            }

            $amount = (float) $inquiry->balance_amount + (float) $inquiry->additional_charges;
            $orderPrefix = 'VEN-BAL-';
            $description = 'Venue booking final balance for ';
        } else {
            if ($inquiry->status !== VenueInquiry::STATUSES['DEPOSIT_REQUESTED']) {
                throw ValidationException::withMessages([
                    'inquiry' => ['This inquiry is not ready for deposit payment.'],
                ]);
            }

            $amount = (float) $inquiry->deposit_amount;
            $orderPrefix = 'VEN-DEP-';
            $description = 'Venue booking deposit for ';
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'inquiry' => ['This inquiry has no payable amount for the selected phase.'],
            ]);
        }

        $inquiry->loadMissing('venueListing');

        $transaction = Transaction::create([
            'user_uuid' => $userUuid,
            'transactionable_type' => 'venue_inquiry',
            'transactionable_uuid' => $inquiry->uuid,
            'organization_uuid' => $inquiry->venueListing?->organization_uuid,
            'payment_order_id' => GeneralHelper::generatePaymentOrderId(),
            'payment_provider' => $payload['payment_provider'],
            'order_number' => GeneralHelper::generateOrderNumber($orderPrefix),
            'sub_total' => $amount,
            'tax_amount' => 0,
            'discount' => 0,
            'total_amount' => $amount,
            'payment_data' => [
                'venue_payment_phase' => $phase,
                'venue_inquiry_uuid' => $inquiry->uuid,
            ],
        ]);

        $paymentService = PaymentServiceFactory::create($payload['payment_provider']);

        $paymentData = [
            'amount' => $transaction->total_amount,
            'currency' => 'PHP',
            'description' => $description . ($inquiry->venueListing?->name ?? 'venue'),
            'reference_id' => $transaction->payment_order_id,
            'metadata' => [
                'transaction_uuid' => $transaction->uuid,
                'user_uuid' => $transaction->user_uuid,
                'venue_inquiry_uuid' => $inquiry->uuid,
                'venue_payment_phase' => $phase,
            ],
            'return_url' => $payload['return_url'] ?? config('app.frontend_url') . '/payment/success/' . $transaction->uuid,
            'cancel_url' => $payload['cancel_url'] ?? config('app.frontend_url') . '/payment/cancel/' . $transaction->uuid,
        ];

        if ($payload['payment_provider'] === 'paymongo') {
            if (! empty($payload['payment_methods'])) {
                $paymentData['payment_methods'] = $payload['payment_methods'];
            }
            $paymentData['statement_descriptor'] = 'VENUE';
        } elseif ($payload['payment_provider'] === 'paypal') {
            $paymentData['brand_name'] = config('app.name');
            $paymentData['landing_page'] = 'LOGIN';
            $paymentData['shipping_preference'] = 'NO_SHIPPING';
            $paymentData['user_action'] = 'PAY_NOW';
        }

        $paymentResult = $paymentService->createPayment($paymentData);

        if (! ($paymentResult['success'] ?? false)) {
            throw ValidationException::withMessages([
                'payment' => ['Payment creation failed: ' . ($paymentResult['error'] ?? 'Unknown error')],
            ]);
        }

        $transaction->update([
            'payment_id' => $paymentResult['payment_id'] ?? null,
            'payment_status' => $paymentResult['status'] ?? 'pending',
            'payment_data' => array_merge(
                $transaction->payment_data ?? [],
                ['raw_response' => $paymentResult['raw_response'] ?? []],
            ),
        ]);

        return [
            'transaction' => $transaction->fresh(),
            'payment_result' => $paymentResult,
            'payment_phase' => $phase,
        ];
    }
}
