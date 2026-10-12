<?php

namespace App\Http\Resources;

use App\Models\PaymentGateway;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Credentials are never returned in full; admins only see masked previews.
 */
class PaymentGatewayResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'provider' => $this->provider,
            'environment' => $this->environment,
            'gateway_id' => $this->gateway_id,
            'public_key_preview' => PaymentGateway::maskCredential($this->public_key, 8),
            'secret_key_preview' => PaymentGateway::maskCredential($this->secret_key),
            'api_username_preview' => PaymentGateway::maskCredential($this->api_username, 3),
            'api_password_preview' => PaymentGateway::maskCredential($this->api_password),
            'payment_channels' => $this->provider === 'paynamics' ? $this->enabledPaynamicsChannels() : null,
            'status' => $this->status,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
