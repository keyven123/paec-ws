<?php

namespace App\Services\Payments;

use App\Constants\GeneralConstants;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Paynamics PayGate workflow API. Every call is authenticated with the gateway's API username and
 * password (HTTP Basic) and signed with SHA-512 over the merchant ID, the request fields and the
 * merchant key.
 */
class PaynamicsService implements PaymentServiceInterface
{
    /**
     * Payment channels offered at checkout, keyed by the value the storefront sends.
     */
    public const CHANNELS = [
        'card' => ['pmethod' => 'creditcard', 'pchannel' => ''],
        'gcash' => ['pmethod' => 'wallet', 'pchannel' => 'gc'],
        'maya' => ['pmethod' => 'wallet', 'pchannel' => 'paymaya_ph'],
        'grabpay' => ['pmethod' => 'wallet', 'pchannel' => 'grabpay_ph'],
        'shopeepay' => ['pmethod' => 'wallet', 'pchannel' => 'shopeepay_ph'],
        'bdo' => ['pmethod' => 'onlinebanktransfer', 'pchannel' => 'br_bdo_ph'],
        'bpi' => ['pmethod' => 'onlinebanktransfer', 'pchannel' => 'bpi_online'],
        'landbank' => ['pmethod' => 'onlinebanktransfer', 'pchannel' => 'br_landbank_ph'],
        'unionbank' => ['pmethod' => 'onlinebanktransfer', 'pchannel' => 'ubp_online'],
        'billease' => ['pmethod' => 'installment_fintech', 'pchannel' => 'billease_install_ph'],
    ];

    /** Paynamics codes for a channel that is not enabled on the merchant account. */
    public const CHANNEL_UNAVAILABLE_CODES = ['GR009', 'GR150'];

    public const PAYBIZ_WALLET_ID_PATTERN = '/^[A-Za-z0-9_-]+$/';

    public const CREDENTIALS_ACCEPTED = 'accepted';
    public const CREDENTIALS_REJECTED = 'rejected';
    public const CREDENTIALS_BAD_SIGNATURE = 'bad_signature';
    public const CREDENTIALS_UNREACHABLE = 'unreachable';
    public const CREDENTIALS_BLOCKED = 'blocked';

    private const SUCCESS_CODES = ['GR001', 'GR002'];
    private const PENDING_CODE = 'GR033';
    private const FAILED_CODE = 'GR003';
    private const CANCELLED_CODE = 'GR053';
    private const SIGNATURE_FAILED_CODE = 'GR036';
    private const INVALID_AUTHENTICATION_CODE = 'GR153';

    public function __construct(
        private readonly string $merchantId,
        private readonly string $merchantKey,
        private readonly string $apiUsername,
        private readonly string $apiPassword,
        private readonly string $baseUrl,
        private readonly ?string $paybizWalletId = null,
    ) {
    }

    /**
     * @throws RuntimeException When production is requested but no live URL is configured.
     */
    public static function baseUrlFor(?string $environment): string
    {
        if ($environment !== GeneralConstants::PAYMENT_GATEWAY_ENVIRONMENTS['PRODUCTION']) {
            return rtrim((string) config('services.paynamics.testing_url'), '/');
        }

        $url = (string) config('services.paynamics.production_url');
        if ($url === '') {
            throw new RuntimeException('The Paynamics production URL is not configured. Set PAYNAMICS_PRODUCTION_URL.');
        }

        return rtrim($url, '/');
    }

