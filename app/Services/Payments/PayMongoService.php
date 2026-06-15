<?php

namespace App\Services\Payments;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PayMongoService implements PaymentServiceInterface
{
    private string $secretKey;
    private string $publicKey;
    private string $webhookSecret;
    private string $baseUrl = 'https://api.paymongo.com/v1';

    public function __construct()
    {
        $this->secretKey = trim((string) (config('services.paymongo.secret_key') ?? ''));
        $this->publicKey = trim((string) (config('services.paymongo.public_key') ?? ''));
        $this->webhookSecret = trim((string) (config('services.paymongo.webhook_secret') ?? ''));
    }

    /**
     * PayMongo uses HTTP Basic auth; an empty secret yields no Authorization header and API returns "Missing authorization header".
     */
    private function ensureSecretKeyConfigured(): void
    {
        if ($this->secretKey === '') {
            throw new Exception(
                'PayMongo secret key is missing. Set PAYMONGO_SECRET_KEY in .env. ' .
                'If it is already set, check for duplicate PAYMONGO_SECRET_KEY lines — in .env the last value wins.'
            );
        }
    }

    /**
     * Create a checkout session (hosted checkout page)
     *
     * @param array $paymentData
     * @return array
     * @throws Exception
     */
    public function createPayment(array $paymentData): array
    {
        try {
            $this->ensureSecretKeyConfigured();

            // Build line items for the checkout
            $lineItems = [
                [
                    'currency' => $paymentData['currency'] ?? 'PHP',
                    'amount' => (int) ($paymentData['amount'] * 100), // Convert to centavos
                    'description' => $paymentData['description'] ?? 'Ticket Purchase',
                    'name' => $paymentData['description'] ?? 'Ticket Purchase',
                    'quantity' => 1
                ]
            ];

            $attributes = [
                'line_items' => $lineItems,
                'payment_method_types' => $paymentData['payment_methods'] ?? [
                    'shopee_pay',
                    'qrph',
                    'billease',
                    'card',
                    'dob',
                    'dob_ubp',
                    'brankas_bdo',
                    'brankas_landbank',
                    'brankas_metrobank',
                    'gcash',
                    'grab_pay',
                    'paymaya'
                ],
                'success_url' => $paymentData['return_url'] ?? config('app.frontend_url') . '/payment/success',
                'cancel_url' => $paymentData['cancel_url'] ?? config('app.frontend_url') . '/payment/cancel',
                'description' => $paymentData['description'] ?? 'Ticket Purchase',
                'reference_number' => $paymentData['reference_id'] ?? null,
                'metadata' => $paymentData['metadata'] ?? []
            ];

            // Add statement descriptor if provided
            if (isset($paymentData['statement_descriptor'])) {
                $attributes['statement_descriptor'] = $paymentData['statement_descriptor'];
            }

            $response = Http::withBasicAuth($this->secretKey, '')
                ->post("{$this->baseUrl}/checkout_sessions", [
                    'data' => [
                        'attributes' => $attributes
                    ]
                ]);

            if ($response->failed()) {
                throw new Exception('PayMongo API Error: ' . $response->body());
            }

            $data = $response->json();

            return [
                'success' => true,
                'payment_id' => $data['data']['id'],
                'checkout_url' => $data['data']['attributes']['checkout_url'],
                'status' => $data['data']['attributes']['status'],
                'amount' => $data['data']['attributes']['line_items'][0]['amount'] / 100,
                'currency' => $data['data']['attributes']['line_items'][0]['currency'],
                'raw_response' => $data
            ];
        } catch (Exception $e) {
            Log::error('PayMongo createPayment error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Create a payment intent (for custom integration)
     *
     * @param array $paymentData
     * @return array
     * @throws Exception
     */
    public function createPaymentIntent(array $paymentData): array
    {
        try {
            $this->ensureSecretKeyConfigured();

            $response = Http::withBasicAuth($this->secretKey, '')
                ->post("{$this->baseUrl}/payment_intents", [
                    'data' => [
                        'attributes' => [
                            'amount' => (int) ($paymentData['amount'] * 100), // Convert to centavos
                            'payment_method_allowed' => $paymentData['payment_methods'] ?? [
                                'card',
                                'gcash',
                                'grab_pay',
                                'paymaya',
                                'dob',
                                'qrph'
                            ],
                            'payment_method_options' => [
                                'card' => [
                                    'request_three_d_secure' => 'automatic'
                                ]
                            ],
                            'currency' => $paymentData['currency'] ?? 'PHP',
                            'description' => $paymentData['description'] ?? 'Ticket Purchase',
                            'statement_descriptor' => $paymentData['statement_descriptor'] ?? 'TICKET',
                            'metadata' => $paymentData['metadata'] ?? []
                        ]
                    ]
                ]);

            if ($response->failed()) {
                throw new Exception('PayMongo API Error: ' . $response->body());
            }

            $data = $response->json();

            return [
                'success' => true,
                'payment_id' => $data['data']['id'],
                'client_key' => $data['data']['attributes']['client_key'],
                'status' => $data['data']['attributes']['status'],
                'amount' => $data['data']['attributes']['amount'] / 100,
                'currency' => $data['data']['attributes']['currency'],
                'raw_response' => $data
            ];
        } catch (Exception $e) {
            Log::error('PayMongo createPaymentIntent error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Capture/confirm a payment intent
     *
     * @param string $paymentId
     * @param array $additionalData
     * @return array
     * @throws Exception
     */
    public function capturePayment(string $paymentId, array $additionalData = []): array
    {
        try {
            $this->ensureSecretKeyConfigured();

            $response = Http::withBasicAuth($this->secretKey, '')
                ->post("{$this->baseUrl}/payment_intents/{$paymentId}/attach", [
                    'data' => [
                        'attributes' => [
                            'payment_method' => $additionalData['payment_method_id'],
                            'client_key' => $additionalData['client_key'] ?? null,
                            'return_url' => $additionalData['return_url'] ?? config('app.url') . '/payment/return'
                        ]
                    ]
                ]);

            if ($response->failed()) {
                throw new Exception('PayMongo API Error: ' . $response->body());
            }

            $data = $response->json();

            return [
                'success' => true,
                'payment_id' => $data['data']['id'],
                'status' => $data['data']['attributes']['status'],
                'amount' => $data['data']['attributes']['amount'] / 100,
                'currency' => $data['data']['attributes']['currency'],
                'raw_response' => $data
            ];
        } catch (Exception $e) {
            Log::error('PayMongo capturePayment error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Refund a payment
     *
     * @param string $paymentId
     * @param float $amount
     * @param string $reason
     * @return array
     * @throws Exception
     */
    public function refundPayment(string $paymentId, float $amount, string $reason = ''): array
    {
        try {
            $this->ensureSecretKeyConfigured();

            $response = Http::withBasicAuth($this->secretKey, '')
                ->post("{$this->baseUrl}/refunds", [
                    'data' => [
                        'attributes' => [
                            'amount' => (int) ($amount * 100), // Convert to centavos
                            'payment_intent' => $paymentId,
                            'reason' => $reason ?: 'requested_by_customer',
                            'notes' => $reason
                        ]
                    ]
                ]);

            if ($response->failed()) {
                throw new Exception('PayMongo API Error: ' . $response->body());
            }

            $data = $response->json();

            return [
                'success' => true,
                'refund_id' => $data['data']['id'],
                'status' => $data['data']['attributes']['status'],
                'amount' => $data['data']['attributes']['amount'] / 100,
                'currency' => $data['data']['attributes']['currency'],
                'raw_response' => $data
            ];
        } catch (Exception $e) {
            Log::error('PayMongo refundPayment error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get payment status (handles both checkout sessions and payment intents)
     *
     * @param string $paymentId
     * @return array
     * @throws Exception
     */
    public function getPaymentStatus(string $paymentId): array
    {
        try {
            $this->ensureSecretKeyConfigured();

            // Determine if this is a checkout session or payment intent based on ID prefix
            $isCheckoutSession = str_starts_with($paymentId, 'cs_');

            if ($isCheckoutSession) {
                return $this->getCheckoutSessionStatus($paymentId);
            }

            // Default to payment intent
            $response = Http::withBasicAuth($this->secretKey, '')
                ->get("{$this->baseUrl}/payment_intents/{$paymentId}");

            if ($response->failed()) {
                throw new Exception('PayMongo API Error: ' . $response->body());
            }

            $data = $response->json();

            return [
                'success' => true,
                'payment_id' => $data['data']['id'],
                'status' => $data['data']['attributes']['status'],
                'amount' => $data['data']['attributes']['amount'] / 100,
                'currency' => $data['data']['attributes']['currency'],
                'raw_response' => $data
            ];
        } catch (Exception $e) {
            Log::error('PayMongo getPaymentStatus error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get checkout session status
     *
     * @param string $checkoutSessionId
     * @return array
     * @throws Exception
     */
    public function getCheckoutSessionStatus(string $checkoutSessionId): array
    {
        try {
            $this->ensureSecretKeyConfigured();

            $response = Http::withBasicAuth($this->secretKey, '')
                ->get("{$this->baseUrl}/checkout_sessions/{$checkoutSessionId}");

            if ($response->failed()) {
                throw new Exception('PayMongo API Error: ' . $response->body());
            }

            $data = $response->json();
            $attributes = $data['data']['attributes'];

            // Determine actual payment status by checking payment_intent or payments
            // The checkout session status can remain "active" even after successful payment
            $status = 'pending';

            // First check if there's a payment_intent with succeeded status
            if (isset($attributes['payment_intent']['attributes']['status'])) {
                $paymentIntentStatus = $attributes['payment_intent']['attributes']['status'];
                if ($paymentIntentStatus === 'succeeded') {
                    $status = 'succeeded';
                } elseif (in_array($paymentIntentStatus, ['failed', 'cancelled'])) {
                    $status = 'failed';
                }
            }

            // Also check payments array for paid status
            if ($status === 'pending' && isset($attributes['payments']) && is_array($attributes['payments'])) {
                foreach ($attributes['payments'] as $payment) {
                    if (isset($payment['attributes']['status']) && $payment['attributes']['status'] === 'paid') {
                        $status = 'succeeded';
                        break;
                    }
                }
            }

            // Fallback to checkout session status if no payment_intent or payments found
            if ($status === 'pending') {
                $status = match ($attributes['status']) {
                    'paid' => 'succeeded',
                    'expired' => 'failed',
                    default => 'pending'
                };
            }

            return [
                'success' => true,
                'payment_id' => $data['data']['id'],
                'status' => $status,
                'amount' => $attributes['line_items'][0]['amount'] / 100,
                'currency' => $attributes['line_items'][0]['currency'],
                'payment_intent_id' => $attributes['payment_intent']['id'] ?? null,
                'raw_response' => $data
            ];
        } catch (Exception $e) {
            Log::error('PayMongo getCheckoutSessionStatus error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Verify webhook signature
     *
     * @param string $payload
     * @param string $signature
     * @return bool
     */
    public function verifyWebhook(string $payload, string $signature): bool
    {
        try {
            $computedSignature = hash_hmac('sha256', $payload, $this->webhookSecret);
            return hash_equals($signature, $computedSignature);
        } catch (Exception $e) {
            Log::error('PayMongo webhook verification error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Create a payment method
     *
     * @param array $paymentMethodData
     * @return array
     * @throws Exception
     */
    public function createPaymentMethod(array $paymentMethodData): array
    {
        try {
            if ($this->publicKey === '') {
                throw new Exception('PayMongo public key is missing. Set PAYMONGO_PUBLIC_KEY in .env.');
            }

            $response = Http::withBasicAuth($this->publicKey, '')
                ->post("{$this->baseUrl}/payment_methods", [
                    'data' => [
                        'attributes' => $paymentMethodData
                    ]
                ]);

            if ($response->failed()) {
                throw new Exception('PayMongo API Error: ' . $response->body());
            }

            $data = $response->json();

            return [
                'success' => true,
                'payment_method_id' => $data['data']['id'],
                'type' => $data['data']['attributes']['type'],
                'raw_response' => $data
            ];
        } catch (Exception $e) {
            Log::error('PayMongo createPaymentMethod error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
