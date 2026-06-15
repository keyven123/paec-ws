<?php

namespace App\Http\Controllers;

use App\Http\Repositories\AffiliatePayoutRequestRepository;
use App\Http\Requests\Admin\DeclineAffiliatePayoutRequest;
use App\Http\Resources\AffiliatePayoutRequestResource;
use App\Models\AffiliatePayoutRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAffiliatePayoutRequestController extends Controller
{
    public function __construct(
        protected AffiliatePayoutRequestRepository $affiliatePayoutRequestRepository,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = $request->get('per_page', 15);
        $filters = $request->only('status');
        $list = $this->affiliatePayoutRequestRepository->getAll($filters);

        return AffiliatePayoutRequestResource::collection($list->paginate($perPage))->response();
    }

    public function approve(Request $request, string $uuid): JsonResponse
    {
        $payout = $this->affiliatePayoutRequestRepository->fetchOrThrow('uuid', $uuid);

        if ($payout->status !== AffiliatePayoutRequest::STATUS_PENDING) {
            return response()->json(['message' => 'This payout request is not pending.'], 422);
        }

        $this->affiliatePayoutRequestRepository->update($payout, [
            'status' => AffiliatePayoutRequest::STATUS_APPROVED,
            'processed_at' => now(),
            'processed_by_uuid' => auth('admin')->user()->uuid,
            'admin_notes' => null,
        ]);

        return (new AffiliatePayoutRequestResource($payout->fresh()->load('user')))->response();
    }

    public function decline(DeclineAffiliatePayoutRequest $request, string $uuid): JsonResponse
    {
        $payout = $this->affiliatePayoutRequestRepository->fetchOrThrow('uuid', $uuid);

        if ($payout->status !== AffiliatePayoutRequest::STATUS_PENDING) {
            return response()->json(['message' => 'This payout request is not pending.'], 422);
        }

        $data = $request->validated();
        $this->affiliatePayoutRequestRepository->update($payout, [
            'status' => AffiliatePayoutRequest::STATUS_DECLINED,
            'processed_at' => now(),
            'processed_by_uuid' => auth('admin')->user()->uuid,
            'admin_notes' => $data['admin_notes'] ?? null,
        ]);

        return (new AffiliatePayoutRequestResource($payout->fresh()->load('user')))->response();
    }
}