    /**
     * Creates a hosted Paynamics payment link for one channel.
     *
     * Expects reference_id (sent as the Paynamics request_id), amount, currency, description,
     * return_url, cancel_url, notification_url, payment_channel (a CHANNELS key), and optional
     * customer details (first_name, last_name, email, phone_number, address fields).
     */
    public function createPayment(array $paymentData): array
    {
        $channel = self::CHANNELS[$paymentData['payment_channel'] ?? ''] ?? null;
        if ($channel === null) {
            return ['success' => false, 'error' => 'Choose how you want to pay with Paynamics.'];
        }

        $requestId = (string) $paymentData['reference_id'];
        $amount = $this->money($paymentData['amount']);
        $currency = $paymentData['currency'] ?? 'PHP';

        $transaction = [
            'request_id' => $requestId,
            'notification_url' => (string) ($paymentData['notification_url'] ?? ''),
            'response_url' => (string) $paymentData['return_url'],
            'cancel_url' => (string) $paymentData['cancel_url'],
            'pmethod' => $channel['pmethod'],
            'pchannel' => $channel['pchannel'],
            'payment_action' => 'url_link',
            'collection_method' => 'single_pay',
            'payment_notification_status' => '1',
            'payment_notification_channel' => '1',
            'amount' => $amount,
            'currency' => $currency,
        ];
        if ($channel['pmethod'] === 'creditcard') {
            $transaction['trx_type'] = 'sale';
        }
        // schedule, deferred_period, deferred_time, dp_balance_info and descriptor_note are unused but still signed as ''.
        $transaction['signature'] = $this->sign([
            $this->merchantId,
            $requestId,
            $transaction['notification_url'],
            $transaction['response_url'],
            $transaction['cancel_url'],
            $transaction['pmethod'],
            $transaction['payment_action'],
            '',
            $transaction['collection_method'],
            '',
            '',
            '',
            $amount,
            $currency,
            '',
            $transaction['payment_notification_status'],
            $transaction['payment_notification_channel'],
        ]);

        $customer = $paymentData['customer'] ?? [];
        $customerInfo = [
            'fname' => (string) ($customer['first_name'] ?? ''),
            'lname' => (string) ($customer['last_name'] ?? ''),
            'mname' => (string) ($customer['middle_name'] ?? ''),
            'email' => (string) ($customer['email'] ?? ''),
            'phone' => '',
            'mobile' => (string) ($customer['phone_number'] ?? ''),
            'dob' => '',
        ];
        $customerInfo['signature'] = $this->sign(array_values($customerInfo));

        $body = [
            'transaction' => $transaction,
            'customer_info' => $customerInfo,
            'billing_info' => [
                'billing_address1' => (string) ($customer['address_line_1'] ?? ''),
                'billing_address2' => (string) ($customer['address_line_2'] ?? ''),
                'billing_city' => (string) ($customer['city'] ?? ''),
                'billing_state' => (string) ($customer['region'] ?? ''),
                'billing_country' => (string) ($customer['country'] ?? '') ?: 'PH',
                'billing_zip' => (string) ($customer['postal_code'] ?? ''),
            ],
            // A single line for the order total keeps the totals consistent after fees and discounts.
            'order_details' => [
                'orders' => [[
                    'itemname' => Str::limit((string) ($paymentData['description'] ?? 'Ticket purchase'), 100, ''),
                    'quantity' => 1,
                    'unitprice' => $amount,
                    'totalprice' => $amount,
                ]],
                'subtotalprice' => $amount,
                'shippingprice' => '0.00',
                'discountamount' => '0.00',
                'totalorderamount' => $amount,
            ],
        ];

        if (filled($this->paybizWalletId)) {
            $body['settlement_information'] = [
                $this->settlement($this->paybizWalletId, $amount, $currency, 'Sale ' . $requestId, 'STL-' . $requestId),
            ];
        }

        try {
            $response = $this->client()->post('/paygate/transactions/', $body);
        } catch (ConnectionException $e) {
            Log::error('Paynamics createPayment could not connect', ['request_id' => $requestId, 'error' => $e->getMessage()]);

            return ['success' => false, 'error' => 'Paynamics could not be reached. Please try again.'];
        }

        $data = (array) $response->json();
        $checkoutUrl = $this->paymentLink($data);

        if ($response->failed() || $checkoutUrl === null) {
            Log::error('Paynamics createPayment failed', [
                'request_id' => $requestId,
                'status' => $response->status(),
                'response' => $data,
            ]);

            return [
                'success' => false,
                'error' => 'Paynamics: ' . ($data['response_message'] ?? 'no payment link was returned.'),
                'channel_unavailable' => in_array($data['response_code'] ?? null, self::CHANNEL_UNAVAILABLE_CODES, true),
                'raw_response' => $data,
            ];
        }

        return [
            'success' => true,
            'payment_id' => $requestId,
            'checkout_url' => $checkoutUrl,
            'status' => 'pending',
            'raw_response' => $data,
        ];
    }

