<?php

namespace App\Services\Payments;

use App\Constants\GeneralConstants;
use App\Models\PaymentGateway;
use App\Models\Transaction;
use InvalidArgumentException;

class PaymentServiceFactory
{
    /**
     * Create payment service instance based on provider
     *
     * @param string $provider
     * @return PaymentServiceInterface
     * @throws InvalidArgumentException
     */
    public static function create(string $provider): PaymentServiceInterface
    {
        return match (strtolower($provider)) {
            GeneralConstants::PAYMENT_PROVIDERS['PAYMONGO'] => new PayMongoService(),
            GeneralConstants::PAYMENT_PROVIDERS['PAYPAL'] => new PayPalService(),
            default => throw new InvalidArgumentException("Unsupported payment provider: {$provider}")
        };
    }

    /**
     * Payment service that charges through an activity's own payment gateway keys.
     *
     * @throws InvalidArgumentException
     * @throws \RuntimeException When a production Paynamics gateway has no live URL configured.
     */
    public static function forGateway(PaymentGateway $gateway): PaymentServiceInterface
    {
        return match ($gateway->provider) {
            GeneralConstants::PAYMENT_GATEWAY_PROVIDERS['PAYMONGO'] => new PayMongoService(
                $gateway->secret_key,
                $gateway->public_key,
            ),
            GeneralConstants::PAYMENT_GATEWAY_PROVIDERS['PAYPAL'] => new PayPalService(
                $gateway->public_key,
                $gateway->secret_key,
                $gateway->environment === GeneralConstants::PAYMENT_GATEWAY_ENVIRONMENTS['PRODUCTION'] ? 'live' : 'sandbox',
            ),
            GeneralConstants::PAYMENT_GATEWAY_PROVIDERS['PAYNAMICS'] => new PaynamicsService(
                (string) $gateway->public_key,
                (string) $gateway->secret_key,
                (string) $gateway->api_username,
                (string) $gateway->api_password,
                PaynamicsService::baseUrlFor($gateway->environment),
                $gateway->paybiz_wallet_id,
            ),
            default => throw new InvalidArgumentException("Online checkout is not available for {$gateway->provider} yet."),
        };
    }

    /**
     * Payment service for verifying, capturing or cancelling an existing transaction,
     * using the same gateway keys it was checked out with.
     *
     * @throws InvalidArgumentException
     */
    public static function forTransaction(Transaction $transaction): PaymentServiceInterface
    {
        if ($transaction->payment_gateway_uuid) {
            $gateway = PaymentGateway::withTrashed()->find($transaction->payment_gateway_uuid);
            if ($gateway) {
                return self::forGateway($gateway);
            }
        }

        return self::create((string) $transaction->payment_provider);
    }

    /**
     * Get all available payment providers
     *
     * @return array
     */
    public static function getAvailableProviders(): array
    {
        return array_values(GeneralConstants::PAYMENT_PROVIDERS);
    }

    /**
     * Check if provider is supported
     *
     * @param string $provider
     * @return bool
     */
    public static function isProviderSupported(string $provider): bool
    {
        return in_array(strtolower($provider), array_values(GeneralConstants::PAYMENT_PROVIDERS));
    }
}
