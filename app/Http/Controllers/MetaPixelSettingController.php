<?php

namespace App\Http\Controllers;

use App\Models\CmsSetting;
use App\Models\MetaPixelEventLog;
use App\Services\MetaPixelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function stats(): JsonResponse
    {
        $base = MetaPixelEventLog::query();

        $total = (clone $base)->count();
        $sent = (clone $base)->where('status', 'sent')->count();
        $failed = (clone $base)->where('status', 'failed')->count();
        $today = (clone $base)->whereDate('created_at', now()->toDateString())->count();

        $byEvent = MetaPixelEventLog::query()
            ->select('event_name', DB::raw('count(*) as total'))
            ->groupBy('event_name')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'event_name' => $row->event_name,
                'total' => (int) $row->total,
            ])
            ->values();

        $bySource = MetaPixelEventLog::query()
            ->select('source', DB::raw('count(*) as total'))
            ->groupBy('source')
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => [
                'source' => $row->source,
                'total' => (int) $row->total,
            ])
            ->values();

        return response()->json([
            'data' => [
                'total' => $total,
                'sent' => $sent,
                'failed' => $failed,
                'today' => $today,
                'by_event' => $byEvent,
                'by_source' => $bySource,
            ],
        ]);
    }

    public function logs(Request $request): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->integer('per_page', 20)));

        $query = MetaPixelEventLog::query()->orderByDesc('created_at');

        if ($request->filled('event_name')) {
            $query->where('event_name', (string) $request->string('event_name'));
        }
        if ($request->filled('source')) {
            $query->where('source', (string) $request->string('source'));
        }
        if ($request->filled('status')) {
            $query->where('status', (string) $request->string('status'));
        }
        if ($request->filled('search')) {
            $search = trim((string) $request->string('search'));
            $query->where(function ($q) use ($search) {
                $q->where('content_name', 'like', "%{$search}%")
                    ->orWhere('page_url', 'like', "%{$search}%")
                    ->orWhere('order_id', 'like', "%{$search}%")
                    ->orWhere('event_id', 'like', "%{$search}%")
                    ->orWhere('transaction_uuid', 'like', "%{$search}%");
            });
        }

        $paginator = $query->paginate($perPage);

        return response()->json([
            'data' => $paginator->getCollection()->map(fn (MetaPixelEventLog $log) => [
                'uuid' => $log->uuid,
                'event_name' => $log->event_name,
                'source' => $log->source,
                'status' => $log->status,
                'pixel_id' => $log->pixel_id,
                'event_id' => $log->event_id,
                'content_name' => $log->content_name,
                'page_url' => $log->page_url,
                'value' => $log->value !== null ? (float) $log->value : null,
                'currency' => $log->currency,
                'transaction_uuid' => $log->transaction_uuid,
                'event_uuid' => $log->event_uuid,
                'order_id' => $log->order_id,
                'http_status' => $log->http_status,
                'error_message' => $log->error_message,
                'ip_address' => $log->ip_address,
                'created_at' => optional($log->created_at)?->toIso8601String(),
            ])->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Browser beacon — records storefront fbq events in the admin audit log.
     */
    public function publicLog(Request $request, MetaPixelService $metaPixelService): JsonResponse
    {
        $validated = $request->validate([
            'event_name' => ['required', 'string', 'max:100'],
            'event_id' => ['nullable', 'string', 'max:191'],
            'content_name' => ['nullable', 'string', 'max:255'],
            'page_url' => ['nullable', 'string', 'max:1000'],
            'value' => ['nullable', 'numeric'],
            'currency' => ['nullable', 'string', 'max:8'],
            'content_ids' => ['nullable', 'array'],
            'content_ids.*' => ['string', 'max:100'],
            'order_id' => ['nullable', 'string', 'max:191'],
            'num_items' => ['nullable', 'integer', 'min:0'],
        ]);

        $config = $metaPixelService->resolveProjectConfig();
        $contentIds = $validated['content_ids'] ?? [];

        $metaPixelService->recordEventLog([
            'event_name' => $validated['event_name'],
            'source' => 'browser',
            'status' => 'sent',
            'pixel_id' => $config['pixel_id'] !== '' ? $config['pixel_id'] : null,
            'event_id' => $validated['event_id'] ?? null,
            'content_name' => $validated['content_name'] ?? null,
            'page_url' => $validated['page_url'] ?? $request->headers->get('referer'),
            'value' => $validated['value'] ?? null,
            'currency' => $validated['currency'] ?? null,
            'event_uuid' => is_array($contentIds) ? ($contentIds[0] ?? null) : null,
            'order_id' => $validated['order_id'] ?? null,
            'payload' => [
                'content_ids' => $contentIds,
                'num_items' => $validated['num_items'] ?? null,
            ],
        ]);

        return response()->json(['success' => true]);
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

        $eventRow = $payload['data'][0];

        $response = Http::timeout(30)
            ->acceptJson()
            ->asJson()
            ->post("https://graph.facebook.com/v18.0/{$pixelId}/events", $payload);

        if ($response->successful()) {
            $metaPixelService->recordEventLog([
                'event_name' => 'Purchase',
                'source' => 'admin_test',
                'status' => 'sent',
                'pixel_id' => $pixelId,
                'event_id' => $eventRow['event_id'] ?? null,
                'content_name' => data_get($eventRow, 'custom_data.content_name'),
                'page_url' => $eventRow['event_source_url'] ?? null,
                'value' => data_get($eventRow, 'custom_data.value'),
                'currency' => data_get($eventRow, 'custom_data.currency'),
                'order_id' => data_get($eventRow, 'custom_data.order_id'),
                'http_status' => $response->status(),
                'payload' => ['event' => $eventRow, 'meta' => $response->json()],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Test event sent successfully. Check Meta Events Manager (Test Events if a test code is set).',
                'meta' => $response->json(),
            ]);
        }

        $body = $response->json();
        $errorMessage = data_get($body, 'error.message') ?: $response->body();

        $metaPixelService->recordEventLog([
            'event_name' => 'Purchase',
            'source' => 'admin_test',
            'status' => 'failed',
            'pixel_id' => $pixelId,
            'event_id' => $eventRow['event_id'] ?? null,
            'content_name' => data_get($eventRow, 'custom_data.content_name'),
            'page_url' => $eventRow['event_source_url'] ?? null,
            'value' => data_get($eventRow, 'custom_data.value'),
            'currency' => data_get($eventRow, 'custom_data.currency'),
            'order_id' => data_get($eventRow, 'custom_data.order_id'),
            'http_status' => $response->status(),
            'error_message' => is_string($errorMessage) ? $errorMessage : json_encode($errorMessage),
            'payload' => ['event' => $eventRow, 'meta' => $body],
        ]);

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
