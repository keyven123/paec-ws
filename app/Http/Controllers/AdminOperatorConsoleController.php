<?php

namespace App\Http\Controllers;

use App\Models\AdminUser;
use App\Models\Event;
use App\Models\MerchantPayoutRequest;
use App\Models\Organization;
use App\Models\OrganizationBank;
use App\Services\AffiliateCommissionAvailabilityService;
use App\Services\Organizer\OrganizerAccountingBalanceService;
use App\Services\Platform\AdminOperatorConsoleService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

class AdminOperatorConsoleController extends Controller
{
    public function show(Request $request, AdminOperatorConsoleService $service): JsonResponse
    {
        $admin = auth('admin')->user();
        if (! $admin) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $tz = AffiliateCommissionAvailabilityService::timezone();
        $asOf = null;
        $rawAsOf = $request->query('as_of');
        if ($rawAsOf !== null && $rawAsOf !== '') {
            try {
                $asOf = Carbon::parse((string) $rawAsOf, $tz);
            } catch (\Throwable) {
                return response()->json([
                    'message' => 'Invalid as_of date.',
                ], 422);
            }
        }

        $period = strtolower((string) $request->query('period', AdminOperatorConsoleService::PERIOD_DAILY));
        if (! in_array($period, AdminOperatorConsoleService::allowedPeriods(), true)) {
            return response()->json([
                'message' => 'Invalid period. Use daily, weekly, monthly, yearly, or custom.',
            ], 422);
        }

        $customStart = null;
        $customEnd = null;
        if ($period === AdminOperatorConsoleService::PERIOD_CUSTOM) {
            $from = $request->query('custom_start', $request->query('date_from'));
            $to = $request->query('custom_end', $request->query('date_to'));
            if ($from === null || $from === '' || $to === null || $to === '') {
                return response()->json([
                    'message' => 'custom_start and custom_end are required when period is custom.',
                ], 422);
            }
            try {
                $customStart = Carbon::parse((string) $from, $tz)->startOfDay();
                $customEnd = Carbon::parse((string) $to, $tz)->endOfDay();
            } catch (\Throwable) {
                return response()->json([
                    'message' => 'Invalid custom_start or custom_end.',
                ], 422);
            }
        }

        try {
            $data = $service->build($asOf, $period, $customStart, $customEnd);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Operator console',
            'data' => $data,
        ]);
    }

    public function remittances(Request $request, AdminOperatorConsoleService $service): JsonResponse
    {
        $admin = auth('admin')->user();
        if (! $admin) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $perPage = min(50, max(5, (int) $request->query('per_page', 10)));
        $page = max(1, (int) $request->query('page', 1));
        $tz = AffiliateCommissionAvailabilityService::timezone();
        $organizationUuid = $request->query('organization_uuid');
        if ($organizationUuid !== null && $organizationUuid !== '') {
            $exists = Organization::query()->where('uuid', $organizationUuid)->exists();
            if (! $exists) {
                return response()->json([
                    'message' => 'Invalid organization_uuid.',
                ], 422);
            }
        } else {
            $organizationUuid = null;
        }

        return response()->json([
            'success' => true,
            'message' => 'Operator console remittances',
            'data' => $service->paginatedRemittances($page, $perPage, $tz, $organizationUuid),
        ]);
    }

    public function pendingPayouts(Request $request, AdminOperatorConsoleService $service): JsonResponse
    {
        $admin = auth('admin')->user();
        if (! $admin) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $perPage = min(50, max(5, (int) $request->query('per_page', 10)));
        $page = max(1, (int) $request->query('page', 1));
        $tz = AffiliateCommissionAvailabilityService::timezone();

        return response()->json([
            'success' => true,
            'message' => 'Operator console pending payouts',
            'data' => $service->paginatedPendingPayouts($page, $perPage, $tz),
        ]);
    }

    public function payoutRequests(Request $request, AdminOperatorConsoleService $service): JsonResponse
    {
        $admin = auth('admin')->user();
        if (! $admin) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $status = strtolower((string) $request->query('status', ''));
        if (! in_array($status, [
            MerchantPayoutRequest::STATUS_APPROVED,
            MerchantPayoutRequest::STATUS_DECLINED,
        ], true)) {
            return response()->json([
                'message' => 'Query parameter "status" is required and must be "approved" or "declined".',
            ], 422);
        }

        $perPage = min(50, max(5, (int) $request->query('per_page', 10)));
        $page = max(1, (int) $request->query('page', 1));
        $tz = AffiliateCommissionAvailabilityService::timezone();

        return response()->json([
            'success' => true,
            'message' => 'Operator console payout requests',
            'data' => $service->paginatedPayoutRequests($status, $page, $perPage, $tz),
        ]);
    }

    public function remittanceEvents(Request $request): JsonResponse
    {
        $admin = auth('admin')->user();
        if (! $admin || ! $admin->role?->is_admin) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $payload = $request->validate([
            'organization_uuid' => ['required', 'uuid', 'exists:organizations,uuid'],
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        $query = Event::query()
            ->where('organization_uuid', $payload['organization_uuid'])
            ->orderBy('event_name');

        if (! empty($payload['q'])) {
            $keyword = $payload['q'];
            $query->where(function ($builder) use ($keyword) {
                $builder->where('event_name', 'LIKE', "%{$keyword}%")
                    ->orWhere('contact_email', 'LIKE', "%{$keyword}%");
            });
        }

        $rows = $query->limit(50)->get(['uuid', 'event_name']);

        return response()->json([
            'success' => true,
            'message' => 'Operator console remittance events',
            'data' => $rows,
        ]);
    }

    public function storeRemittance(
        Request $request,
        OrganizerAccountingBalanceService $balanceService,
    ): JsonResponse {
        $admin = auth('admin')->user();
        if (! $admin || ! $admin->role?->is_admin) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $payload = $request->validate([
            'organization_uuid' => ['required', 'uuid', 'exists:organizations,uuid'],
            'event_uuid' => [
                'required',
                'uuid',
                Rule::exists('events', 'uuid')->where(
                    fn ($query) => $query->where('organization_uuid', $request->input('organization_uuid')),
                ),
            ],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $org = Organization::query()->where('uuid', $payload['organization_uuid'])->first();
        if (! $org) {
            return response()->json(['message' => 'Organizer not found.'], 404);
        }

        $amount = (float) $payload['amount'];
        $available = $balanceService->availableForPayout($org->uuid, $payload['event_uuid']);

        if ($amount > $available + 0.009) {
            return response()->json([
                'message' => 'Amount exceeds available balance for this event (PHP '
                    . number_format($available, 2)
                    . ').',
                'available' => $available,
            ], 422);
        }

        $bankUuid = OrganizationBank::query()
            ->where('organization_uuid', $org->uuid)
            ->orderByDesc('is_default')
            ->orderBy('created_at')
            ->value('uuid');

        if ($bankUuid === null) {
            return response()->json([
                'message' => 'This merchant has no bank account on file.',
            ], 422);
        }

        $row = MerchantPayoutRequest::query()->create([
            'organization_uuid' => $org->uuid,
            'organization_bank_uuid' => $bankUuid,
            'event_uuid' => $payload['event_uuid'],
            'amount_requested' => $amount,
            'currency' => 'PHP',
            'status' => MerchantPayoutRequest::STATUS_APPROVED,
            'merchant_note' => null,
            'admin_notes' => $payload['note'] ?? 'Manual remittance entry',
            'processed_at' => now(),
            'processed_by_uuid' => $admin->uuid,
            'requested_by_admin_uuid' => $admin->uuid,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Remittance recorded',
            'data' => [
                'uuid' => $row->uuid,
            ],
        ], 201);
    }

    public function voidRemittance(string $uuid): JsonResponse
    {
        $admin = auth('admin')->user();
        if (! $admin) {
            return response()->json([
                'message' => 'Forbidden.',
            ], 403);
        }

        $row = MerchantPayoutRequest::query()->where('uuid', $uuid)->first();

        if (! $row) {
            return response()->json(['message' => 'Remittance not found.'], 404);
        }

        if ($row->status !== MerchantPayoutRequest::STATUS_APPROVED) {
            return response()->json([
                'message' => 'Only approved remittance entries can be voided.',
            ], 422);
        }

        /** @var AdminUser $admin */
        $row->update([
            'status' => MerchantPayoutRequest::STATUS_VOID,
            'void_at' => now(),
            'void_by_uuid' => $admin->uuid,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Remittance voided',
            'data' => [
                'uuid' => $row->uuid,
                'status' => $row->status,
                'void_at' => $row->void_at?->toIso8601String(),
                'void_by_uuid' => $row->void_by_uuid,
            ],
        ]);
    }
}

