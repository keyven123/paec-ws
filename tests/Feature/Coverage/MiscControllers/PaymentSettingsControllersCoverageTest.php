<?php

namespace Tests\Feature\Coverage\MiscControllers;

use App\Constants\GeneralConstants;
use App\Models\Dataset;
use App\Models\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaymentSettingsControllersCoverageTest extends TestCase
{
    use MiscFixtures;
    use RefreshDatabase;

    #[Test]
    public function paymentGatewayShowReturnsGatewayWithoutSecrets(): void
    {
        $gateway = PaymentGateway::create([
            'name' => 'PayMongo Main',
            'provider' => 'paymongo',
            'environment' => 'testing',
            'gateway_id' => 'paymongo',
            'public_key' => 'pk_test_1234567890abcdef',
            'secret_key' => 'sk_test_abcdefghijklmnop9876',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $response = $this->withHeaders($this->adminHeaders())
            ->getJson("/api/v1/payment-gateways/{$gateway->uuid}")
            ->assertOk()
            ->assertJsonPath('data.uuid', $gateway->uuid)
            ->assertJsonPath('data.name', 'PayMongo Main');

        $this->assertStringNotContainsString('sk_test_abcdefghijklmnop9876', $response->getContent());
    }

    #[Test]
    public function rateSettingsUpdatePersistsPaymongoAndPaypalRates(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->putJson('/api/v1/payment-gateway-rate-settings', [
                'paymongo' => [
                    'qrph' => 1.5,
                    'card' => 3.5,
                    'gcash' => null,
                    'dob' => ['percentage' => 0.8, 'fixed_minimum' => 15],
                ],
                'paypal' => [
                    'paypal_fee' => 4.4,
                    'additional_fee' => null,
                ],
            ])
            ->assertOk();

        $response->assertJsonPath('data.paymongo.card', 3.5)
            ->assertJsonPath('data.paymongo.gcash', null)
            ->assertJsonPath('data.paymongo.dob.percentage', 0.8)
            ->assertJsonPath('data.paymongo.dob.fixed_minimum', 15)
            ->assertJsonPath('data.paypal.paypal_fee', 4.4)
            ->assertJsonPath('data.paypal.additional_fee', null);
        $this->assertNotNull($response->json('data.paymongo_updated_at'));
        $this->assertNotNull($response->json('data.paypal_updated_at'));

        $paymongo = Dataset::paymongoRates();
        $this->assertSame(3.5, $paymongo['card']);
        $this->assertNull($paymongo['gcash']);
        $this->assertSame(0.8, $paymongo['dob']);
        $this->assertSame(15.0, $paymongo['dob_fixed_minimum']);
        $this->assertSame(4.4, Dataset::paypalRates()['paypal_fee']);
    }

    #[Test]
    public function rateSettingsUpdateClearsDobWhenNestedValuesAreNull(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->putJson('/api/v1/payment-gateway-rate-settings', [
                'paymongo' => ['dob' => ['percentage' => 2, 'fixed_minimum' => 10]],
                'paypal' => ['paypal_fee' => 3],
            ])
            ->assertOk();

        $this->withHeaders($this->adminHeaders())
            ->putJson('/api/v1/payment-gateway-rate-settings', [
                'paymongo' => ['dob' => ['percentage' => null]],
                'paypal' => ['additional_fee' => null],
            ])
            ->assertOk()
            ->assertJsonPath('data.paymongo.dob.percentage', null)
            ->assertJsonPath('data.paymongo.dob.fixed_minimum', null)
            ->assertJsonPath('data.paypal.paypal_fee', 3);
    }

    #[Test]
    public function rateSettingsUpdateRequiresBothProviderPayloads(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->putJson('/api/v1/payment-gateway-rate-settings', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['paymongo', 'paypal']);
    }
}
