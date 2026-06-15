<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\UpdateMerchantCommissionSettingRequest;
use App\Http\Repositories\MerchantCommissionSettingRepository;
use Illuminate\Http\JsonResponse;

class MerchantCommissionSettingController extends Controller
{
    public function __construct(
        protected MerchantCommissionSettingRepository $merchantCommissionSettingRepository
    ) {
    }

    public function show(): JsonResponse
    {
        $row = $this->merchantCommissionSettingRepository->getSingleton();

        return response()->json([
            'data' => [
                'default_commission_percentage' => (float) $row->value,
                'updated_at' => $row->updated_at?->toISOString(),
            ],
        ]);
    }

    public function update(UpdateMerchantCommissionSettingRequest $request): JsonResponse
    {
        $admin = auth('admin')->user();
        $row = $this->merchantCommissionSettingRepository->updateSingleton(
            (float) $request->validated('default_commission_percentage'),
            $admin?->uuid
        );

        return response()->json([
            'data' => [
                'default_commission_percentage' => (float) $row->value,
                'updated_at' => $row->updated_at?->toISOString(),
            ],
        ]);
    }
}
