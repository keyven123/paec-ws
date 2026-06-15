<?php

namespace App\Http\Controllers;

use App\Http\Repositories\DefaultPaymentMethodsSettingRepository;
use App\Http\Requests\Admin\UpdateDefaultPaymentMethodsSettingRequest;
use Illuminate\Http\JsonResponse;

class DefaultPaymentMethodsSettingController extends Controller
{
    public function __construct(
        protected DefaultPaymentMethodsSettingRepository $defaultPaymentMethodsSettingRepository
    ) {
    }

    public function show(): JsonResponse
    {
        $row = $this->defaultPaymentMethodsSettingRepository->getSingleton();

        return response()->json([
            'data' => [
                'payment_methods' => $this->defaultPaymentMethodsSettingRepository->getNormalizedMethods(),
                'updated_at' => $row->updated_at?->toISOString(),
            ],
        ]);
    }

    public function update(UpdateDefaultPaymentMethodsSettingRequest $request): JsonResponse
    {
        $row = $this->defaultPaymentMethodsSettingRepository->updateSingleton(
            $request->validated('payment_methods'),
        );

        return response()->json([
            'data' => [
                'payment_methods' => $this->defaultPaymentMethodsSettingRepository->getNormalizedMethods(),
                'updated_at' => $row->updated_at?->toISOString(),
            ],
        ]);
    }
}
