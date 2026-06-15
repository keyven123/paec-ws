<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\DeclineAffiliatePayoutRequest;
use App\Models\AdminUser;
use App\Models\MerchantPayoutRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminMerchantPayoutRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(50, max(5, (int) $request->get('per_page', 15)));
        $status = $request->get('status');

        $q = MerchantPayoutRequest::query()
            ->with(['organization' => function ($rel) {
                $rel->select('uuid', 'name');
            }])
            ->orderByDesc('created_at');

        if (in_array($status, [
            MerchantPayoutRequest::STATUS_PENDING,
            MerchantPayoutRequest::STATUS_APPROVED,
            MerchantPayoutRequest::STATUS_DECLINED,
        ], true)) {
            $q->where('status', $status);
        }

        $paginator = $q->paginate($perPage);
        $tz = config('app.timezone', 'UTC');

        $data = collect($paginator->items())->map(function (MerchantPayoutRequest $row) use ($tz) {
            return [
                'uuid' => $row->uuid,
                'organization_uuid' => $row->organization_uuid,
                'organization_name' => $row->organization?->name,
                'amount_requested' => (float) $row->amount_requested,
                'currency' => $row->currency,
                'status' => $row->status,
                'merchant_note' => $row->merchant_note,
                'admin_notes' => $row->admin_notes,
                'created_at' => $row->created_at?->timezone($tz)->toIso8601String(),
                'processed_at' => $row->processed_at?->timezone($tz)->toIso8601String(),
            ];
        })->values()->all();

        return response()->json([
            'success' => true,
            'message' => 'Merchant payout requests',
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function approve(string $uuid): JsonResponse
    {
        $row = MerchantPayoutRequest::query()->where('uuid', $uuid)->first();

        if (! $row) {
            return response()->json(['message' => 'Payout request not found.'], 404);
        }

        if ($row->status !== MerchantPayoutRequest::STATUS_PENDING) {
            return response()->json(['message' => 'This payout request is not pending.'], 422);
        }

        /** @var AdminUser $admin */
        $admin = auth('admin')->user();

        $row->update([
            'status' => MerchantPayoutRequest::STATUS_APPROVED,
            'processed_at' => now(),
            'processed_by_uuid' => $admin->uuid,
            'admin_notes' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payout approved',
            'data' => ['uuid' => $row->uuid],
        ]);
    }

    public function decline(DeclineAffiliatePayoutRequest $request, string $uuid): JsonResponse
    {
        $row = MerchantPayoutRequest::query()->where('uuid', $uuid)->first();

        if (! $row) {
            return response()->json(['message' => 'Payout request not found.'], 404);
        }

        if ($row->status !== MerchantPayoutRequest::STATUS_PENDING) {
            return response()->json(['message' => 'This payout request is not pending.'], 422);
        }

        /** @var AdminUser $admin */
        $admin = auth('admin')->user();

        $data = $request->validated();

        $row->update([
            'status' => MerchantPayoutRequest::STATUS_DECLINED,
            'processed_at' => now(),
            'processed_by_uuid' => $admin->uuid,
            'admin_notes' => $data['admin_notes'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Payout declined',
            'data' => ['uuid' => $row->uuid],
        ]);
    }
}
