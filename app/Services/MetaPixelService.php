<?php

namespace App\Services;

use App\Http\Controllers\MetaPixelSettingController;
use App\Models\CmsSetting;
use App\Models\Event;
use App\Models\Transaction;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaPixelService
{
    /**
     * Resolve project-wide Meta Pixel credentials.
     * Admin CMS settings take priority over .env when enabled.
     *
     * @return array{enabled: bool, pixel_id: string, access_token: string, test_event_code: ?string, source: string}
     */
    public function resolveProjectConfig(): array
    {
        $stored = CmsSetting::getValue(MetaPixelSettingController::SETTING_KEY, []);
        if (! is_array($stored)) {
            $stored = [];
        }

        $dbEnabled = (bool) ($stored['enabled'] ?? false);
        $dbPixelId = trim((string) ($stored['pixel_id'] ?? ''));
        $dbToken = trim((string) ($stored['access_token'] ?? ''));
        $dbTestCode = isset($stored['test_event_code']) && $stored['test_event_code'] !== ''
            ? trim((string) $stored['test_event_code'])
            : null;

        if ($dbEnabled && $dbPixelId !== '' && $dbToken !== '') {
            return [
                'enabled' => true,
                'pixel_id' => $dbPixelId,
                'access_token' => $dbToken,
                'test_event_code' => $dbTestCode,
                'source' => 'settings',
            ];
        }

        $envPixelId = trim((string) config('services.meta.pixel_id', ''));
        $envToken = trim((string) config('services.meta.access_token', ''));
        $envTestCode = config('services.meta.test_event_code');
        $envTestCode = filled($envTestCode) ? trim((string) $envTestCode) : null;

        return [
            'enabled' => $envPixelId !== '' && $envToken !== '',
            'pixel_id' => $envPixelId,
            'access_token' => $envToken,
            'test_event_code' => $envTestCode,
            'source' => 'env',
        ];
    }

    /**
     * Track a Meta Pixel event using Conversions API (project-wide pixel only).
     *
     * @param  string  $eventName  The event name (e.g., 'ViewContent', 'InitiateCheckout', 'AddPaymentInfo', 'Purchase')
     * @param  array  $eventData  Event data including user info, event info, etc.
     * @param  Event|null  $event  Unused for credentials (kept for call-site compatibility)
     */
    public function trackEvent(string $eventName, array $eventData, ?Event $event = null): bool
    {
        $config = $this->resolveProjectConfig();
        $pixelId = $config['pixel_id'];
        $accessToken = $config['access_token'];
        $testEventCode = $config['test_event_code'];

        if (! $config['enabled'] || $pixelId === '' || $accessToken === '') {
            Log::warning('Meta Pixel tracking skipped: Missing pixel ID or access token', [
                'event_name' => $eventName,
                'pixel_id' => $pixelId !== '' ? $pixelId : null,
                'has_access_token' => $accessToken !== '',
                'event_uuid' => $eventData['custom_data']['content_ids'][0] ?? null,
            ]);

            return false;
        }

        try {
            $conversionData = $this->prepareConversionData($eventName, $eventData);

            $payload = [
                'data' => [$conversionData],
                'access_token' => $accessToken,
            ];

            if ($testEventCode) {
                $payload['test_event_code'] = $testEventCode;
            }

            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->post("https://graph.facebook.com/v18.0/{$pixelId}/events", $payload);

            if ($response->successful()) {
                return true;
            }

            $errorBody = $response->json();
            $errorMessage = $errorBody['error']['message'] ?? $response->body();
            $errorCode = $errorBody['error']['code'] ?? null;
            $errorSubcode = $errorBody['error']['error_subcode'] ?? null;

            Log::warning("Meta Pixel event tracking failed: {$eventName}", [
                'pixel_id' => $pixelId,
                'event_name' => $eventName,
                'config_source' => $config['source'],
                'using_event_specific' => false,
                'status' => $response->status(),
                'error_code' => $errorCode,
                'error_subcode' => $errorSubcode,
                'error_message' => $errorMessage,
                'response' => $response->body(),
            ]);

            // Error code 100 with subcode 33 usually means permissions issue or invalid access token
            if ($errorCode == 100 && $errorSubcode == 33) {
                Log::error('Global Meta Pixel access token is invalid or missing permissions.', [
                    'pixel_id' => $pixelId,
                    'access_token_prefix' => substr($accessToken, 0, 20).'...',
                    'suggestion' => 'Verify the project Meta Pixel access token (Admin → Meta Pixel or META_PIXEL_ACCESS_TOKEN) has permissions for pixel '.$pixelId,
                ]);
            }

            return false;
        } catch (\Exception $e) {
            Log::error("Meta Pixel event tracking error: {$eventName}", [
                'pixel_id' => $pixelId,
                'event_name' => $eventName,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Prepare conversion data for Meta Conversions API
     *
     * @param string $eventName
     * @param array $eventData
     * @param string $pixelId
     * @param string|null $testEventCode
     * @return array
     */
    private function prepareConversionData(string $eventName, array $eventData): array
    {
        $data = [
            'event_name' => $eventName,
            'event_time' => $eventData['event_time'] ?? time(),
            'event_id' => $eventData['event_id'] ?? $this->generateEventId(),
            'event_source_url' => $eventData['event_source_url'] ?? null,
            'action_source' => $eventData['action_source'] ?? 'website',
        ];

        // Add user data if available
        if (isset($eventData['user_data'])) {
            $data['user_data'] = $this->hashUserData($eventData['user_data']);
        }

        // Add custom data
        if (isset($eventData['custom_data'])) {
            $data['custom_data'] = $eventData['custom_data'];
        }

        return $data;
    }

    /**
     * Hash user data for privacy compliance
     *
     * @param array $userData
     * @return array
     */
    private function hashUserData(array $userData): array
    {
        $hashed = [];

        // Hash email if provided
        if (isset($userData['email'])) {
            $hashed['em'] = hash('sha256', strtolower(trim($userData['email'])));
        }

        // Hash phone if provided
        if (isset($userData['phone'])) {
            $hashed['ph'] = hash('sha256', preg_replace('/[^0-9]/', '', $userData['phone']));
        }

        // Hash first name if provided
        if (isset($userData['first_name'])) {
            $hashed['fn'] = hash('sha256', strtolower(trim($userData['first_name'])));
        }

        // Hash last name if provided
        if (isset($userData['last_name'])) {
            $hashed['ln'] = hash('sha256', strtolower(trim($userData['last_name'])));
        }

        // Add external ID (user UUID)
        if (isset($userData['external_id'])) {
            $hashed['external_id'] = $userData['external_id'];
        }

        // Add client IP address
        if (isset($userData['client_ip_address'])) {
            $hashed['client_ip_address'] = $userData['client_ip_address'];
        }

        // Add client user agent
        if (isset($userData['client_user_agent'])) {
            $hashed['client_user_agent'] = $userData['client_user_agent'];
        }

        // Add fbp (Facebook browser ID) if available
        if (isset($userData['fbp'])) {
            $hashed['fbp'] = $userData['fbp'];
        }

        // Add fbc (Facebook click ID) if available
        if (isset($userData['fbc'])) {
            $hashed['fbc'] = $userData['fbc'];
        }

        return $hashed;
    }

    /**
     * Generate a unique event ID
     *
     * @return string
     */
    private function generateEventId(): string
    {
        return uniqid('', true);
    }

    /**
     * Track ViewContent event (when user views an event)
     *
     * @param Event $event
     * @param array $userData
     * @param string|null $sourceUrl
     * @return bool
     */
    public function trackViewContent(Event $event, array $userData = [], ?string $sourceUrl = null): bool
    {
        $eventData = [
            'event_time' => time(),
            'event_id' => 'view_' . $event->uuid . '_' . time(),
            'event_source_url' => $sourceUrl,
            'action_source' => 'website',
            'user_data' => $userData,
            'custom_data' => [
                'content_name' => $event->event_name,
                'content_category' => $event->category->name ?? 'Event',
                'content_ids' => [$event->uuid],
                'content_type' => 'event',
                'value' => 0, // View events typically have no value
                'currency' => 'PHP',
            ],
        ];

        return $this->trackEvent('ViewContent', $eventData, $event);
    }

    /**
     * Track InitiateCheckout event (when user clicks "Get Tickets")
     *
     * @param Event $event
     * @param float $value
     * @param array $userData
     * @param string|null $sourceUrl
     * @return bool
     */
    public function trackInitiateCheckout(Event $event, float $value, array $userData = [], ?string $sourceUrl = null): bool
    {
        $eventData = [
            'event_time' => time(),
            'event_id' => 'initiate_' . $event->uuid . '_' . time(),
            'event_source_url' => $sourceUrl,
            'action_source' => 'website',
            'user_data' => $userData,
            'custom_data' => [
                'content_name' => $event->event_name,
                'content_category' => $event->category->name ?? 'Event',
                'content_ids' => [$event->uuid],
                'content_type' => 'event',
                'value' => $value,
                'currency' => 'PHP',
            ],
        ];

        return $this->trackEvent('InitiateCheckout', $eventData, $event);
    }

    /**
     * Track AddPaymentInfo event (when user clicks payment button)
     *
     * @param Event $event
     * @param float $value
     * @param string $paymentMethod
     * @param array $userData
     * @param string|null $sourceUrl
     * @return bool
     */
    public function trackAddPaymentInfo(Event $event, float $value, string $paymentMethod, array $userData = [], ?string $sourceUrl = null): bool
    {
        $eventData = [
            'event_time' => time(),
            'event_id' => 'payment_' . $event->uuid . '_' . time(),
            'event_source_url' => $sourceUrl,
            'action_source' => 'website',
            'user_data' => $userData,
            'custom_data' => [
                'content_name' => $event->event_name,
                'content_category' => $event->category->name ?? 'Event',
                'content_ids' => [$event->uuid],
                'content_type' => 'event',
                'value' => $value,
                'currency' => 'PHP',
            ],
        ];

        return $this->trackEvent('AddPaymentInfo', $eventData, $event);
    }

    /**
     * Track Purchase event (when payment is successful)
     *
     * @param Transaction $transaction
     * @param array $userData
     * @return bool
     */
    public function trackPurchase(Transaction $transaction, array $userData = []): bool
    {
        $event = $transaction->event;

        if (!$event) {
            Log::warning('Meta Pixel Purchase tracking skipped: Transaction has no event', [
                'transaction_uuid' => $transaction->uuid,
            ]);
            return false;
        }

        // Get transaction items
        $items = [];
        $numItems = 0;

        // Load transaction orders if not already loaded
        $transaction->load('transactionOrders.eventTicket');

        if ($transaction->transactionOrders && $transaction->transactionOrders->count() > 0) {
            foreach ($transaction->transactionOrders as $order) {
                $eventTicket = $order->eventTicket;
                $items[] = [
                    'id' => $order->event_ticket_uuid ?? '',
                    'name' => $eventTicket->name ?? 'Ticket',
                    'category' => $eventTicket->code ?? 'Ticket',
                    'quantity' => $order->quantity ?? 1,
                    'item_price' => floatval($order->price ?? 0),
                ];
                $numItems += $order->quantity ?? 1;
            }
        }

        $eventData = [
            'event_time' => $transaction->paid_at ? $transaction->paid_at->timestamp : time(),
            'event_id' => 'purchase_' . $transaction->uuid,
            'event_source_url' => config('app.frontend_url') . '/payment/success/' . $transaction->uuid,
            'action_source' => 'website',
            'user_data' => $userData,
            'custom_data' => [
                'content_name' => $event->event_name,
                'content_category' => $event->category->name ?? 'Event',
                'content_ids' => [$event->uuid],
                'content_type' => 'event',
                'value' => floatval($transaction->total_amount),
                'currency' => 'PHP',
                'num_items' => $numItems,
                'contents' => $items,
                'order_id' => $transaction->uuid,
            ],
        ];

        return $this->trackEvent('Purchase', $eventData, $event);
    }

    /**
     * Track payment cancellation (when user cancels payment/checkout)
     */
    public function trackPaymentCancellation(Transaction $transaction, array $userData = []): bool
    {
        $event = $transaction->event;

        if (!$event) {
            Log::warning('Meta Pixel cancellation tracking skipped: Transaction has no event', [
                'transaction_uuid' => $transaction->uuid,
            ]);
            return false;
        }

        // Load transaction orders to collect details
        $transaction->loadMissing('transactionOrders.eventTicket');

        $items = [];
        $numItems = 0;

        if ($transaction->transactionOrders && $transaction->transactionOrders->count() > 0) {
            foreach ($transaction->transactionOrders as $order) {
                $eventTicket = $order->eventTicket;
                $items[] = [
                    'id' => $order->event_ticket_uuid ?? '',
                    'name' => $eventTicket->name ?? 'Ticket',
                    'category' => $eventTicket->code ?? 'Ticket',
                    'quantity' => $order->quantity ?? 1,
                    'item_price' => floatval($order->price ?? 0),
                ];
                $numItems += $order->quantity ?? 1;
            }
        }

        $eventData = [
            'event_time' => time(),
            'event_id' => 'cancel_checkout_' . $transaction->uuid,
            'event_source_url' => config('app.frontend_url') . '/payment/cancel/' . $transaction->uuid,
            'action_source' => 'website',
            'user_data' => $userData,
            'custom_data' => [
                'content_name' => $event->event_name,
                'content_category' => $event->category->name ?? 'Event',
                'content_ids' => [$event->uuid],
                'content_type' => 'event',
                'value' => floatval($transaction->total_amount),
                'currency' => 'PHP',
                'num_items' => $numItems,
                'contents' => $items,
                'order_id' => $transaction->uuid,
            ],
        ];

        return $this->trackEvent('CancelCheckout', $eventData, $event);
    }
}
