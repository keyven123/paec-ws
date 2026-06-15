<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Repositories\AffiliateConversionRepository;
use App\Http\Repositories\AffiliatePayoutRequestRepository;
use App\Http\Repositories\EventRepository;
use App\Http\Repositories\UserRepository;
use App\Http\Requests\Customer\ListAffiliateCatalogRequest;
use App\Http\Requests\Customer\StoreAffiliatePayoutRequest;
use App\Http\Requests\Customer\UpdateAffiliateBankDetailsRequest;
use App\Http\Resources\AffiliateConversionResource;
use App\Http\Resources\AffiliatePayoutRequestResource;
use App\Http\Resources\EventPublicResource;
use App\Models\AffiliatePayoutRequest;
use App\Models\EventSection;
use App\Constants\GeneralConstants;
use App\Models\User;
use App\Services\AffiliatePartnerStatsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AffiliatePartnerController extends Controller
{
    public function __construct(
        protected UserRepository $userRepository,
        protected EventRepository $eventRepository,
        protected AffiliatePayoutRequestRepository $affiliatePayoutRequestRepository,
        protected AffiliateConversionRepository $affiliateConversionRepository,
    ) {
    }

    protected function ensureApprovedAffiliate(Request $request): User
    {
        $user = $request->user();
        abort_unless(
            ($user->userAffiliate?->affiliate_status ?? GeneralConstants::AFFILIATE_STATUSES['NONE']) === GeneralConstants::AFFILIATE_STATUSES['APPROVED'],
            403,
            'Affiliate partner access required.'
        );

        return $user;
    }

    /**
     * Approved or suspended partners may view payout and commission history (read-only when suspended).
     */
    protected function ensureAffiliatePartnerReadAccess(Request $request): User
    {
        $user = $request->user();
        $status = $user->userAffiliate?->affiliate_status ?? GeneralConstants::AFFILIATE_STATUSES['NONE'];
        abort_unless(
            in_array($status, [GeneralConstants::AFFILIATE_STATUSES['APPROVED'], GeneralConstants::AFFILIATE_STATUSES['SUSPENDED']], true),
            403,
            'Affiliate partner access required.'
        );

        return $user;
    }

    protected function affiliatePartnerData(User $user): array
    {
        $affiliate = $user->userAffiliate;
        $baseUrl = rtrim(config('app.frontend_url', config('app.url')), '/');
        $code = $affiliate?->affiliate_code;
        $status = $affiliate?->affiliate_status ?? 'none';
        $referralLink = ($status === 'approved' && $code)
            ? "{$baseUrl}/browse?ref={$code}"
            : null;

        return [
            'status' => $status,
            'code' => $code,
            'referral_link' => $referralLink,
            'affiliate_applied_at' => $affiliate?->affiliate_applied_at,
            'affiliate_approved_at' => $affiliate?->affiliate_approved_at,
            'affiliate_suspend_reason' => $status === GeneralConstants::AFFILIATE_STATUSES['SUSPENDED']
                ? $affiliate?->affiliate_suspend_reason
                : null,
            'affiliate_suspended_at' => $status === GeneralConstants::AFFILIATE_STATUSES['SUSPENDED']
                ? $affiliate?->affiliate_suspended_at
                : null,
            'stats' => AffiliatePartnerStatsService::dashboardStatsForUser($user),
            'bank_details' => [
                'bank' => $affiliate?->affiliate_bank_name,
                'branch' => $affiliate?->affiliate_bank_branch,
                'account_name' => $affiliate?->affiliate_bank_account_name,
                'account_number' => $affiliate?->affiliate_bank_account_number,
                'tin' => $affiliate?->affiliate_bank_tin,
            ],
        ];
    }

    public function availableEvents(ListAffiliateCatalogRequest $request): JsonResponse
    {
        $this->ensureApprovedAffiliate($request);
        $validated = $request->validated();
        unset($validated['fun_type']);
        $perPage = (int) ($validated['per_page'] ?? 15);
        $list = $this->eventRepository->getAffiliatePartnerTicketEvents($validated);

        return EventPublicResource::collection($list->paginate($perPage))->response();
    }

    public function availableFun(ListAffiliateCatalogRequest $request): JsonResponse
    {
        $this->ensureApprovedAffiliate($request);
        $validated = $request->validated();
        $validated['type'] = EventSection::AMUSEMENT_SECTION;
        $perPage = (int) ($validated['per_page'] ?? 15);
        $list = $this->eventRepository->getPublicEvents($validated)
            ->where('affiliate_enabled', true)
            ->whereAffiliateProgramNotPastEndDate();

        return EventPublicResource::collection($list->paginate($perPage))->response();
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->affiliatePartnerData($request->user()),
        ]);
    }

    public function updateBankDetails(UpdateAffiliateBankDetailsRequest $request): JsonResponse
    {
        $user = $this->ensureApprovedAffiliate($request);
        $validated = $request->validated();
        $user->userAffiliate()->updateOrCreate(
            ['user_uuid' => $user->uuid],
            [
                'affiliate_bank_name' => $validated['bank'],
                'affiliate_bank_branch' => $validated['branch'],
                'affiliate_bank_account_name' => $validated['account_name'],
                'affiliate_bank_account_number' => $validated['account_number'],
                'affiliate_bank_tin' => $validated['tin'],
            ]
        );

        return response()->json([
            'data' => $this->affiliatePartnerData($user->fresh()),
        ]);
    }

    public function payoutHistory(Request $request): JsonResponse
    {
        $user = $this->ensureAffiliatePartnerReadAccess($request);
        $perPage = min(50, max(1, (int) $request->get('per_page', 15)));
        $list = $this->affiliatePayoutRequestRepository->getByUser($user->uuid, $perPage);

        return AffiliatePayoutRequestResource::collection($list)->response();
    }

    public function conversionHistory(Request $request): JsonResponse
    {
        $user = $this->ensureAffiliatePartnerReadAccess($request);
        $perPage = $request->get('per_page', 15);
        $list = $this->affiliateConversionRepository->getByUser($user->uuid, $perPage);

        return AffiliateConversionResource::collection($list)->response();
    }

    public function storePayoutRequest(StoreAffiliatePayoutRequest $request): JsonResponse
    {
        $user = $this->ensureApprovedAffiliate($request);

        if ($this->affiliatePayoutRequestRepository->hasPending($user->uuid)) {
            return response()->json([
                'message' => 'You already have a pending payout request. Wait for it to be processed.',
            ], 422);
        }

        $validated = $request->validated();
        $row = $this->affiliatePayoutRequestRepository->create([
            'user_uuid' => $user->uuid,
            'amount_requested' => $validated['amount'],
            'currency' => 'PHP',
            'status' => AffiliatePayoutRequest::STATUS_PENDING,
        ]);

        return (new AffiliatePayoutRequestResource($row))->response()->setStatusCode(201);
    }

    public function apply(Request $request): JsonResponse
    {
        $user = $this->userRepository->fetchOrThrow('uuid', $request->user()->uuid);
        $status = $user->userAffiliate?->affiliate_status ?? 'none';

        if ($status === GeneralConstants::AFFILIATE_STATUSES['SUSPENDED']) {
            return response()->json([
                'message' => 'Your affiliate account is suspended. Contact support if you have questions.',
            ], 422);
        }

        if ($status !== GeneralConstants::AFFILIATE_STATUSES['NONE']) {
            return response()->json([
                'message' => 'You are already part of the affiliate program.',
            ], 422);
        }

        $fresh = $this->userRepository->enrollAffiliatePartner($user);

        return response()->json([
            'message' => 'Welcome to the TicketOC affiliate partner program.',
            'data' => $this->affiliatePartnerData($fresh),
        ], 201);
    }
}
