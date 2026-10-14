<?php

namespace App\Http\Controllers\Customer;

use App\Constants\GeneralConstants;
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
use App\Http\Requests\Customer\GuestCheckoutTempTransactionRequest;
use App\Http\Requests\Customer\GuestPayTempTransactionRequest;
use App\Http\Requests\Customer\GuestTempTransactionRequest;
use App\Http\Requests\Customer\GuestTransactionPaymentRequest;
use App\Http\Requests\Customer\ShowTempTransactionRequest;
use App\Http\Requests\Customer\TempTransactionRequest;
use App\Http\Requests\Customer\UpdateTempTransactionRequest;
use App\Http\Resources\TempTransactionResource;
use App\Jobs\SuccessPaymentProcess;
use App\Models\AdminUser;
use App\Models\EventTicket;
use App\Models\PaymentGateway;
use App\Models\TempTransaction;
use App\Models\Transaction;
use App\Notifications\PaymentCancelledNotification;
use App\Models\User;
use App\Services\Checkout\GuestCustomerService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Services\MetaPixelService;
use App\Services\Payments\PaymentServiceFactory;
use App\Services\Payments\PayMongoService;
use App\Services\Payments\PaymentStatusResolver;
use App\Services\Payments\PaynamicsService;
use App\Services\Payments\PayPalService;
use App\Support\OrganizationPaymentMethods;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TempTransactionController extends Controller
{
    private const PAYNAMICS_CHANNEL_UNAVAILABLE = 'This payment option is not available right now. Please choose another one.';

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
        return $this->createTempTransactionFor($request->validated(), $request->user());
    }

    /**
     * Guest checkout: create the temp transaction for the customer matching the guest email
     * (a guest customer is created when no account exists).
     */
    public function guestTempTransaction(GuestTempTransactionRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $user = GuestCustomerService::resolve($payload['guest']);

        return $this->createTempTransactionFor($payload, $user);
    }

    public function guestPlaceOrder(GuestCheckoutTempTransactionRequest $request): JsonResponse
    {
        $this->assertGuestOwnsTempTransaction($request->validated());

        return $this->placeOrder($request);
    }

    /**
     * The payment gateway must be one the activity is set up with and active.
     */
    private function resolveCheckoutGateway(TempTransaction $tempTransaction, ?string $gatewayUuid): ?PaymentGateway
    {
        if (! $gatewayUuid) {
            return null;
        }

        $gateway = $tempTransaction->event?->paymentGateways()
            ->where('payment_gateways.uuid', $gatewayUuid)
            ->where('status', GeneralConstants::GENERAL_STATUSES['ACTIVE'])
            ->first();

        if (! $gateway) {
            throw ValidationException::withMessages([
                'payment_gateway_uuid' => ['This payment method is not available for this activity.'],
            ]);
        }

        return $gateway;
    }

    /**
     * Customers may only verify or cancel their own payments; admins (transactions-execute) may verify any.
     */
    private function assertCanManageTransaction(Transaction $transaction): void
    {
        $actor = request()->user();
        if ($actor instanceof AdminUser) {
            return;
        }

        if (! $actor || $actor->uuid !== $transaction->user_uuid) {
            abort(403, 'You cannot access this transaction.');
        }
    }

    private function assertGuestOwnsTransaction(Transaction $transaction, string $email): void
    {
        $ownerEmail = User::query()->whereKey($transaction->user_uuid)->value('email');

        if (! $ownerEmail || Str::lower($ownerEmail) !== Str::lower(trim($email))) {
            abort(404);
        }
    }

    private function assertGuestOwnsTempTransaction(array $payload): void
    {
        $tempTransaction = $this->tempTransactionRepository->fetchOrThrow('uuid', $payload['temp_transaction_uuid']);
        $ownerEmail = User::query()->whereKey($tempTransaction->user_uuid)->value('email');

        if (! $ownerEmail || Str::lower($ownerEmail) !== Str::lower(trim($payload['email']))) {
            throw ValidationException::withMessages([
                'temp_transaction_uuid' => ['This order does not match the provided email address.'],
            ]);
        }
    }

    private function createTempTransactionFor(array $payload, User $user): JsonResponse
    {
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
            $user,
            $event,
        );

        $createPayload = [
            'user_uuid' => $user->uuid,
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
        $tempTransaction = $this->tempTransactionRepository->create($createPayload);
        $this->tempTransactionRepository
            ->createTempTransactionOrders(
                $pricing['temp_transaction_orders'],
                $user->uuid,
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

        if ($tempTransaction->user_uuid !== $request->user()?->uuid) {
            throw ValidationException::withMessages([
                'temp_transaction_uuid' => ['This order does not belong to your account.'],
            ]);
        }

        return $this->startCheckout($tempTransaction, $payload);
    }

    /**
     * Guest checkout through the activity's payment gateway: returns the hosted payment page URL.
     */
    public function guestCheckout(GuestPayTempTransactionRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $this->assertGuestOwnsTempTransaction($payload);
        $tempTransaction = $this->tempTransactionRepository->fetchOrThrow('uuid', $payload['temp_transaction_uuid']);

        return $this->startCheckout($tempTransaction, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function startCheckout(TempTransaction $tempTransaction, array $payload): JsonResponse
    {
        $gateway = $this->resolveCheckoutGateway($tempTransaction, $payload['payment_gateway_uuid'] ?? null);
        if ($gateway) {
            $payload['payment_provider'] = $gateway->provider;
        }

        $isPaynamics = $payload['payment_provider'] === GeneralConstants::PAYMENT_GATEWAY_PROVIDERS['PAYNAMICS'];
        if ($isPaynamics && empty($payload['payment_channel'])) {
            throw ValidationException::withMessages([
                'payment_channel' => ['Choose how you want to pay with Paynamics.'],
            ]);
        }
        if ($isPaynamics && ! in_array($payload['payment_channel'], $gateway->enabledPaynamicsChannels(), true)) {
            throw ValidationException::withMessages([
                'payment_channel' => [self::PAYNAMICS_CHANNEL_UNAVAILABLE],
            ]);
        }

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

            if ($gateway && $allowedPaymongoTypes === []) {
                // The activity's own gateway is used even when the organizer has not configured methods.
                $allowedPaymongoTypes = OrganizationPaymentMethods::defaultPaymongoCheckoutApiTypes();
            }
            $paypalAllowed = $paypalAllowed || $gateway !== null;

            $paymentService = $gateway
                ? PaymentServiceFactory::forGateway($gateway)
                : PaymentServiceFactory::create($payload['payment_provider']);

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
            } elseif ($isPaynamics) {
                $paymentData['payment_channel'] = $payload['payment_channel'];
                $paymentData['notification_url'] = url('/api/v1/payments/paynamics/notification');
                $paymentData['customer'] = $result['transaction']->user?->only([
                    'first_name', 'middle_name', 'last_name', 'email', 'phone_number',
                    'address_line_1', 'address_line_2', 'city', 'region', 'postal_code', 'country',
                ]) ?? [];
            }

            // Create payment intent/order
            $paymentResult = $paymentService->createPayment($paymentData);

            if (!$paymentResult['success']) {
                if ($paymentResult['channel_unavailable'] ?? false) {
                    throw ValidationException::withMessages([
                        'payment_channel' => [self::PAYNAMICS_CHANNEL_UNAVAILABLE],
                    ]);
                }
                throw new \Exception('Payment creation failed: ' . $paymentResult['error']);
            }

            $result['transaction']->update([
                'payment_gateway_uuid' => $gateway?->uuid,
                'paybiz_wallet_id' => $isPaynamics ? $gateway?->paybiz_wallet_id : null,
                'payment_id' => $paymentResult['payment_id'],
                'payment_status' => Transaction::PAYMENT_STATUS['PENDING'],
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
            } elseif ($isPaynamics) {
                $response['payment']['checkout_url'] = $paymentResult['checkout_url'];
                $response['redirect_url'] = $paymentResult['checkout_url'];
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
     * Confirms a free order and issues tickets immediately. Paid orders must go through a payment gateway.
     */
    public function placeOrder(CheckoutFreeTempTransactionRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $tempTransaction = $this->tempTransactionRepository->fetchOrThrow('uuid', $payload['temp_transaction_uuid']);

        if ($tempTransaction->total_amount > 0) {
            return response()->json([
                'success' => false,
                'message' => 'This order requires payment. Please choose a payment method.',
            ], 400);
        }

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
                'tickets' => $paidTransaction->tickets()
                    ->with('eventTicket:uuid,name,price')
                    ->get(),
                'payment' => [
                    'provider' => 'free',
                    'status' => 'paid',
                ],
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Place order failed', [
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
        $transaction = Transaction::where('uuid', $transactionUuid)->firstOrFail();
        $this->assertCanManageTransaction($transaction);

        return $this->reconcileGatewayPayment($transaction);
    }

    public function guestCompletePayment(GuestTransactionPaymentRequest $request, string $transactionUuid): JsonResponse
    {
        $transaction = Transaction::where('uuid', $transactionUuid)->firstOrFail();
        $this->assertGuestOwnsTransaction($transaction, $request->validated('email'));

        return $this->reconcileGatewayPayment($transaction);
    }

    /**
     * Re-checks a pending payment with its gateway for scheduled jobs, which have no signed-in viewer.
     */
    public function verifyPendingPayment(string $transactionUuid): JsonResponse
    {
        return $this->reconcileGatewayPayment(Transaction::where('uuid', $transactionUuid)->firstOrFail());
    }

    /**
     * Paynamics server-to-server notification. The posted body is only used to find the order;
     * its status always comes from the signed query API.
     */
    public function paynamicsNotification(Request $request): JsonResponse
    {
        // Paynamics may post JSON without a JSON content type, so fall back to decoding the raw body.
        $payload = $request->all() ?: (array) json_decode($request->getContent(), true);
        $requestId = (string) (data_get($payload, 'request_id') ?? data_get($payload, 'transaction.request_id') ?? '');

        Log::info('Paynamics notification received', [
            'request_id' => $requestId,
            'response_code' => data_get($payload, 'response_code'),
            'fields' => array_keys($payload),
        ]);

        $transaction = Transaction::query()
            ->where('payment_provider', GeneralConstants::PAYMENT_GATEWAY_PROVIDERS['PAYNAMICS'])
            ->where('payment_id', $requestId)
            ->first();

        if (! $transaction) {
            return response()->json(['received' => false, 'message' => 'Unknown request_id'], 404);
        }

        $outcome = $this->reconcileGatewayPayment($transaction)->getData(true);

        return response()->json([
            'received' => true,
            'payment_status' => $transaction->fresh()->payment_status,
            'message' => $outcome['message'] ?? null,
        ]);
    }

    public function guestCancelPayment(GuestTransactionPaymentRequest $request, string $transactionUuid): JsonResponse
    {
        $transaction = Transaction::where('uuid', $transactionUuid)->firstOrFail();
        $this->assertGuestOwnsTransaction($transaction, $request->validated('email'));

        return $this->cancelTransactionPayment($transaction);
    }

    /**
     * Check the transaction against its payment gateway and issue the tickets once it is paid.
     */
    private function reconcileGatewayPayment(Transaction $transaction): JsonResponse
    {
        try {
            if ($transaction->payment_status === Transaction::PAYMENT_STATUS['PAID']) {
                return $this->paidTransactionResponse($transaction, 'Payment already completed');
            }

            if (in_array($transaction->payment_status, [
                Transaction::PAYMENT_STATUS['CANCELLED'],
                Transaction::PAYMENT_STATUS['FAILED'],
            ], true)) {
                return response()->json([
                    'success' => false,
                    'payment_status' => $transaction->payment_status,
                    'transaction' => $transaction,
                    'message' => 'This order was cancelled and no payment was taken. Please start a new order.',
                ]);
            }

            // Handle free tickets - bypass payment verification
            if ($transaction->payment_provider === 'free' && $transaction->total_amount == 0) {
                return response()->json([
                    'success' => true,
                    'payment_status' => 'paid',
                    'transaction' => $transaction->fresh(),
                    'message' => '🎉 Congratulations! You\'ve successfully claimed your free ticket! Enjoy the event!'
                ]);
            }


            $paymentService = PaymentServiceFactory::forTransaction($transaction);

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
                $this->notifyOrderCancelled($transaction);

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

            // Fulfil synchronously so the confirmation page can show the issued QR codes.
            $handler = $this->checkoutHandlerRegistry->resolveFor($paidTransaction);
            if ($handler) {
                $handler->handlePaid($paidTransaction, syncFulfillment: true);
            } else {
                SuccessPaymentProcess::dispatchSync($paidTransaction->uuid);
            }

            return $this->paidTransactionResponse($paidTransaction, 'Payment completed successfully', $gatewayStatus);
        } catch (\Exception $e) {
            Log::error('Payment completion failed', [
                'transaction_uuid' => $transaction->uuid,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment completion failed',
            ], 500);
        }
    }

    private function paidTransactionResponse(Transaction $transaction, string $message, ?string $gatewayStatus = null): JsonResponse
    {
        $transaction = $transaction->fresh();

        return response()->json([
            'success' => true,
            'payment_status' => $gatewayStatus ?? $transaction->payment_status,
            'transaction' => $transaction->load([
                'event',
                'transactionOrders.eventTicket',
                'user',
            ]),
            'tickets' => $transaction->tickets()
                ->with('eventTicket:uuid,name,price')
                ->get(),
            'message' => $message,
        ]);
    }

    public function cancelPayment(string $transactionUuid): JsonResponse
    {
        $transaction = Transaction::where('uuid', $transactionUuid)->firstOrFail();
        $this->assertCanManageTransaction($transaction);

        return $this->cancelTransactionPayment($transaction);
    }

    private function cancelTransactionPayment(Transaction $transaction): JsonResponse
    {
        if ($transaction->payment_status === Transaction::PAYMENT_STATUS['PAID']) {
            return response()->json([
                'success' => false,
                'payment_status' => $transaction->payment_status,
                'message' => 'This order is already paid and cannot be cancelled here.',
            ], 409);
        }

        if ($transaction->payment_status === Transaction::PAYMENT_STATUS['CANCELLED']) {
            return response()->json([
                'success' => true,
                'transaction' => $transaction,
                'message' => 'Payment cancelled successfully',
            ]);
        }

        if ($this->expireGatewayCheckout($transaction)) {
            // The customer finished paying on the gateway before cancelling; honour the payment.
            return $this->reconcileGatewayPayment($transaction);
        }

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
        $this->notifyOrderCancelled($freshTransaction);

        return response()->json([
            'success' => true,
            'transaction' => $freshTransaction,
            'message' => 'Payment cancelled successfully'
        ]);
    }

    /**
     * Ticket orders only; venue bookings send their own emails.
     */
    private function notifyOrderCancelled(Transaction $transaction): void
    {
        if ($transaction->event_uuid !== null && $transaction->user !== null) {
            $transaction->user->notify(new PaymentCancelledNotification($transaction->uuid));
        }
    }

    /**
     * Best-effort: stop the gateway from accepting payment for this order (PayMongo expires the
     * hosted checkout session; uncaptured PayPal orders lapse on their own; Paynamics links expire,
     * so it is only asked whether the customer already paid).
     *
     * @return bool True when the gateway reports the order is already paid.
     */
    private function expireGatewayCheckout(Transaction $transaction): bool
    {
        if (empty($transaction->payment_id) || ! in_array($transaction->payment_provider, [
            GeneralConstants::PAYMENT_PROVIDERS['PAYMONGO'],
            GeneralConstants::PAYMENT_GATEWAY_PROVIDERS['PAYNAMICS'],
        ], true)) {
            return false;
        }

        try {
            $service = PaymentServiceFactory::forTransaction($transaction);
            if ($service instanceof PaynamicsService) {
                return ($service->getPaymentStatus($transaction->payment_id)['status'] ?? null) === 'paid';
            }

            $result = $service instanceof PayMongoService ? $service->cancelPayment($transaction->payment_id) : [];

            return (bool) ($result['already_paid'] ?? false);
        } catch (\Throwable $e) {
            Log::warning('Failed to expire gateway checkout', [
                'transaction_uuid' => $transaction->uuid,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
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
