<?php

namespace App\Http\Controllers;

use App\Constants\GeneralConstants;
use App\Http\Repositories\PromoCodeRepository;
use App\Http\Requests\PromoCode\CreatePromoCodeRequest;
use App\Http\Requests\PromoCode\UpdatePromoCodeRequest;
use App\Http\Requests\PromoCode\ListPromoCodeRequest;
use App\Http\Resources\PromoCodeResource;
use App\Http\Resources\PublicPromoCodeResource;
use App\Exceptions\NoPromoCodeFoundException;
use App\Exceptions\UnauthorizedException;
use App\Http\Requests\PromoCode\PublicShowPromoCodeRequest;
use App\Http\Requests\PromoCode\PublicValidatePromoCodeRequest;
use App\Models\PromoCode;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\Response;
use Illuminate\Http\JsonResponse;

class PromoCodeController extends Controller
{
    public function __construct(protected PromoCodeRepository $promoCodeRepository)
    {
    }

    /**
     * Display a listing of promo codes.
     * @param ListPromoCodeRequest $request
     * @return JsonResponse
     */
    public function index(ListPromoCodeRequest $request): JsonResponse
    {
        $perPage = $request->get('per_page', 15);
        $list = $this->promoCodeRepository->getAll($request->validated());
        return PromoCodeResource::collection($list->paginate($perPage))->response();
    }

    /**
     * Store a newly created promo code.
     * @param CreatePromoCodeRequest $request
     * @return JsonResponse
     */
    public function store(CreatePromoCodeRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $promoCode = $this->promoCodeRepository->create($payload);
        return (new PromoCodeResource($promoCode))->response()->setStatusCode(201);
    }

    /**
     * Validate a promo code for an event (no login required — preview discount on event page).
     */
    public function validateForEvent(PublicValidatePromoCodeRequest $request, string $code): JsonResponse
    {
        return $this->respondWithEligiblePromoCode(
            $code,
            $request->validated()['event_uuid'],
            $request->user(),
            requireAuthenticatedUser: false,
        );
    }

    /**
     * Display a promo code by UUID for checkout (customer route — requires login + event_uuid).
     */
    public function showForCustomer(PublicShowPromoCodeRequest $request, string $uuid): JsonResponse
    {
        $eventUuid = $request->validated()['event_uuid'] ?? null;
        if (!$eventUuid) {
            return response()->json([
                'success' => false,
                'message' => 'Event is required to validate this promo code',
            ], 422);
        }

        return $this->respondWithEligiblePromoCodeByUuid(
            $uuid,
            $eventUuid,
            $request->user(),
            requireAuthenticatedUser: true,
        );
    }

    /**
     * Display the specified promo code (customer route — requires login).
     */
    public function publicCode(PublicShowPromoCodeRequest $request, string $code): JsonResponse
    {
        $eventUuid = $request->validated()['event_uuid'] ?? null;
        if (!$eventUuid) {
            return response()->json([
                'success' => false,
                'message' => 'Event is required to validate this promo code',
            ], 422);
        }

        return $this->respondWithEligiblePromoCode(
            $code,
            $eventUuid,
            $request->user(),
            requireAuthenticatedUser: true,
        );
    }

    /**
     * @return JsonResponse
     */
    private function respondWithEligiblePromoCode(
        string $code,
        string $eventUuid,
        $user,
        bool $requireAuthenticatedUser,
    ): JsonResponse {
        if ($requireAuthenticatedUser && !$user) {
            return response()->json([
                'success' => false,
                'message' => 'Please login to use this promo code',
            ], 401);
        }

        $normalizedCode = strtoupper(trim($code));
        $promoCode = PromoCode::where('activityable_id', $eventUuid)
            ->whereRaw('UPPER(code) = ?', [$normalizedCode])
            ->where('status', GeneralConstants::GENERAL_STATUSES['ACTIVE'])
            ->first();

        if (!$promoCode) {
            return $this->promoNotFoundResponse($normalizedCode, $eventUuid);
        }

        $periodError = $this->promoPeriodErrorResponse($promoCode);
        if ($periodError !== null) {
            return $periodError;
        }

        return $this->finalizePromoEligibilityResponse($promoCode, $user);
    }