    /**
     * Paynamics settles sales on its own, so there is nothing to capture; report the current status.
     */
    public function capturePayment(string $paymentId, array $additionalData = []): array
    {
        return $this->getPaymentStatus($paymentId);
    }

    /**
     * @param string $paymentId The Paynamics response_id of the original sale (org_trxid).
     */
    public function refundPayment(string $paymentId, float $amount, string $reason = ''): array
    {
        $requestId = $this->newRequestId();
        $ipAddress = (string) request()->server('SERVER_ADDR', '');
        $amountValue = $this->money($amount);

        $body = [
            'merchantid' => $this->merchantId,
            'request_id' => $requestId,
            'org_trxid' => $paymentId,
            'ip_address' => $ipAddress,
            'amount' => $amountValue,
            'notification_url' => '',
            'response_url' => '',
            'reason' => $reason,
            'signature' => $this->sign([$this->merchantId, $requestId, $paymentId, $ipAddress, '', '', $amountValue, $reason]),
        ];

        try {
            $response = $this->client()->post('/paygate/transactions/refund', $body);
        } catch (ConnectionException $e) {
            Log::error('Paynamics refund could not connect', ['org_trxid' => $paymentId, 'error' => $e->getMessage()]);

            return ['success' => false, 'error' => 'Paynamics could not be reached. Please try again.'];
        }

        $data = (array) $response->json();
        $code = $data['response_code'] ?? null;
        $accepted = $response->successful()
            && (in_array($code, self::SUCCESS_CODES, true) || $code === self::PENDING_CODE);

        return [
            'success' => $accepted,
            'refund_id' => $data['response_id'] ?? $requestId,
            'status' => $code === self::PENDING_CODE ? 'pending' : ($accepted ? 'completed' : 'failed'),
            'error' => $accepted ? null : ($data['response_message'] ?? 'Paynamics did not accept the refund.'),
            'raw_response' => $data,
        ];
    }

    /**
     * Looks the sale up by our request_id (org_trxid2) and maps its Paynamics response code to
     * paid / pending / failed / cancelled. Unknown codes are reported as unverified rather than
     * guessed, so an order is never failed or fulfilled on an ambiguous answer.
     */
    public function getPaymentStatus(string $paymentId): array
    {
        $queryId = $this->newRequestId();
        $body = [
            'request_id' => $queryId,
            'org_trxid2' => $paymentId,
            'signature' => $this->sign([$this->merchantId, $queryId, $paymentId]),
        ];

        try {
            $response = $this->client()->post('/paygate/transactions/query', $body);
        } catch (ConnectionException $e) {
            Log::error('Paynamics query could not connect', ['request_id' => $paymentId, 'error' => $e->getMessage()]);

            return ['success' => false, 'error' => 'Paynamics could not be reached. Please try again.'];
        }

        $data = (array) $response->json();
        $result = $response->successful() ? $this->findTransactionResult($data, $paymentId) : null;
        $code = $result['response_code'] ?? null;

        $status = match (true) {
            in_array($code, self::SUCCESS_CODES, true) => 'paid',
            $code === self::PENDING_CODE => 'pending',
            $code === self::FAILED_CODE => 'failed',
            $code === self::CANCELLED_CODE => 'cancelled',
            default => null,
        };

        if ($status === null) {
            Log::warning('Paynamics query did not return a usable status', [
                'request_id' => $paymentId,
                'status' => $response->status(),
                'response' => $data,
            ]);

            return [
                'success' => false,
                'error' => $result['response_message'] ?? $data['response_message'] ?? 'Paynamics could not confirm this payment.',
                'raw_response' => $data,
            ];
        }

        return [
            'success' => true,
            'payment_id' => $paymentId,
            'status' => $status,
            'raw_response' => $data,
        ];
    }

