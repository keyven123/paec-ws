<?php

namespace App\Services\Payments;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PayPalService implements PaymentServiceInterface
{
    private ?string $clientId;
    private ?string $clientSecret;
    private string $mode;
    private ?string $webhookId;
    private string $baseUrl;
    private ?string $accessToken = null;

    public function __construct()
    {
        $this->clientId = config('services.paypal.client_id');
        $this->clientSecret = config('services.paypal.client_secret');
        $this->mode = config('services.paypal.mode', 'sandbox');
        $this->webhookId = config('services.paypal.webhook_id');

        // Validate credentials are set
        if (empty($this->clientId) || empty($this->clientSecret)) {
            Log::warning('PayPal credentials not configured', [
                'client_id_set' => !empty($this->clientId),
                'client_secret_set' => !empty($this->clientSecret),
                'mode' => $this->mode
            ]);
        }

        $this->baseUrl = $this->mode === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
    }

    /**
     * Get access token for PayPal API
     *
     * @return string
     * @throws Exception
     */
    private function getAccessToken(): string
    {
        if ($this->accessToken) {
            return $this->accessToken;
        }

        // Validate credentials before attempting authentication
        if (empty($this->clientId) || empty($this->clientSecret)) {
            $missing = [];
            if (empty($this->clientId)) {
                $missing[] = 'PAYPAL_CLIENT_ID';
            }
            if (empty($this->clientSecret)) {
                $missing[] = 'PAYPAL_CLIENT_SECRET';
            }

            $errorMsg = 'PayPal credentials are not configured. Missing: ' . implode(', ', $missing) . '. ';
            $errorMsg .= 'Please set these in your .env file. Note: This system uses PayPal REST API (backend), which requires BOTH CLIENT_ID and CLIENT_SECRET. ';
            $errorMsg .= 'Get your credentials from: https://developer.paypal.com/dashboard/';

            Log::error($errorMsg, [
                'mode' => $this->mode,
                'base_url' => $this->baseUrl,
                'client_id_set' => !empty($this->clientId),
                'client_secret_set' => !empty($this->clientSecret),
                'client_id_value' => !empty($this->clientId) ? substr($this->clientId, 0, 10) . '...' : 'NOT SET'
            ]);
            throw new Exception($errorMsg);
        }

        try {
            $response = Http::withBasicAuth($this->clientId, $this->clientSecret)
                ->asForm()
                ->post("{$this->baseUrl}/v1/oauth2/token", [
                    'grant_type' => 'client_credentials'
                ]);

            if ($response->failed()) {
                $errorBody = $response->body();
                $errorData = $response->json();

                // Provide more helpful error messages
                $errorMessage = 'PayPal authentication failed. ';

                if (isset($errorData['error'])) {
                    if ($errorData['error'] === 'invalid_client') {
                        $errorMessage .= 'Invalid client credentials. Please check your PAYPAL_CLIENT_ID and PAYPAL_CLIENT_SECRET in .env file. ';
                        $errorMessage .= 'Make sure you are using ' . ($this->mode === 'sandbox' ? 'sandbox' : 'live') . ' credentials for ' . $this->mode . ' mode.';
                    } else {
                        $errorMessage .= $errorData['error_description'] ?? $errorData['error'];
                    }
                } else {
                    $errorMessage .= $errorBody;
                }

                Log::error('PayPal authentication failed', [
                    'status' => $response->status(),
                    'error' => $errorData ?? $errorBody,
                    'mode' => $this->mode,
                    'base_url' => $this->baseUrl,
                    'client_id_set' => !empty($this->clientId),
                    'client_secret_set' => !empty($this->clientSecret)
                ]);

                throw new Exception($errorMessage);
            }

            $data = $response->json();

            if (!isset($data['access_token'])) {
                Log::error('PayPal access token not found in response', ['response' => $data]);
                throw new Exception('PayPal authentication failed: Access token not received');
            }

            $this->accessToken = $data['access_token'];

            return $this->accessToken;
        } catch (Exception $e) {
            // Don't log again if we already logged above
            if (!str_contains($e->getMessage(), 'PayPal authentication failed')) {
                Log::error('PayPal getAccessToken error: ' . $e->getMessage());
            }
            throw $e;
        }
    }

