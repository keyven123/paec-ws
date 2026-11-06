<?php

namespace App\Http\Controllers;

use App\Models\CmsSetting;
use App\Services\MetaPixelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

class MetaPixelSettingController extends Controller
{
    public const SETTING_KEY = 'meta_pixel';

    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->payload(),
        ]);
    }

    /**
     * Public browser config only — never exposes the access token.
     * Used by the storefront to load fbevents.js / fbq.
     */
    public function publicShow(): JsonResponse
    {
        $config = app(MetaPixelService::class)->resolveProjectConfig();

        return response()->json([
            'data' => [
                'enabled' => (bool) ($config['enabled'] ?? false),
                'pixel_id' => (string) ($config['pixel_id'] ?? ''),
                'test_event_code' => $config['test_event_code'] ?? null,
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'enabled' => ['required', 'boolean'],
            'pixel_id' => ['nullable', 'string', 'max:255', 'required_if:enabled,true'],
            'access_token' => ['nullable', 'string', 'max:2000'],
            'test_event_code' => ['nullable', 'string', 'max:255'],
        ]);

        $validator->after(function ($validator) use ($request) {
            if (! $request->boolean('enabled')) {
                return;
            }

            $existing = $this->stored();
            $token = trim((string) $request->input('access_token', ''));
            $hasExistingToken = filled($existing['access_token'] ?? null);

            if ($token === '' && ! $hasExistingToken) {
                $validator->errors()->add('access_token', 'Access token is required when Meta Pixel is enabled.');
            }
        });

        $validated = $validator->validate();

        $existing = $this->stored();
        $incomingToken = trim((string) ($validated['access_token'] ?? ''));
        $accessToken = $incomingToken !== ''
            ? $incomingToken
            : (string) ($existing['access_token'] ?? '');

        CmsSetting::setValue(self::SETTING_KEY, [
            'enabled' => (bool) $validated['enabled'],
            'pixel_id' => trim((string) ($validated['pixel_id'] ?? '')),
            'access_token' => $accessToken,
            'test_event_code' => trim((string) ($validated['test_event_code'] ?? '')) ?: null,
        ]);

        return response()->json([
            'data' => $this->payload(),
            'message' => 'Meta Pixel settings updated successfully.',
        ]);
    }

    /**
     * Send a test Purchase event to Meta Conversions API using the saved project settings.
     */
    public function test(MetaPixelService $metaPixelService): JsonResponse
    {
        $config = $metaPixelService->resolveProjectConfig();

        if (! ($config['enabled'] ?? false) || empty($config['pixel_id']) || empty($config['access_token'])) {
            return response()->json([
                'success' => false,
                'message' => 'Enable Meta Pixel and save a Pixel ID and access token before testing.',
            ], 422);
        }

        $pixelId = $config['pixel_id'];
        $accessToken = $config['access_token'];
        $testEventCode = $config['test_event_code'] ?? null;

        $payload = [
            'data' => [[
                'event_name' => 'Purchase',
                'event_time' => time(),
                'event_id' => 'admin_test_'.uniqid('', true),
                'action_source' => 'website',
                'event_source_url' => rtrim((string) config('app.frontend_url', config('app.url')), '/').'/admin/meta-pixel',
                'user_data' => [
                    'em' => [hash('sha256', 'meta.pixel.test@paec.local')],
                    'client_user_agent' => request()->userAgent() ?: 'PAEC-Admin-MetaPixel-Test',
                ],
                'custom_data' => [
                    'currency' => 'PHP',
                    'value' => 1.0,
                    'content_type' => 'product',
                    'content_name' => 'PAEC Meta Pixel admin test',
                    'order_id' => 'admin-test-'.now()->format('YmdHis'),
                ],
            ]],
            'access_token' => $accessToken,
        ];

        if (filled($testEventCode)) {
            $payload['test_event_code'] = $testEventCode;
        }

        $response = Http::timeout(30)
            ->acceptJson()
            ->asJson()
            ->post("https://graph.facebook.com/v18.0/{$pixelId}/events", $payload);

        if ($response->successful()) {
            return response()->json([
                'success' => true,
                'message' => 'Test event sent successfully. Check Meta Events Manager (Test Events if a test code is set).',
                'meta' => $response->json(),
            ]);
        }

        $body = $response->json();
        $errorMessage = data_get($body, 'error.message') ?: $response->body();

        return response()->json([
            'success' => false,
            'message' => 'Meta rejected the test event: '.$errorMessage,
            'meta' => $body,
        ], 422);
    }

    /**
     * @return array{enabled: bool, pixel_id: string, access_token: string, test_event_code: ?string}
     */
    private function stored(): array
    {
        $value = CmsSetting::getValue(self::SETTING_KEY, []);

        if (! is_array($value)) {
            $value = [];
        }

        return [
            'enabled' => (bool) ($value['enabled'] ?? false),
            'pixel_id' => (string) ($value['pixel_id'] ?? ''),
            'access_token' => (string) ($value['access_token'] ?? ''),
            'test_event_code' => isset($value['test_event_code']) && $value['test_event_code'] !== ''
                ? (string) $value['test_event_code']
                : null,
        ];
    }

    /**
     * @return array{enabled: bool, pixel_id: string, access_token_set: bool, access_token_masked: string|null, test_event_code: ?string}
     */
    private function payload(): array
    {
        $stored = $this->stored();
        $token = $stored['access_token'];
        $masked = null;

        if ($token !== '') {
            $prefix = substr($token, 0, 8);
            $suffix = strlen($token) > 12 ? substr($token, -4) : '';
            $masked = $prefix.'…'.$suffix;
        }

        return [
            'enabled' => $stored['enabled'],
            'pixel_id' => $stored['pixel_id'],
            'access_token_set' => $token !== '',
            'access_token_masked' => $masked,
            'test_event_code' => $stored['test_event_code'],
        ];
    }
}
