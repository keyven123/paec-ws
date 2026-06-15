<?php

namespace App\Services\Payments;

use App\Constants\GeneralConstants;
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
