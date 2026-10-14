<?php

namespace App\Http\Controllers;

use App\Constants\GeneralConstants;
use App\Http\Requests\PaymentGateway\CreatePaymentGatewayRequest;
use App\Http\Requests\PaymentGateway\ListPaymentGatewayRequest;
use App\Http\Requests\PaymentGateway\UpdatePaymentGatewayRequest;
use App\Http\Resources\PaymentGatewayResource;
use App\Models\PaymentGateway;
use App\Services\Payments\PaymentGatewayEnvironmentDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class PaymentGatewayController extends Controller
{
    public function __construct(
        private readonly PaymentGatewayEnvironmentDetector $environmentDetector,
    ) {
    }

    public function index(ListPaymentGatewayRequest $request): JsonResponse
    {
        $filters = $request->validated();
        $perPage = $filters['per_page'] ?? 15;

        $list = PaymentGateway::query()
            ->filter($filters)
            ->orderByDesc('created_at')
            ->paginate($perPage);

        return PaymentGatewayResource::collection($list)->response();
    }

    /**
     * Active gateways for selection on an activity. Deliberately omits any key material.
     */
    public function options(): JsonResponse
    {
        $gateways = PaymentGateway::query()
            ->where('status', GeneralConstants::GENERAL_STATUSES['ACTIVE'])
            ->orderBy('provider')
            ->orderBy('name')
            ->get(['uuid', 'name', 'provider', 'environment', 'gateway_id', 'status']);

        return response()->json(['data' => $gateways]);
    }

    public function store(CreatePaymentGatewayRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $payload['status'] ??= GeneralConstants::GENERAL_STATUSES['ACTIVE'];
        $payload['environment'] = $this->environmentDetector->detect(
            $payload['provider'],
            $payload['public_key'],
            $payload['secret_key'],
            $payload['api_username'] ?? null,
            $payload['api_password'] ?? null,
        );
        $payload['created_by'] = auth('admin')->id();

        $gateway = PaymentGateway::create($payload);

        return (new PaymentGatewayResource($gateway))->response()->setStatusCode(201);
    }

    public function show(string $uuid): JsonResponse
    {
        $gateway = PaymentGateway::query()->findOrFail($uuid);

        return (new PaymentGatewayResource($gateway))->response();
    }

    public function update(UpdatePaymentGatewayRequest $request, string $uuid): JsonResponse
    {
        $gateway = PaymentGateway::query()->findOrFail($uuid);

        $gateway->fill($request->validated());

        if ($gateway->provider !== GeneralConstants::PAYMENT_GATEWAY_PROVIDERS['PAYNAMICS']) {
            $gateway->api_username = null;
            $gateway->api_password = null;
            $gateway->paybiz_wallet_id = null;
            $gateway->payment_channels = null;
        } elseif (blank($gateway->api_username) || blank($gateway->api_password)) {
            throw ValidationException::withMessages(array_filter([
                'api_username' => blank($gateway->api_username) ? 'The Paynamics API username is required.' : null,
                'api_password' => blank($gateway->api_password) ? 'The Paynamics API password is required.' : null,
            ]));
        }

        if ($gateway->isDirty(['provider', 'public_key', 'secret_key', 'api_username', 'api_password'])) {
            $gateway->environment = $this->environmentDetector->detect(
                $gateway->provider,
                $gateway->public_key,
                $gateway->secret_key,
                $gateway->api_username,
                $gateway->api_password,
            );
        }

        $gateway->updated_by = auth('admin')->id();
        $gateway->save();

        return (new PaymentGatewayResource($gateway))->response();
    }

    public function destroy(string $uuid): Response
    {
        PaymentGateway::query()->findOrFail($uuid)->delete();

        return response()->noContent();
    }
}
