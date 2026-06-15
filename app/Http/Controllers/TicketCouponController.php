<?php

namespace App\Http\Controllers;

use App\Exceptions\NoResourceFoundException;
use App\Http\Controllers\Controller;
use App\Http\Repositories\TicketCouponRepository;
use App\Http\Requests\TicketCoupon\GetByQrCodeRequest;
use App\Http\Requests\TicketCoupon\ListTicketCouponRequest;
use App\Http\Resources\TicketCouponResource;
use Illuminate\Http\JsonResponse;

class TicketCouponController extends Controller
{
    public function __construct(protected TicketCouponRepository $ticketCouponRepository)
    {
    }

    /**
     * List coupons for the authenticated customer (My Coupons), latest first.
     *
     * @param ListTicketCouponRequest $request
     * @return JsonResponse
     */
    public function index(ListTicketCouponRequest $request): JsonResponse
    {
        $perPage = $request->get('per_page', 10);
        $payload = $request->validated();
        $coupons = $this->ticketCouponRepository->getAll($payload);

        return TicketCouponResource::collection($coupons->paginate($perPage))->response();
    }

    public function getByQrCode(GetByQrCodeRequest $request): JsonResponse
    {
        try {
            $payload = $request->validated();

            $coupon = $this->ticketCouponRepository->getByQrCode($payload);
            if (!$coupon) {
                throw new NoResourceFoundException('Invalid coupon.');
            }
            return (new TicketCouponResource($coupon))->response();
        } catch (NoResourceFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid coupon.'
            ], 404);
        }
    }

    public function confirmClaimed(string $uuid): JsonResponse
    {
        $coupon = $this->ticketCouponRepository->getByUuid($uuid);
        if (!$coupon) {
            return response()->json([
                'success' => false,
                'message' => 'Coupon not found'
            ], 404);
        }
        $this->ticketCouponRepository->confirmClaimed($coupon->fresh());
        return (new TicketCouponResource($coupon->fresh()))->response();
    }
}
