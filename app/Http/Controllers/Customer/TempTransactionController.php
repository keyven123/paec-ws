<?php

namespace App\Http\Controllers\Customer;

use App\Helpers\ComputationHelper;
use App\Http\Controllers\Controller;
use App\Http\Repositories\EventRepository;
use App\Http\Repositories\PromoCodeRepository;
use App\Http\Repositories\TempTransactionRepository;
use App\Http\Repositories\TransactionRepository;
use App\Services\ActivityComplianceService;
use App\Services\AffiliateAttributionService;
use App\Services\EventLocationService;
use App\Services\Checkout\CheckoutHandlerRegistry;
use App\Http\Requests\Customer\CheckoutTempTransactionRequest;
use App\Http\Requests\Customer\CheckoutFreeTempTransactionRequest;
use App\Http\Requests\Customer\CheckoutPaypalCardRequest;
use App\Http\Requests\Customer\ShowTempTransactionRequest;
use App\Http\Requests\Customer\TempTransactionRequest;
use App\Http\Requests\Customer\UpdateTempTransactionRequest;
use App\Http\Resources\TempTransactionResource;
use App\Jobs\SuccessPaymentProcess;
use App\Models\EventTicket;
use App\Models\TempTransaction;
use App\Models\Transaction;
use Illuminate\Http\Request;
use App\Services\MetaPixelService;
use App\Services\Payments\PaymentServiceFactory;
use App\Services\Payments\PaymentStatusResolver;
use App\Services\Payments\PayPalService;
use App\Support\OrganizationPaymentMethods;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TempTransactionController extends Controller
{
    public function __construct(
        protected TempTransactionRepository $tempTransactionRepository,
        protected EventRepository $eventRepository,
        protected TransactionRepository $transactionRepository,
        protected MetaPixelService $metaPixelService,
        protected PromoCodeRepository $promoCodeRepository,
        protected CheckoutHandlerRegistry $checkoutHandlerRegistry,
    ) {
    }

    public function getTempTransaction(ShowTempTransactionRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $tempTransaction = $this->tempTransactionRepository
            ->getSpecificTempTransactionByUser($request->user()->uuid, $payload);

        return response()->json($tempTransaction);
    }

    public function showTempTransactionByUuid(string $uuid): JsonResponse
    {
        $tempTransaction = $this->tempTransactionRepository->fetchOrThrow('uuid', $uuid);
        return (new TempTransactionResource($tempTransaction))->response();
    }

    /**
     * Store a newly created temp transaction.
     * @param TempTransactionRequest $request
     * @return JsonResponse
     */
    public function tempTransaction(TempTransactionRequest $request): JsonResponse
    {
        $payload = $request->validated();

        // Validate ticket availability before proceeding
        $this->validateTicketAvailability($payload['tickets'], null);

        $event = $this->eventRepository->fetchOrThrow('uuid', $payload['event_uuid']);
        $eventLocation = EventLocationService::resolveForCheckout(
            $event,
            $payload['event_location_uuid'] ?? null,
        );

        DB::beginTransaction();

        $promoCodeDiscount = 0;
        $promoCode = null;
        if (isset($payload['promo_code_uuid']) && $payload['promo_code_uuid']) {
            $promoCode = $this->promoCodeRepository->fetchOrThrow('uuid', $payload['promo_code_uuid']);
            $cartPreview = ComputationHelper::generateTempTransactionData($payload['tickets']);
            $promoCodeDiscount = ComputationHelper::calculatePromoCodeDiscount(
                $promoCode,
                ComputationHelper::promoEligibleCartTotal($cartPreview),
            );
        }

        $pricing = ComputationHelper::buildCheckoutPricing($event, $payload['tickets'], $promoCodeDiscount);

        $affiliatePartnerUuid = AffiliateAttributionService::resolvePartnerUuid(
            $payload['affiliate_code'] ?? null,
            $request->user(),
            $event,
        );

        $createPayload = [
            'user_uuid' => $request->user()->uuid,
            'transactionable_type' => 'event',
            'transactionable_uuid' => $payload['event_uuid'],
            'event_uuid' => $payload['event_uuid'],
            'event_location_uuid' => $eventLocation->uuid,
            'schedule_uuid' => $payload['schedule_uuid'] ?? null,
            'schedule_time_uuid' => $payload['schedule_time_uuid'] ?? null,
            'organization_uuid' => EventLocationService::resolveOrganizationUuid($eventLocation, $event),
            'affiliate_partner_uuid' => $affiliatePartnerUuid,
            'promo_code_uuid' => $promoCode ? $promoCode->uuid : null,
            'promo_code_discount' => $promoCodeDiscount,
            'sub_total' => $pricing['total_amount'],
            'discount' => $pricing['total_discount'],
            'markup_type' => $pricing['markup_type'],
            'markup_value' => $pricing['markup_value'],
            'markup_amount' => $pricing['markup_amount'],
            'markup_discount' => $pricing['markup_discount'],
            'tax_amount' => $pricing['tax_amount'],
            'total_amount' => $pricing['total_amount'],
        ];
        if (!empty($payload['valid_until'])) {
            $createPayload['valid_until'] = Carbon::parse($payload['valid_until'])->endOfDay()->format('Y-m-d H:i:s');
        }
        $tempTransaction = $this->tempTransactionRepository->create($createPayload);
        $this->tempTransactionRepository
            ->createTempTransactionOrders(
                $pricing['temp_transaction_orders'],
                $request->user()->uuid,
                $tempTransaction->uuid
            );

        $tempTransaction = $this->tempTransactionRepository->syncPricingTotals(
            $tempTransaction,
            $promoCodeDiscount,
        );

        DB::commit();

        return response()->json(
            $this->tempTransactionCheckoutPayload($tempTransaction->load(['tempTransactionOrders', 'event'])),
            201,
        );
    }

    public function updateTempTransaction(UpdateTempTransactionRequest $request): JsonResponse
    {
        $payload = $request->validated();

        // Get existing temp transaction to account for existing orders
        $tempTransaction = $this->tempTransactionRepository->fetchOrThrow('uuid', $payload['temp_transaction_uuid']);

        // Validate ticket availability before proceeding (accounting for existing orders)
        $this->validateTicketAvailability($payload['tickets'], $tempTransaction);

        $event = $this->eventRepository->fetchOrThrow('uuid', $payload['event_uuid']);
        $eventLocation = EventLocationService::resolveForCheckout(
            $event,
            $payload['event_location_uuid'] ?? null,
        );

        $promoCodeDiscount = 0;
        if (isset($payload['promo_code_uuid']) && $payload['promo_code_uuid']) {
            $promoCode = $this->promoCodeRepository->fetchOrThrow('uuid', $payload['promo_code_uuid']);
            $cartPreview = ComputationHelper::generateTempTransactionData($payload['tickets']);
            $promoCodeDiscount = ComputationHelper::calculatePromoCodeDiscount(
                $promoCode,
                ComputationHelper::promoEligibleCartTotal($cartPreview),
            );
            $payload['promo_code_uuid'] = $promoCode->uuid;
            $payload['promo_code_discount'] = $promoCodeDiscount;
        } else {
            $payload['promo_code_uuid'] = null;
            $payload['promo_code_discount'] = 0;
        }

        if (isset($payload['affiliate_code'])) {
            $payload['affiliate_partner_uuid'] = AffiliateAttributionService::resolvePartnerUuid(
                $payload['affiliate_code'],
                $request->user(),
                $event,
            );
        }

        $pricing = ComputationHelper::buildCheckoutPricing($event, $payload['tickets'], $promoCodeDiscount);

        DB::beginTransaction();
        $this->tempTransactionRepository->update($tempTransaction, array_merge($payload, [
            'event_location_uuid' => $eventLocation->uuid,
            'organization_uuid' => EventLocationService::resolveOrganizationUuid($eventLocation, $event),
            'sub_total' => $pricing['total_amount'],
            'discount' => $pricing['total_discount'],
            'markup_type' => $pricing['markup_type'],
            'markup_value' => $pricing['markup_value'],
            'markup_amount' => $pricing['markup_amount'],
            'markup_discount' => $pricing['markup_discount'],
            'tax_amount' => $pricing['tax_amount'],
            'total_amount' => $pricing['total_amount'],
        ]));

        $this->tempTransactionRepository
            ->updateTempTransactionOrders(
                $tempTransaction->fresh(),
                $pricing['temp_transaction_orders']
            );

        $tempTransaction = $this->tempTransactionRepository->syncPricingTotals(
            $tempTransaction->fresh(),
            $promoCodeDiscount,
        );

        if (!isset($payload['promo_code_uuid']) || !$payload['promo_code_uuid']) {
            $tempTransaction->update(['promo_code_uuid' => null]);
        }

        DB::commit();

        return response()->json(
            $this->tempTransactionCheckoutPayload($tempTransaction->fresh()->load(['tempTransactionOrders', 'event'])),
            201,
        );
    }

    /**
     * Release a seat-selection checkout hold by deleting the temp transaction and its orders.
     */
    public function destroyTempTransaction(Request $request, string $uuid): JsonResponse
    {
        $tempTransaction = $this->tempTransactionRepository->fetchOrThrow('uuid', $uuid);

        if ($tempTransaction->user_uuid !== $request->user()->uuid) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $tempTransaction->load(['tempTransactionOrders', 'event']);

        if (!$this->tempTransactionHasSeatSelection($tempTransaction)) {
            return response()->json([
                'success' => false,
                'message' => 'Only seat-selection reservations can be released.',
            ], 422);
        }

        DB::beginTransaction();

        try {
            $this->tempTransactionRepository->delete($tempTransaction);
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Reservation released.',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to delete temp transaction', [
                'temp_transaction_uuid' => $uuid,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to release reservation. Please try again.',
            ], 500);
        }
    }

    public function checkout(CheckoutTempTransactionRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $tempTransaction = $this->tempTransactionRepository->fetchOrThrow('uuid', $payload['temp_transaction_uuid']);

        if ($tempTransaction->promo_code_uuid) {
            $promoCode = $this->promoCodeRepository->fetchOrThrow('uuid', $tempTransaction->promo_code_uuid);
            if ($promoCode->max_use && $promoCode->used_count >= $promoCode->max_use) {
                $this->recalculateTempTransactionComplianceTotals($tempTransaction, 0);
                $tempTransaction->update(['promo_code_uuid' => null]);

                return response()->json([
                    'promo_code_applied' => false,
                    'success' => false,
                    'message' => 'Promo code reached its maximum usage. Promo code has been removed.'
                ], 404);
            }
        }
        DB::beginTransaction();

        try {
            // Create transaction record but don't complete it yet
            $result = $this->tempTransactionRepository->checkout($tempTransaction, $payload);

            // If total amount is 0, skip payment provider and mark as paid directly
            if ($result['transaction']->total_amount == 0) {
                $result['transaction']->update([
                    'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
                    'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
                    'paid_at' => now(),
                ]);
                SuccessPaymentProcess::dispatch($result['transaction']->uuid);
                $tempTransaction->tempTransactionOrders()->delete();
                $tempTransaction->delete();

                DB::commit();

                return response()->json([
                    'success' => true,
                    'transaction' => $result['transaction']->fresh(),
                    'orders' => $result['orders'],
                    'tickets' => $result['tickets'],
                    'payment' => [
                        'provider' => 'free',
                        'status' => 'paid',
                    ],
                    'redirect_url' => config('app.frontend_url') . '/payment/success/' . $result['transaction']->uuid,
                ], 201);
            }

            $result['event']->loadMissing('organization');
            $orgPaymentMethodsRaw = $result['event']->organization?->payment_methods;
            $allowedPaymongoTypes = OrganizationPaymentMethods::checkoutPaymongoApiTypes($orgPaymentMethodsRaw);
            $paypalAllowed = OrganizationPaymentMethods::checkoutPaypalAllowed($orgPaymentMethodsRaw);

            // Create payment using the selected provider
            $paymentService = PaymentServiceFactory::create($payload['payment_provider']);

            // Prepare payment data
            $paymentData = [
                'amount' => $result['transaction']->total_amount,
                'currency' => 'PHP',
                'description' => "Ticket purchase for {$result['event']->event_name}",
                'reference_id' => $result['transaction']->payment_order_id,
                'metadata' => [
                    'temp_transaction_uuid' => $result['transaction']->uuid,
                    'user_uuid' => $result['transaction']->user_uuid,
                    'event_uuid' => $result['transaction']->event_uuid,
                ],
                'return_url' => $payload['return_url'] ?? config('app.frontend_url') . '/payment/success/' . $result['transaction']->uuid,
                'cancel_url' => $payload['cancel_url'] ?? config('app.frontend_url') . '/payment/cancel/' . $result['transaction']->uuid,
            ];

            // Add provider-specific data (PayMongo rails restricted to organizer-enabled methods)
            if ($payload['payment_provider'] === 'paymongo') {
                if ($allowedPaymongoTypes === []) {
                    throw ValidationException::withMessages([
                        'payment_provider' => ['PayMongo checkout is not enabled for this event organizer.'],
                    ]);
                }
                if (! empty($payload['payment_methods'])) {
                    $requested = array_values(array_intersect($payload['payment_methods'], $allowedPaymongoTypes));
                    if ($requested === []) {
                        throw ValidationException::withMessages([
                            'payment_methods' => ['None of the selected payment methods are enabled for this organizer.'],
                        ]);
                    }
                    $paymentData['payment_methods'] = $requested;
                } else {
                    $paymentData['payment_methods'] = $allowedPaymongoTypes;
                }
                $paymentData['statement_descriptor'] = 'TICKET';
            } elseif ($payload['payment_provider'] === 'paypal') {
                if (! $paypalAllowed) {
                    throw ValidationException::withMessages([
                        'payment_provider' => ['PayPal checkout is not enabled for this event organizer.'],
                    ]);
                }
                $paymentData['brand_name'] = $payload['brand_name'] ?? config('app.name');
                $paymentData['landing_page'] = $payload['landing_page'] ?? 'LOGIN'; // LOGIN or BILLING
                $paymentData['shipping_preference'] = $payload['shipping_preference'] ?? 'NO_SHIPPING'; // NO_SHIPPING, GET_FROM_FILE, or SET_PROVIDED_ADDRESS
                $paymentData['user_action'] = $payload['user_action'] ?? 'PAY_NOW'; // PAY_NOW or CONTINUE
            }

            // Create payment intent/order
            $paymentResult = $paymentService->createPayment($paymentData);

            if (!$paymentResult['success']) {
                throw new \Exception('Payment creation failed: ' . $paymentResult['error']);
            }

            $result['transaction']->update([
                'payment_id' => $paymentResult['payment_id'],
                'payment_status' => $paymentResult['status'] ?? 'pending',
                'payment_data' => $paymentResult['raw_response'] ?? [],
            ]);

            $tempTransaction->tempTransactionOrders()->delete();
            $tempTransaction->delete();

            DB::commit();

            // Prepare response based on payment provider
            $response = [
                'success' => true,
                'transaction' => $result['transaction']->fresh(),
                'orders' => $result['orders'],
                'tickets' => $result['tickets'],
                'payment' => [
                    'provider' => $payload['payment_provider'],
                    'payment_id' => $paymentResult['payment_id'],
                    'status' => $paymentResult['status'] ?? 'pending',
                ]
            ];

            // Add provider-specific response data
            if ($payload['payment_provider'] === 'paymongo') {
                // PayMongo Checkout Session returns a checkout_url for hosted checkout
                $response['payment']['checkout_url'] = $paymentResult['checkout_url'] ?? null;
                if ($paymentResult['checkout_url']) {
                    $response['redirect_url'] = $paymentResult['checkout_url'];
                }
            } elseif ($payload['payment_provider'] === 'paypal') {
                $response['payment']['approval_url'] = $paymentResult['approval_url'] ?? null;
                // Use PayPal approval URL as redirect
                if ($paymentResult['approval_url']) {
                    $response['redirect_url'] = $paymentResult['approval_url'];
                }
            }

            return response()->json($response, 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Checkout failed', [
                'temp_transaction_uuid' => $payload['temp_transaction_uuid'],
                'payment_provider' => $payload['payment_provider'] ?? 'free',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Checkout failed. Please try again.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Checkout via PayPal Advanced Credit / Debit Card (ACDC).
     *
     * This endpoint creates the transaction and a PayPal order, but does NOT
     * return a redirect URL — the buyer stays on the checkout page and the
     * PayPal JS SDK Card Fields component confirms the payment source on the
     * client. After the SDK approves the order the frontend calls
     * /transactions/{uuid}/complete which handles the server-side capture.
     */
    public function checkoutPaypalCard(CheckoutPaypalCardRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $tempTransaction = $this->tempTransactionRepository->fetchOrThrow('uuid', $payload['temp_transaction_uuid']);

        if ($tempTransaction->promo_code_uuid) {
            $promoCode = $this->promoCodeRepository->fetchOrThrow('uuid', $tempTransaction->promo_code_uuid);
            if ($promoCode->max_use && $promoCode->used_count >= $promoCode->max_use) {
                $this->recalculateTempTransactionComplianceTotals($tempTransaction, 0);
                $tempTransaction->update(['promo_code_uuid' => null]);

                return response()->json([
                    'promo_code_applied' => false,
                    'success' => false,
                    'message' => 'Promo code reached its maximum usage. Promo code has been removed.'
                ], 404);
            }
        }

        if ($tempTransaction->total_amount <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'This transaction does not require card payment. Please use the free checkout.',
            ], 400);
        }

        DB::beginTransaction();

        try {
            $result = $this->tempTransactionRepository->checkout($tempTransaction, [
                'payment_provider' => 'paypal',
                'other_info' => $payload['other_info'] ?? null,
            ]);

            $result['event']->loadMissing('organization');
            $orgPaymentMethodsRaw = $result['event']->organization?->payment_methods;
            if (! OrganizationPaymentMethods::checkoutPaypalAllowed($orgPaymentMethodsRaw)) {
                throw ValidationException::withMessages([
                    'temp_transaction_uuid' => ['PayPal card checkout is not enabled for this event organizer.'],
                ]);
            }

            $paypalService = new PayPalService();

            $paymentData = [
                'amount' => $result['transaction']->total_amount,
                'currency' => 'PHP',
                'description' => "Ticket purchase for {$result['event']->event_name}",
                'reference_id' => $result['transaction']->payment_order_id,
                'brand_name' => config('app.name'),
                'metadata' => [
                    'temp_transaction_uuid' => $result['transaction']->uuid,
                    'user_uuid' => $result['transaction']->user_uuid,
                    'event_uuid' => $result['transaction']->event_uuid,
                ],
            ];

            $paymentResult = $paypalService->createCardOrder($paymentData);

            if (!$paymentResult['success']) {
                throw new \Exception('Payment creation failed: ' . ($paymentResult['error'] ?? 'Unknown error'));
            }

            $result['transaction']->update([
                'payment_id' => $paymentResult['payment_id'],
                'payment_status' => $paymentResult['status'] ?? 'pending',
                'payment_data' => $paymentResult['raw_response'] ?? [],
            ]);

            $tempTransaction->tempTransactionOrders()->delete();
            $tempTransaction->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'transaction' => $result['transaction']->fresh(),
                'orders' => $result['orders'],
                'tickets' => $result['tickets'],
                'payment' => [
                    'provider' => 'paypal',
                    'flow' => 'card',
                    'payment_id' => $paymentResult['payment_id'],
                    'status' => $paymentResult['status'] ?? 'pending',
                ],
                'paypal_order_id' => $paymentResult['payment_id'],
                'transaction_uuid' => $result['transaction']->uuid,
            ], 201);
        } catch (ValidationException $e) {
            DB::rollBack();
            throw $e;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('PayPal card checkout failed', [
                'temp_transaction_uuid' => $payload['temp_transaction_uuid'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Checkout failed. Please try again.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Temporary dev bypass — marks any temp transaction as paid without a gateway.
     * Only available when APP_DEBUG=true.
     */
    public function checkoutBypass(CheckoutFreeTempTransactionRequest $request): JsonResponse
    {
        if (!config('app.debug')) {
            return response()->json([
                'success' => false,
                'message' => 'Payment bypass is not available.',
            ], 403);
        }

        $payload = $request->validated();
        $tempTransaction = $this->tempTransactionRepository->fetchOrThrow('uuid', $payload['temp_transaction_uuid']);

        DB::beginTransaction();

        try {
            $result = $this->tempTransactionRepository->checkout($tempTransaction, [
                'payment_provider' => 'free',
                'other_info' => $payload['other_info'] ?? null,
            ]);

            $result['transaction']->update([
                'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
                'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
                'paid_at' => now(),
            ]);

            $paidTransaction = $result['transaction']->fresh();
            $handler = $this->checkoutHandlerRegistry->resolveFor($paidTransaction);
            if ($handler) {
                $handler->handlePaid($paidTransaction, syncFulfillment: true);
            } else {
                SuccessPaymentProcess::dispatchSync($paidTransaction->uuid);
            }

            $tempTransaction->tempTransactionOrders()->delete();
            $tempTransaction->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'transaction' => $paidTransaction->fresh(),
                'orders' => $result['orders'],
                'tickets' => $result['tickets'],
                'payment' => [
                    'provider' => 'bypass',
                    'status' => 'paid',
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Bypass checkout failed', [
                'temp_transaction_uuid' => $payload['temp_transaction_uuid'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Checkout failed. Please try again.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Checkout free tickets (total_amount = 0)
     */
    public function checkoutFree(CheckoutFreeTempTransactionRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $tempTransaction = $this->tempTransactionRepository->fetchOrThrow('uuid', $payload['temp_transaction_uuid']);

        // Validate that the transaction is actually free
        if ($tempTransaction->total_amount > 0) {
            return response()->json([
                'success' => false,
                'message' => 'This transaction requires payment. Please use the regular checkout.',
            ], 400);
        }

        DB::beginTransaction();

        try {
            // Create transaction record with 'free' as payment provider
            $result = $this->tempTransactionRepository->checkout($tempTransaction, [
                'payment_provider' => 'free',
                'other_info' => $payload['other_info'] ?? null,
            ]);

            // Mark transaction as paid and confirmed immediately
            $result['transaction']->update([
                'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
                'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
                'paid_at' => now(),
            ]);

            $paidTransaction = $result['transaction']->fresh();
            $handler = $this->checkoutHandlerRegistry->resolveFor($paidTransaction);
            if ($handler) {
                $handler->handlePaid($paidTransaction);
            } else {
                SuccessPaymentProcess::dispatch($paidTransaction->uuid);
            }

            $tempTransaction->tempTransactionOrders()->delete();
            $tempTransaction->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'transaction' => $paidTransaction->fresh(),
                'orders' => $result['orders'],
                'tickets' => $result['tickets'],
                'payment' => [
                    'provider' => 'free',
                    'status' => 'paid',
                ],
                'redirect_url' => config('app.frontend_url') . '/payment/success/' . $result['transaction']->uuid,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Free checkout failed', [
                'temp_transaction_uuid' => $payload['temp_transaction_uuid'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Free checkout failed. Please try again.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Complete payment after successful payment confirmation
     */
    public function completePayment(string $transactionUuid): JsonResponse
    {
        try {
            $transaction = Transaction::where('uuid', $transactionUuid)->firstOrFail();

            // Handle free tickets - bypass payment verification
            if ($transaction->payment_provider === 'free' && $transaction->total_amount == 0) {
                return response()->json([
                    'success' => true,
                    'payment_status' => 'paid',
                    'transaction' => $transaction->fresh(),
                    'message' => '🎉 Congratulations! You\'ve successfully claimed your free ticket! Enjoy the event!'
                ]);
            }


            // Get payment service for the provider
            $paymentService = PaymentServiceFactory::create($transaction->payment_provider);

            // Check payment status
            $paymentStatus = $paymentService->getPaymentStatus($transaction->payment_id);

            if (!$paymentStatus['success']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to verify payment status'
                ], 400);
            }

            // If PayPal order is APPROVED, capture it first
            // CREATED = order created but user hasn't approved yet (shouldn't capture)
            // APPROVED = user approved and ready to capture
            if ($transaction->payment_provider === 'paypal' && $paymentStatus['status'] === 'APPROVED') {
                $captureResult = $paymentService->capturePayment($transaction->payment_id);

                if ($captureResult['success']) {
                    // Use the capture result status directly (should be COMPLETED)
                    // If status is already COMPLETED from capture, use it; otherwise re-check
                    if (isset($captureResult['status']) && $captureResult['status'] === 'COMPLETED') {
                        $paymentStatus['status'] = 'COMPLETED';
                        $paymentStatus['raw_response'] = $captureResult['raw_response'] ?? $paymentStatus['raw_response'];
                    } else {
                        // Re-check status if capture didn't return COMPLETED directly
                        $paymentStatus = $paymentService->getPaymentStatus($transaction->payment_id);
                    }
                } else {
                    Log::error('PayPal payment capture failed', [
                        'transaction_uuid' => $transaction->uuid,
                        'payment_id' => $transaction->payment_id,
                        'error' => $captureResult['error'] ?? 'Unknown error',
                        'capture_result' => $captureResult
                    ]);

                    // Extract user-friendly error message (already parsed by PayPalService)
                    $errorMessage = $captureResult['error'] ?? 'Payment could not be processed. Please try a different payment method.';

                    return response()->json([
                        'success' => false,
                        'message' => $errorMessage,
                        'payment_status' => $paymentStatus['status'],
                        'transaction' => $transaction->fresh()
                    ], 400);
                }
            }

            $gatewayStatus = (string) $paymentStatus['status'];
            $resolution = PaymentStatusResolver::resolve($gatewayStatus);

            if ($resolution === PaymentStatusResolver::RESOLUTION_PENDING) {
                $transaction->update([
                    'payment_data' => array_merge($transaction->payment_data ?? [], $paymentStatus['raw_response'] ?? []),
                ]);

                return response()->json([
                    'success' => false,
                    'payment_status' => $gatewayStatus,
                    'transaction' => $transaction->fresh()->load([
                        'event',
                        'transactionOrders.eventTicket',
                        'user',
                    ]),
                    'message' => 'Payment is still pending',
                ], 200);
            }

            if ($resolution === PaymentStatusResolver::RESOLUTION_FAILED) {
                $transaction->update([
                    'status' => Transaction::STATUS['CANCELLED'],
                    'payment_status' => Transaction::PAYMENT_STATUS['FAILED'],
                    'payment_data' => array_merge($transaction->payment_data ?? [], $paymentStatus['raw_response'] ?? []),
                    'order_status' => Transaction::ORDER_STATUS['CANCELLED'],
                    'paid_at' => null,
                ]);

                $tickets = $transaction->tickets;
                foreach ($tickets as $ticket) {
                    $ticket->delete();
                }

                return response()->json([
                    'success' => false,
                    'payment_status' => $gatewayStatus,
                    'transaction' => $transaction->fresh()->load([
                        'event',
                        'transactionOrders.eventTicket',
                        'user',
                    ]),
                    'message' => 'Payment failed',
                ], 200);
            }

            $transaction->update([
                'status' => Transaction::STATUS['ACTIVE'],
                'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
                'payment_data' => array_merge($transaction->payment_data ?? [], $paymentStatus['raw_response'] ?? []),
                'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
                'paid_at' => now(),
            ]);

            $paidTransaction = $transaction->fresh();

            $handler = $this->checkoutHandlerRegistry->resolveFor($paidTransaction);
            if ($handler) {
                $handler->handlePaid($paidTransaction);
            }

            return response()->json([
                'success' => true,
                'payment_status' => $gatewayStatus,
                'transaction' => $paidTransaction->load([
                    'event',
                    'transactionOrders.eventTicket',
                    'user',
                ]),
                'message' => 'Payment completed successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Payment completion failed', [
                'transaction_uuid' => $transactionUuid,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment completion failed',
            ], 500);
        }
    }

    public function cancelPayment(string $transactionUuid): JsonResponse
    {
        $transaction = Transaction::where('uuid', $transactionUuid)->firstOrFail();
        $transaction->update([
            'status' => Transaction::STATUS['CANCELLED'],
            'payment_status' => Transaction::PAYMENT_STATUS['CANCELLED'],
            'order_status' => Transaction::ORDER_STATUS['CANCELLED'],
        ]);

        $freshTransaction = $transaction->fresh()->load([
            'event',
            'transactionOrders.eventTicket',
            'user',
        ]);
        $this->trackMetaPixelCancellation($freshTransaction);

        $tickets = $transaction->tickets;

        foreach ($tickets as $ticket) {
            $ticket->delete();
        }

        return response()->json([
            'success' => true,
            'transaction' => $freshTransaction,
            'message' => 'Payment cancelled successfully'
        ]);
    }

    /**
     * Build Meta Pixel user data payload
     */
    private function getMetaPixelUserData(Transaction $transaction): array
    {
        $user = $transaction->user;

        if (!$user) {
            return [];
        }

        return [
            'email' => $user->email ?? null,
            'phone' => $user->phone ?? null,
            'first_name' => $user->first_name ?? null,
            'last_name' => $user->last_name ?? null,
            'external_id' => $user->uuid ?? null,
        ];
    }

    /**
     * Track Meta Pixel cancellation event
     */
    private function trackMetaPixelCancellation(Transaction $transaction): void
    {
        try {
            $userData = $this->getMetaPixelUserData($transaction);
            $this->metaPixelService->trackPaymentCancellation($transaction, $userData);
        } catch (\Exception $e) {
            Log::error('Failed to track Meta Pixel cancellation event', [
                'transaction_uuid' => $transaction->uuid,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Validate ticket availability before creating or updating temp transaction
     *
     * @param array $tickets Array of tickets with event_ticket_uuid and quantity
     * @param TempTransaction|null $existingTempTransaction Existing temp transaction (for updates)
     * @return void
     * @throws ValidationException
     */
    private function tempTransactionHasSeatSelection(TempTransaction $tempTransaction): bool
    {
        return $tempTransaction->hasSeatReservation();
    }

    private function validateTicketAvailability(array $tickets, ?TempTransaction $existingTempTransaction = null): void
    {
        $errors = [];

        // Get existing order quantities for update scenario
        $existingOrderQuantities = [];
        if ($existingTempTransaction) {
            foreach ($existingTempTransaction->tempTransactionOrders as $order) {
                $ticketUuid = $order->event_ticket_uuid;
                $existingOrderQuantities[$ticketUuid] = ($existingOrderQuantities[$ticketUuid] ?? 0) + $order->quantity;
            }
        }

        foreach ($tickets as $index => $ticket) {
            $eventTicket = EventTicket::where('uuid', $ticket['event_ticket_uuid'])->first();

            if (!$eventTicket) {
                $errors["tickets.{$index}.event_ticket_uuid"] = ['Ticket not found.'];
                continue;
            }

            // Skip validation for unlimited tickets
            if ($eventTicket->is_unlimited) {
                continue;
            }

            $requestedQuantity = (int) $ticket['quantity'];

            // Calculate available tickets
            // For updates: subtract existing order quantities from sold_ticket
            $effectiveSoldTickets = $eventTicket->sold_ticket;
            if ($existingTempTransaction && isset($existingOrderQuantities[$eventTicket->uuid])) {
                $effectiveSoldTickets -= $existingOrderQuantities[$eventTicket->uuid];
            }

            $availableTickets = $eventTicket->max_ticket - $effectiveSoldTickets;

            // Check if requested quantity exceeds available tickets
            if ($requestedQuantity > $availableTickets) {
                $ticketName = $eventTicket->name ?? 'Unknown ticket';
                if ($availableTickets <= 0) {
                    $errors["tickets.{$index}.quantity"] = ["{$ticketName} is no longer available. All tickets have been sold."];
                } else {
                    $errors["tickets.{$index}.quantity"] = ["Only {$availableTickets} ticket" . ($availableTickets > 1 ? 's' : '') . " available for {$ticketName}. You requested {$requestedQuantity}."];
                }
            }
        }

        if (!empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function tempTransactionCheckoutPayload(TempTransaction $tempTransaction): array
    {
        $resource = (new TempTransactionResource($tempTransaction))->resolve();

        return array_merge($tempTransaction->toArray(), $resource);
    }

    private function recalculateTempTransactionComplianceTotals(
        TempTransaction $tempTransaction,
        float $promoCodeDiscount,
    ): void {
        $this->tempTransactionRepository->syncPricingTotals($tempTransaction, $promoCodeDiscount);
    }
}
