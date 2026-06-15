<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Http\Repositories\TicketCouponRepository;
use App\Http\Requests\TicketCoupon\ListTicketCouponRequest;
use App\Http\Resources\TicketCouponResource;
use Illuminate\Http\JsonResponse;

class CouponController extends Controller
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
    public function getMyCoupons(ListTicketCouponRequest $request): JsonResponse
    {
        $perPage = $request->get('per_page', 10);
        $payload = $request->validated();
        $coupons = $this->ticketCouponRepository->getMyCoupons($request->user()->uuid, $payload);

        return TicketCouponResource::collection($coupons->paginate($perPage))->response();
    }
}
