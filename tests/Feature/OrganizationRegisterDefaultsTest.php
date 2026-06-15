<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\Dataset;
use App\Models\Organization;
use App\Models\Role;
use App\Support\OrganizationPaymentMethods;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationRegisterDefaultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create([
            'name' => 'Organizer',
            'code' => GeneralConstants::ROLES['ORGANIZER']['name'],
        ]);

        Dataset::create([
            'name' => 'merchant_commission_percentage',
            'value' => '12.5',
            'description' => 'Default merchant commission',
        ]);

        Dataset::create([
            'name' => 'default_payment_methods',
            'description' => 'The default payment methods',
            'value' => json_encode([
                ['name' => 'qrph', 'value' => true, 'provider' => 'paymongo'],
                ['name' => 'paypal', 'value' => true, 'provider' => 'paypal'],
            ]),
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itAssignsDefaultCommissionPaymentMethodsAndLogsInitialCommissionOnRegister(): void
    {
        $response = $this->postJson('/api/v1/organizations/register', [
            'name' => 'New Register Merchant',
            'business_type' => Organization::BUSINESS_TYPE_SOLE_PROPRIETORSHIP,
            'representative_first_name' => 'John',
            'representative_last_name' => 'Merchant',
            'address' => '456 Register Ave',
            'contact_number' => '09179876543',
            'email' => 'register_merchant@test.com',
            'description' => 'A new merchant partner registering on the platform.',
            'password' => 'SecurePass1!',
            'password_confirmation' => 'SecurePass1!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['access_token', 'admin_user']);

        $organization = Organization::where('email', 'register_merchant@test.com')->first();
        $this->assertNotNull($organization);
        $this->assertEquals(Organization::BUSINESS_TYPE_SOLE_PROPRIETORSHIP, $organization->business_type);
        $this->assertEquals(12.5, (float) $organization->commission_percentage);

        $normalized = OrganizationPaymentMethods::normalize($organization->payment_methods);
        $qrph = collect($normalized)->firstWhere('name', 'qrph');
        $paypal = collect($normalized)->firstWhere('name', 'paypal');
        $this->assertTrue($qrph['value']);
        $this->assertTrue($paypal['value']);

        $this->assertDatabaseHas('organization_platform_coms', [
            'organization_uuid' => $organization->uuid,
            'previous_coms' => null,
            'current_coms' => '12.50',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itAcceptsEmailWithHyphenInDomainOnRegister(): void
    {
        $response = $this->postJson('/api/v1/organizations/register', [
            'name' => 'Hyphen Domain Merchant',
            'business_type' => Organization::BUSINESS_TYPE_CORPORATION,
            'representative_first_name' => 'Jane',
            'representative_last_name' => 'Doe',
            'address' => '123 Hyphen St',
            'contact_number' => '09171234567',
            'email' => 'partner@my-company.co.uk',
            'description' => 'Merchant with a hyphenated domain email.',
            'password' => 'SecurePass1!',
            'password_confirmation' => 'SecurePass1!',
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('organizations', [
            'email' => 'partner@my-company.co.uk',
            'business_type' => Organization::BUSINESS_TYPE_CORPORATION,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresBusinessTypeOnRegister(): void
    {
        $response = $this->postJson('/api/v1/organizations/register', [
            'name' => 'Missing Business Type Merchant',
            'representative_first_name' => 'John',
            'representative_last_name' => 'Merchant',
            'address' => '456 Register Ave',
            'contact_number' => '09179876543',
            'email' => 'missing_business_type@test.com',
            'description' => 'A new merchant partner registering on the platform.',
            'password' => 'SecurePass1!',
            'password_confirmation' => 'SecurePass1!',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['business_type']);
    }
}
