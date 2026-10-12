<?php

namespace App\Services\Payments;

use App\Constants\GeneralConstants;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Works out whether a gateway's keys are testing (sandbox) or production (live) keys.
 *
 * Returns null when the environment cannot be determined (PayPal or Paynamics could not be
 * reached). Throws a ValidationException when the keys are clearly wrong for the provider.
 */
class PaymentGatewayEnvironmentDetector
{
    private const PAYPAL_HOSTS = [
        'testing' => 'https://api-m.sandbox.paypal.com',
        'production' => 'https://api-m.paypal.com',
    ];

    public function detect(
        string $provider,
        string $publicKey,
        string $secretKey,
        ?string $apiUsername = null,
        ?string $apiPassword = null,
    ): ?string {
        return match ($provider) {
            GeneralConstants::PAYMENT_GATEWAY_PROVIDERS['PAYMONGO'] => $this->detectPayMongo($publicKey, $secretKey),
            GeneralConstants::PAYMENT_GATEWAY_PROVIDERS['PAYPAL'] => $this->detectPayPal($publicKey, $secretKey),
            GeneralConstants::PAYMENT_GATEWAY_PROVIDERS['PAYNAMICS'] => $this->detectPaynamics(
                $publicKey,
                $secretKey,
                (string) $apiUsername,
                (string) $apiPassword,
            ),
            default => null,
        };
    }

    /**
     * Paynamics keys look the same in both environments, so ask each configured PayGate host.
     */
    private function detectPaynamics(string $merchantId, string $merchantKey, string $apiUsername, string $apiPassword): ?string
    {
        $hosts = array_filter([
            GeneralConstants::PAYMENT_GATEWAY_ENVIRONMENTS['TESTING'] => (string) config('services.paynamics.testing_url'),
            GeneralConstants::PAYMENT_GATEWAY_ENVIRONMENTS['PRODUCTION'] => (string) config('services.paynamics.production_url'),
        ]);

        $unreachable = false;
        $badSignature = false;

        foreach ($hosts as $environment => $host) {
            $service = new PaynamicsService($merchantId, $merchantKey, $apiUsername, $apiPassword, rtrim($host, '/'));

            $result = $service->checkCredentials();
            if ($result === PaynamicsService::CREDENTIALS_ACCEPTED) {
                return $environment;
            }

            $unreachable = $unreachable || $result === PaynamicsService::CREDENTIALS_UNREACHABLE;
            $badSignature = $badSignature || $result === PaynamicsService::CREDENTIALS_BAD_SIGNATURE;
        }

        if ($badSignature) {
            throw ValidationException::withMessages([
                'secret_key' => 'Paynamics accepted the API username and password but rejected the signature. Check the merchant ID and merchant key.',
            ]);
        }

        if ($unreachable) {
            return null;
        }

        $message = 'Paynamics did not accept this API username and password.';
        if (! isset($hosts[GeneralConstants::PAYMENT_GATEWAY_ENVIRONMENTS['PRODUCTION']])) {
            $message .= ' Only the test environment was checked; live credentials need PAYNAMICS_PRODUCTION_URL set on the server.';
        }

        throw ValidationException::withMessages(['api_password' => $message]);
    }

    private function detectPayMongo(string $publicKey, string $secretKey): string
    {
        $publicMode = $this->payMongoMode($publicKey, 'pk');
        $secretMode = $this->payMongoMode($secretKey, 'sk');

        $errors = [];
        if ($publicMode === null) {
            $errors['public_key'] = 'PayMongo public keys start with pk_test_ or pk_live_.';
        }
        if ($secretMode === null) {
            $errors['secret_key'] = 'PayMongo secret keys start with sk_test_ or sk_live_.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($publicMode !== $secretMode) {
            throw ValidationException::withMessages([
                'secret_key' => 'The public and secret keys must both be test keys or both be live keys.',
            ]);
        }

        return $publicMode;
    }

    private function payMongoMode(string $key, string $prefix): ?string
    {
        return match (true) {
            str_starts_with($key, "{$prefix}_test_") => GeneralConstants::PAYMENT_GATEWAY_ENVIRONMENTS['TESTING'],
            str_starts_with($key, "{$prefix}_live_") => GeneralConstants::PAYMENT_GATEWAY_ENVIRONMENTS['PRODUCTION'],
            default => null,
        };
    }

    /**
     * PayPal client IDs look the same in both environments, so ask each one for a token.
     */
    private function detectPayPal(string $clientId, string $clientSecret): ?string
    {
        $unreachable = false;

        foreach (self::PAYPAL_HOSTS as $environment => $host) {
            try {
                $response = Http::withBasicAuth($clientId, $clientSecret)
                    ->asForm()
                    ->acceptJson()
                    ->timeout(10)
                    ->post("{$host}/v1/oauth2/token", ['grant_type' => 'client_credentials']);
            } catch (ConnectionException $e) {
                Log::warning('PayPal environment check could not connect', [
                    'host' => $host,
                    'error' => $e->getMessage(),
                ]);
                $unreachable = true;
                continue;
            }

            if ($response->successful()) {
                return $environment;
            }

            if ($response->serverError()) {
                $unreachable = true;
            }
        }

        if ($unreachable) {
            return null;
        }

        throw ValidationException::withMessages([
            'secret_key' => 'PayPal did not accept this client ID and secret in either sandbox or live.',
        ]);
    }
}