    /**
     * @return JsonResponse
     */
    private function respondWithEligiblePromoCodeByUuid(
        string $uuid,
        string $eventUuid,
        $user,
        bool $requireAuthenticatedUser,
    ): JsonResponse {
        if ($requireAuthenticatedUser && !$user) {
            return response()->json([
                'success' => false,
                'message' => 'Please login to use this promo code',
            ], 401);
        }

        $promoCode = PromoCode::where('uuid', $uuid)
            ->where('activityable_id', $eventUuid)
            ->where('status', GeneralConstants::GENERAL_STATUSES['ACTIVE'])
            ->first();

        if (!$promoCode) {
            return response()->json([
                'success' => false,
                'message' => 'Promo code not found or not valid for this event',
            ], 404);
        }

        $periodError = $this->promoPeriodErrorResponse($promoCode);
        if ($periodError !== null) {
            return $periodError;
        }

        return $this->finalizePromoEligibilityResponse($promoCode, $user);
    }

    /**
     * Promo validity uses inclusive calendar days (not exact clock time).
     */
    private function isPromoWithinValidPeriod(PromoCode $promoCode): bool
    {
        $now = now();

        return $now->gte($this->promoPeriodStart($promoCode))
            && $now->lte($this->promoPeriodEnd($promoCode));
    }

    private function promoPeriodStart(PromoCode $promoCode): Carbon
    {
        return $promoCode->usable_from->copy()->startOfDay();
    }

    private function promoPeriodEnd(PromoCode $promoCode): Carbon
    {
        return $promoCode->usable_to->copy()->endOfDay();
    }

    private function promoPeriodErrorResponse(PromoCode $promoCode): ?JsonResponse
    {
        if ($this->isPromoWithinValidPeriod($promoCode)) {
            return null;
        }

        if (now()->lt($this->promoPeriodStart($promoCode))) {
            return response()->json([
                'success' => false,
                'message' => 'Promo code is not active yet',
            ], 404);
        }

        return response()->json([
            'success' => false,
            'message' => 'Promo code has expired',
        ], 404);
    }

    private function promoNotFoundResponse(string $normalizedCode, string $eventUuid): JsonResponse
    {
        $promoOnOtherEvent = PromoCode::whereRaw('UPPER(code) = ?', [$normalizedCode])
            ->where('status', GeneralConstants::GENERAL_STATUSES['ACTIVE'])
            ->where('activityable_id', '!=', $eventUuid)
            ->exists();

        if ($promoOnOtherEvent) {
            return response()->json([
                'success' => false,
                'message' => 'Promo code is not valid for this activity',
            ], 404);
        }

        return response()->json([
            'success' => false,
            'message' => 'Promo code not found or not valid for this event',
        ], 404);
    }

    private function finalizePromoEligibilityResponse(PromoCode $promoCode, $user): JsonResponse
    {
        if ($promoCode->max_use && $promoCode->used_count >= $promoCode->max_use) {
            return response()->json([
                'success' => false,
                'message' => 'Promo code reached its maximum usage',
            ], 404);
        }

        if ($user !== null && $this->userHasAlreadyUsedPromoCode($user->uuid, $promoCode->uuid)) {
            return response()->json([
                'success' => false,
                'message' => 'You have already used this promo code',
            ], 404);
        }

        return (new PublicPromoCodeResource($promoCode))->response();
    }

    private function userHasAlreadyUsedPromoCode(string $userUuid, string $promoCodeUuid): bool
    {
        return Transaction::query()
            ->where('user_uuid', $userUuid)
            ->where('promo_code_uuid', $promoCodeUuid)
            ->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->exists();
    }

    /**
     * Display the specified promo code.
     * @param string $uuid
     * @return JsonResponse
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $promoCode = $this->promoCodeRepository->fetchOrThrow('uuid', $uuid);
            return (new PromoCodeResource($promoCode))->response();
        } catch (NoPromoCodeFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Promo code not found'
            ], 404);
        }
    }

    /**
     * Update the specified promo code.
     * @param UpdatePromoCodeRequest $request
     * @param string $uuid
     * @return JsonResponse
     */
    public function update(UpdatePromoCodeRequest $request, string $uuid): JsonResponse
    {
        try {
            $payload = $request->validated();
            $promoCode = $this->promoCodeRepository->fetchOrThrow('uuid', $uuid);
            $this->promoCodeRepository->update($promoCode, $payload);
            return (new PromoCodeResource($promoCode->fresh()))->response();
        } catch (NoPromoCodeFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Promo code not found'
            ], 404);
        }
    }

    /**
     * Remove the specified promo code from storage.
     * @param string $uuid
     * @return Response|JsonResponse
     */
    public function destroy(string $uuid): Response|JsonResponse
    {
        try {
            $promoCode = $this->promoCodeRepository->fetchOrThrow('uuid', $uuid);
            $this->promoCodeRepository->delete($promoCode);
            return $this->noContent();
        } catch (NoPromoCodeFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Promo code not found'
            ], 404);
        } catch (UnauthorizedException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 403);
        }
    }
}

