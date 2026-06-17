<?php

namespace App\Http\Controllers\Organizer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organizer\OrganizerAccountingRequest;
use App\Http\Requests\Organizer\OrganizerPnLRequest;
use App\Http\Requests\Organizer\StoreOrganizerMerchantPayoutRequest;
use App\Models\Event;
use App\Models\MerchantPayoutRequest;
use App\Services\Organizer\OrganizerAccountingBalanceService;
use App\Services\Organizer\OrganizerAccountingReportService;
use App\Services\Organizer\OrganizerContextService;
use App\Services\Organizer\OrganizerPnLService;
use App\Services\AffiliateCommissionAvailabilityService;
use App\Services\Platform\AdminPlatformPnLService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizerAccountingController extends Controller
{
    public function __construct(
        protected OrganizerAccountingBalanceService $balanceService,
        protected OrganizerAccountingReportService $reportService,
        protected OrganizerPnLService $pnlService,
        protected OrganizerContextService $organizerContext,
    ) {
    }

    public function pnl(OrganizerPnLRequest $request): JsonResponse
    {
        $organizationUuid = $this->organizerContext->organizationUuidOrAbort();
        $validated = $request->validated();
        $tz = AffiliateCommissionAvailabilityService::timezone();

        $asOf = null;
        if (! empty($validated['as_of'])) {
            try {
                $asOf = Carbon::parse((string) $validated['as_of'], $tz);
            } catch (\Throwable) {
                return response()->json(['message' => 'Invalid as_of date.'], 422);
            }
        }

        $period = strtolower((string) ($validated['period'] ?? AdminPlatformPnLService::PERIOD_MONTHLY));

        $customStart = null;
        $customEnd = null;
        if ($period === AdminPlatformPnLService::PERIOD_CUSTOM) {
            if (empty($validated['custom_start']) || empty($validated['custom_end'])) {
                return response()->json([
                    'message' => 'custom_start and custom_end are required when period is custom.',
                ], 422);
            }

            try {
                $customStart = Carbon::parse((string) $validated['custom_start'], $tz)->startOfDay();
                $customEnd = Carbon::parse((string) $validated['custom_end'], $tz)->endOfDay();
            } catch (\Throwable) {
                return response()->json(['message' => 'Invalid custom_start or custom_end.'], 422);
            }
        }

        $eventUuid = isset($validated['event_uuid']) ? (string) $validated['event_uuid'] : null;

        try {
            $data = $this->pnlService->buildReport(
                $organizationUuid,
                $asOf,
                $period,
                $customStart,
                $customEnd,
                $eventUuid,
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Organizer P&L',
            'data' => $data,
        ]);
    }

    public function events(): JsonResponse
    {
        $orgUuid = auth('admin')->user()?->organization_uuid;

        if (! $orgUuid) {
            return response()->json([
                'success' => true,
                'message' => 'Organizer accounting events',
                'data' => [],
            ]);
        }

        $rows = Event::query()
            ->where('organization_uuid', $orgUuid)
            ->orderBy('event_name')
            ->get(['uuid', 'event_name']);

        return response()->json([
            'success' => true,
            'message' => 'Organizer accounting events',
            'data' => $rows,
        ]);
    }

    public function summary(OrganizerAccountingRequest $request): JsonResponse
    {
        $orgUuid = auth('admin')->user()?->organization_uuid;

        if (! $orgUuid) {
            return response()->json([
                'success' => true,
                'message' => 'Organizer accounting summary',
                'data' => [
                    'available' => 0.0,
                    'pending' => 0.0,
                    'total_cashout' => 0.0,
                    'commission_percentage' => null,
                    'effective_commission_percentage' => null,
                    'currency' => 'PHP',
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Organizer accounting summary',
            'data' => $this->reportService->summary($orgUuid, $request->eventUuid()),
        ]);
    }

    public function transactions(OrganizerAccountingRequest $request): JsonResponse
    {
        $bucket = (string) $request->query('bucket', '');
        if (! in_array($bucket, ['available', 'pending'], true)) {
            return response()->json([
                'message' => 'Query parameter "bucket" is required and must be "available" or "pending".',
            ], 422);
        }

        $orgUuid = auth('admin')->user()?->organization_uuid;

        if (! $orgUuid) {
            return response()->json([
                'success' => true,
                'message' => 'Organizer accounting transactions',
                'data' => [
                    'commission_percentage' => null,
                    'effective_commission_percentage' => null,
                    'bucket' => $bucket,
                    'transactions' => [],
                    'meta' => [
                        'current_page' => 1,
                        'last_page' => 1,
                        'per_page' => 15,
                        'total' => 0,
                    ],
                ],
            ]);
        }

        $perPage = (int) $request->query('per_page', 15);
        $page = (int) $request->query('page', 1);

        return response()->json([
            'success' => true,
            'message' => 'Organizer accounting transactions',
            'data' => $this->reportService->transactions($orgUuid, $bucket, $page, $perPage, $request->eventUuid()),
        ]);
    }

    public function remittanceBuckets(OrganizerAccountingRequest $request): JsonResponse
    {
        $bucket = (string) $request->query('bucket', '');
        if (! in_array($bucket, ['available', 'pending'], true)) {
            return response()->json([
                'message' => 'Query parameter "bucket" is required and must be "available" or "pending".',
            ], 422);
        }

        $orgUuid = auth('admin')->user()?->organization_uuid;

        if (! $orgUuid) {
            return response()->json([
                'success' => true,
                'message' => 'Organizer remittance buckets',
                'data' => [
                    'bucket' => $bucket,
                    'commission_percentage' => null,
                    'effective_commission_percentage' => null,
                    'totals' => [
                        'merchant_net_sum' => 0.0,
                        'transaction_count' => 0,
                    ],
                    'months' => [],
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Organizer remittance buckets',
            'data' => $this->reportService->remittanceBuckets($orgUuid, $bucket, $request->eventUuid()),
        ]);
    }

    public function payoutRequests(OrganizerAccountingRequest $request): JsonResponse
    {
        $orgUuid = auth('admin')->user()?->organization_uuid;

        if (! $orgUuid) {
            return response()->json([
                'success' => true,
                'message' => 'Organizer payout requests',
                'data' => $this->emptyPayoutRequestsPayload(),
            ]);
        }

        $perPage = min(50, max(5, (int) $request->query('per_page', 10)));

        return response()->json([
            'success' => true,
            'message' => 'Organizer payout requests',
            'data' => $this->reportService->payoutRequests(
                $orgUuid,
                $request->eventUuid(),
                max(1, (int) $request->query('pending_page', 1)),
                max(1, (int) $request->query('success_page', 1)),
                max(1, (int) $request->query('declined_page', 1)),
                $perPage,
            ),
        ]);
    }

    public function storePayoutRequest(StoreOrganizerMerchantPayoutRequest $request): JsonResponse
    {
        $admin = auth('admin')->user();
        $orgUuid = $admin?->organization_uuid;

        if (! $orgUuid) {
            return response()->json(['message' => 'Organization not found for this account.'], 422);
        }

        $eventUuid = $request->validated('event_uuid');

        $hasPending = MerchantPayoutRequest::query()
            ->where('organization_uuid', $orgUuid)
            ->where('event_uuid', $eventUuid)
            ->where('status', MerchantPayoutRequest::STATUS_PENDING)
            ->exists();

        if ($hasPending) {
            return response()->json([
                'message' => 'You already have a pending payout request for this event. Wait for it to be processed.',
            ], 422);
        }

        $amount = (float) $request->validated('amount');
        $available = $this->balanceService->availableForPayout($orgUuid, $eventUuid);

        if ($amount > $available + 0.009) {
            return response()->json([
                'message' => 'Amount exceeds available balance for this event.',
                'available' => $available,
            ], 422);
        }

        $row = MerchantPayoutRequest::query()->create([
            'organization_uuid' => $orgUuid,
            'organization_bank_uuid' => $request->validated('organization_bank_uuid'),
            'event_uuid' => $eventUuid,
            'amount_requested' => $amount,
            'currency' => 'PHP',
            'status' => MerchantPayoutRequest::STATUS_PENDING,
            'merchant_note' => $request->validated('note'),
            'requested_by_admin_uuid' => $admin->uuid,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payout request submitted',
            'data' => [
                'uuid' => $row->uuid,
                'amount_requested' => (float) $row->amount_requested,
            ],
        ], 201);
    }

    /**
     * @return array<string, array{rows: list<mixed>, meta: array<string, int>}>
     */
    private function emptyPayoutRequestsPayload(): array
    {
        $emptyMeta = [
            'current_page' => 1,
            'last_page' => 1,
            'per_page' => 10,
            'total' => 0,
        ];
        $empty = ['rows' => [], 'meta' => $emptyMeta];

        return [
            'pending' => $empty,
            'success' => $empty,
            'declined' => $empty,
        ];
    }
}