    /**
     * Paynamics notifications are confirmed with the signed query API instead of trusting the posted body.
     */
    public function verifyWebhook(string $payload, string $signature): bool
    {
        return false;
    }

    /**
     * Sends a signed query for a request ID that cannot exist, to tell whether this host accepts
     * the API username/password and the merchant ID/key.
     */
    public function checkCredentials(): string
    {
        $queryId = $this->newRequestId();
        $probeId = $this->newRequestId();

        try {
            $response = $this->client()->timeout(30)->post('/paygate/transactions/query', [
                'request_id' => $queryId,
                'org_trxid2' => $probeId,
                'signature' => $this->sign([$this->merchantId, $queryId, $probeId]),
            ]);
        } catch (ConnectionException $e) {
            Log::warning('Paynamics credential check could not connect', ['host' => $this->baseUrl, 'error' => $e->getMessage()]);

            return self::CREDENTIALS_UNREACHABLE;
        }

        $code = $response->json('response_code');

        // PayGate always answers with a JSON response_code; anything else (403, HTML challenge page)
        // comes from a firewall in front of it, usually because this server's IP is not whitelisted.
        $result = match (true) {
            $response->serverError() => self::CREDENTIALS_UNREACHABLE,
            $code === self::SIGNATURE_FAILED_CODE => self::CREDENTIALS_BAD_SIGNATURE,
            $code === self::INVALID_AUTHENTICATION_CODE, $response->status() === 401 => self::CREDENTIALS_REJECTED,
            $response->status() === 403, ! is_string($code) || $code === '' => self::CREDENTIALS_BLOCKED,
            default => self::CREDENTIALS_ACCEPTED,
        };

        if ($result !== self::CREDENTIALS_ACCEPTED) {
            Log::warning('Paynamics credential check failed', [
                'host' => $this->baseUrl,
                'result' => $result,
                'status' => $response->status(),
                'response_code' => $code,
                'response_message' => $response->json('response_message'),
                'body' => Str::limit($response->body(), 500),
            ]);
        }

        return $result;
    }

    /**
     * Split-payment entry that settles $amount of the sale into a Paybiz wallet.
     *
     * @return array<string, string>
     */
    private function settlement(string $walletId, string $amount, string $currency, string $reason, string $settlementId): array
    {
        $entry = [
            'biz_wallet_id' => $walletId,
            'settlement_amount' => $amount,
            'settlement_currency' => $currency,
            'reason' => $reason,
            'settlement_id' => $settlementId,
        ];
        $entry['signature'] = $this->sign(array_values($entry));

        return $entry;
    }

    private function client(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withBasicAuth($this->apiUsername, $this->apiPassword)
            ->acceptJson()
            ->asJson()
            // The PayGate test server's query endpoint regularly takes 15-30 seconds.
            ->timeout(60);
    }

    /**
     * @param array<int, string> $parts
     */
    private function sign(array $parts): string
    {
        return hash('sha512', implode('', $parts) . $this->merchantKey);
    }

    private function money(mixed $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * Paynamics request IDs are at most 32 characters.
     */
    private function newRequestId(): string
    {
        return 'Q' . now()->format('ymdHis') . Str::upper(Str::random(12));
    }

    private function paymentLink(array $data): ?string
    {
        $info = $data['payment_action_info'] ?? null;
        $url = is_array($info) ? ($info['url'] ?? null) : $info;

        return is_string($url) && filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    /**
     * PayGate answers a query with the sale's own result (its request_id is the original one).
     * Only an element carrying our original request_id is trusted, deepest first, so a wrapper
     * describing the query itself is never mistaken for the sale.
     */
    private function findTransactionResult(array $node, string $requestId): ?array
    {
        foreach ($node as $child) {
            if (is_array($child) && ($match = $this->findTransactionResult($child, $requestId)) !== null) {
                return $match;
            }
        }

        return isset($node['response_code']) && ($node['request_id'] ?? null) === $requestId ? $node : null;
    }
}
