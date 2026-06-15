<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Http\Repositories\OrganizationRepository;
use App\Models\Dataset;
use App\Models\Organization;
use App\Support\OrganizationPaymentMethods;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAdminWithOrganizationPermissions;
use Tests\TestCase;

class DefaultPaymentMethodsSettingControllerTest extends TestCase
{
    use CreatesAdminWithOrganizationPermissions;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpAdminWithOrganizationPermissions();

        Dataset::create([
            'name' => 'default_payment_methods',
            'description' => 'The default payment methods',
            'value' => json_encode([
                ['name' => 'qrph', 'value' => true, 'provider' => 'paymongo'],
                ['name' => 'paypal', 'value' => false, 'provider' => 'paypal'],
            ]),
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsDefaultPaymentMethods(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/default-payment-methods-settings');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'payment_methods' => [
                        '*' => ['name', 'value', 'provider'],
                    ],
                    'updated_at',
                ],
            ]);

        $paypal = collect($response->json('data.payment_methods'))->firstWhere('name', 'paypal');
        $this->assertFalse($paypal['value']);
        $this->assertCount(count(OrganizationPaymentMethods::allKeys()), $response->json('data.payment_methods'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itUpdatesDefaultPaymentMethods(): void
    {
        $payload = $this->paymentMethodsPayloadWithPaypalEnabled();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/v1/default-payment-methods-settings', [
            'payment_methods' => $payload,
        ]);

        $response->assertStatus(200);

        $paypal = collect($response->json('data.payment_methods'))->firstWhere('name', 'paypal');
        $this->assertTrue($paypal['value']);

        $stored = json_decode(Dataset::where('name', 'default_payment_methods')->value('value'), true);
        $storedPaypal = collect($stored)->firstWhere('name', 'paypal');
        $this->assertTrue($storedPaypal['value']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itDoesNotOverwriteExistingOrganizationPaymentMethodsWhenDefaultIsUpdated(): void
    {
        $organization = Organization::create([
            'name' => 'Existing Merchant',
            'representative_first_name' => 'Jane',
            'representative_last_name' => 'Doe',
            'address' => '123 Test Street',
            'contact_number' => '09171234567',
            'email' => 'existing-pm@test.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
            'payment_methods' => OrganizationPaymentMethods::normalize([
                ['name' => 'qrph', 'value' => false, 'provider' => 'paymongo'],
                ['name' => 'paypal', 'value' => false, 'provider' => 'paypal'],
            ]),
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/v1/default-payment-methods-settings', [
            'payment_methods' => $this->paymentMethodsPayloadWithPaypalEnabled(),
        ])->assertStatus(200);

        $organization->refresh();
        $qrph = collect(OrganizationPaymentMethods::normalize($organization->payment_methods))
            ->firstWhere('name', 'qrph');
        $paypal = collect(OrganizationPaymentMethods::normalize($organization->payment_methods))
            ->firstWhere('name', 'paypal');

        $this->assertFalse($qrph['value']);
        $this->assertFalse($paypal['value']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itAssignsDefaultPaymentMethodsWhenOrganizationIsCreated(): void
    {
        $repository = app(OrganizationRepository::class);

        $org = $repository->create([
            'name' => 'New Merchant',
            'representative_first_name' => 'Jane',
            'representative_last_name' => 'Doe',
            'address' => '123 Test Street',
            'contact_number' => '09171234567',
            'email' => 'new-merchant@test.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ]);

        $normalized = OrganizationPaymentMethods::normalize($org->payment_methods);
        $qrph = collect($normalized)->firstWhere('name', 'qrph');
        $paypal = collect($normalized)->firstWhere('name', 'paypal');

        $this->assertTrue($qrph['value']);
        $this->assertFalse($paypal['value']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesPaymentMethodNamesOnUpdate(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/v1/default-payment-methods-settings', [
            'payment_methods' => [
                ['name' => 'invalid_method', 'value' => true, 'provider' => 'paymongo'],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payment_methods.0.name']);
    }
}