    /**
     * Create a payment order
     *
     * @param array $paymentData
     * @return array
     * @throws Exception
     */
    public function createPayment(array $paymentData): array
    {
        try {
            $accessToken = $this->getAccessToken();

            $orderData = [
                'intent' => 'CAPTURE',
                'purchase_units' => [
                    [
                        'reference_id' => $paymentData['reference_id'] ?? uniqid(),
                        'description' => $paymentData['description'] ?? 'Ticket Purchase',
                        'amount' => [
                            'currency_code' => $paymentData['currency'] ?? 'PHP',
                            'value' => number_format($paymentData['amount'], 2, '.', '')
                        ]
                    ]
                ],
                'application_context' => [
                    'return_url' => $paymentData['return_url'] ?? config('app.url') . '/payment/success',
                    'cancel_url' => $paymentData['cancel_url'] ?? config('app.url') . '/payment/cancel',
                    'brand_name' => $paymentData['brand_name'] ?? config('app.name'),
                    'landing_page' => $paymentData['landing_page'] ?? 'LOGIN',
                    'shipping_preference' => $paymentData['shipping_preference'] ?? 'NO_SHIPPING',
                    'user_action' => $paymentData['user_action'] ?? 'PAY_NOW'
                ]
            ];

            $response = Http::withToken($accessToken)
                ->post("{$this->baseUrl}/v2/checkout/orders", $orderData);

            if ($response->failed()) {
                throw new Exception('PayPal API Error: ' . $response->body());
            }

            $data = $response->json();

            return [
                'success' => true,
                'payment_id' => $data['id'],
                'status' => $data['status'],
                'approval_url' => collect($data['links'])->firstWhere('rel', 'approve')['href'] ?? null,
                'raw_response' => $data
            ];
        } catch (Exception $e) {
            Log::error('PayPal createPayment error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Create a payment order intended for the Advanced Credit / Debit Card
     * (ACDC) flow rendered with the PayPal JS SDK Card Fields component.
     *
     * Unlike createPayment(), this never returns an approval URL: the buyer
     * stays on our checkout page and the SDK confirms the payment source
     * client-side, after which we capture the order via capturePayment().
     *
     * @param array $paymentData
     * @return array
     */
    public function createCardOrder(array $paymentData): array
    {
        try {
            $accessToken = $this->getAccessToken();

            $orderData = [
                'intent' => 'CAPTURE',
                'purchase_units' => [
                    [
                        'reference_id' => $paymentData['reference_id'] ?? uniqid(),
                        'description' => $paymentData['description'] ?? 'Ticket Purchase',
                        'amount' => [
                            'currency_code' => $paymentData['currency'] ?? 'PHP',
                            'value' => number_format($paymentData['amount'], 2, '.', '')
                        ]
                    ]
                ],
                'payment_source' => [
                    'card' => [
                        'experience_context' => [
                            'shipping_preference' => 'NO_SHIPPING',
                            'brand_name' => $paymentData['brand_name'] ?? config('app.name'),
                        ]
                    ]
                ]
            ];

            $response = Http::withToken($accessToken)
                ->post("{$this->baseUrl}/v2/checkout/orders", $orderData);

            if ($response->failed()) {
                throw new Exception('PayPal API Error: ' . $response->body());
            }

            $data = $response->json();

            return [
                'success' => true,
                'payment_id' => $data['id'],
                'status' => $data['status'],
                'raw_response' => $data,
            ];
        } catch (Exception $e) {
            Log::error('PayPal createCardOrder error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Capture/confirm a payment order
     *
     * @param string $paymentId
     * @param array $additionalData
     * @return array
     * @throws Exception
     */
    public function capturePayment(string $paymentId, array $additionalData = []): array
    {
        try {
            $accessToken = $this->getAccessToken();
            $captureUrl = "{$this->baseUrl}/v2/checkout/orders/{$paymentId}/capture";

            // PayPal capture endpoint accepts an empty JSON body {} or no body
            // Using withBody() to explicitly send empty JSON object
            $response = Http::withToken($accessToken)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ])
                ->withBody('{}', 'application/json')
                ->post($captureUrl);

            if ($response->failed()) {
                $errorBody = $response->body();
                $errorData = $response->json();

                Log::error('PayPal capture API failed', [
                    'status_code' => $response->status(),
                    'error_body' => $errorBody,
                    'error_data' => $errorData,
                    'payment_id' => $paymentId
                ]);

                // Parse PayPal error and provide user-friendly message
                $errorMessage = $this->parsePayPalError($errorData, $errorBody);
                throw new Exception($errorMessage);
            }

            $data = $response->json();
            $captureDetails = $data['purchase_units'][0]['payments']['captures'][0] ?? null;

            return [
                'success' => true,
                'payment_id' => $data['id'],
                'capture_id' => $captureDetails['id'] ?? null,
                'status' => $data['status'],
                'amount' => $captureDetails['amount']['value'] ?? null,
                'currency' => $captureDetails['amount']['currency_code'] ?? null,
                'raw_response' => $data
            ];
        } catch (Exception $e) {
            Log::error('PayPal capturePayment error', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Refund a payment
     *
     * @param string $paymentId This should be the capture ID for PayPal
     * @param float $amount
     * @param string $reason
     * @return array
     * @throws Exception
     */
    public function refundPayment(string $paymentId, float $amount, string $reason = ''): array
    {
        try {
            $accessToken = $this->getAccessToken();

            $refundData = [
                'amount' => [
                    'value' => number_format($amount, 2, '.', ''),
                    'currency_code' => 'PHP' // You might want to make this configurable
                ],
                'note_to_payer' => $reason ?: 'Refund for ticket purchase'
            ];

            $response = Http::withToken($accessToken)
                ->post("{$this->baseUrl}/v2/payments/captures/{$paymentId}/refund", $refundData);

            if ($response->failed()) {
                throw new Exception('PayPal API Error: ' . $response->body());
            }

            $data = $response->json();

            return [
                'success' => true,
                'refund_id' => $data['id'],
                'status' => $data['status'],
                'amount' => $data['amount']['value'],
                'currency' => $data['amount']['currency_code'],
                'raw_response' => $data
            ];
        } catch (Exception $e) {
            Log::error('PayPal refundPayment error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get payment status
     *
     * @param string $paymentId
     * @return array
     * @throws Exception
     */
    public function getPaymentStatus(string $paymentId): array
    {
        try {
            $accessToken = $this->getAccessToken();

            $response = Http::withToken($accessToken)
                ->get("{$this->baseUrl}/v2/checkout/orders/{$paymentId}");

            if ($response->failed()) {
                throw new Exception('PayPal API Error: ' . $response->body());
            }

            $data = $response->json();
            $captureDetails = $data['purchase_units'][0]['payments']['captures'][0] ?? null;

            return [
                'success' => true,
                'payment_id' => $data['id'],
                'status' => $data['status'],
                'amount' => $captureDetails['amount']['value'] ?? null,
                'currency' => $captureDetails['amount']['currency_code'] ?? null,
                'raw_response' => $data
            ];
        } catch (Exception $e) {
            Log::error('PayPal getPaymentStatus error: ' . $e->getMessage());
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
            $accessToken = $this->getAccessToken();

            // PayPal webhook verification is more complex
            // This is a simplified version - you might want to implement full verification
            $headers = request()->headers->all();

            $verificationData = [
                'auth_algo' => $headers['paypal-auth-algo'][0] ?? '',
                'cert_id' => $headers['paypal-cert-id'][0] ?? '',
                'transmission_id' => $headers['paypal-transmission-id'][0] ?? '',
                'transmission_sig' => $signature,
                'transmission_time' => $headers['paypal-transmission-time'][0] ?? '',
                'webhook_id' => $this->webhookId,
                'webhook_event' => json_decode($payload, true)
            ];

            $response = Http::withToken($accessToken)
                ->post("{$this->baseUrl}/v1/notifications/verify-webhook-signature", $verificationData);

            if ($response->failed()) {
                return false;
            }

            $data = $response->json();
            return $data['verification_status'] === 'SUCCESS';
        } catch (Exception $e) {
            Log::error('PayPal webhook verification error: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Parse PayPal error response and return user-friendly message
     *
     * @param array|null $errorData
     * @param string $errorBody
     * @return string
     */
    private function parsePayPalError(?array $errorData, string $errorBody): string
    {
        if (!$errorData) {
            return 'Payment processing failed. Please try again or use a different payment method.';
        }

        $errorName = $errorData['name'] ?? '';
        $errorMessage = $errorData['message'] ?? '';
        $details = $errorData['details'] ?? [];

        // Check for specific error issues
        $issue = null;
        if (!empty($details) && is_array($details)) {
            $issue = $details[0]['issue'] ?? null;
        }

        // Map common PayPal errors to user-friendly messages
        $userFriendlyMessages = [
            'INSTRUMENT_DECLINED' => 'Your payment method was declined. Please try a different payment method or contact your bank.',
            'PAYER_ACTION_REQUIRED' => 'Additional action is required to complete your payment. Please check your email or PayPal account.',
            'INSUFFICIENT_FUNDS' => 'Insufficient funds. Please use a different payment method or add funds to your account.',
            'CARD_DECLINED' => 'Your card was declined. Please try a different card or contact your bank.',
            'INVALID_EXPIRY_DATE' => 'Invalid card expiry date. Please check your card details and try again.',
            'INVALID_CVV' => 'Invalid security code. Please check your card details and try again.',
            'UNPROCESSABLE_ENTITY' => 'Payment could not be processed. Please try a different payment method.',
        ];

        // Return user-friendly message if available
        if ($issue && isset($userFriendlyMessages[$issue])) {
            return $userFriendlyMessages[$issue];
        }

        // Fallback to PayPal's message if available
        if ($errorMessage) {
            return $errorMessage;
        }

        // Last resort: generic message
        return 'Payment processing failed. Please try again or use a different payment method.';
    }
}
