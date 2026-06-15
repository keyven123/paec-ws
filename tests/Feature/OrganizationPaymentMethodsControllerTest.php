<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Support\OrganizationPaymentMethods;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

class OrganizationPaymentMethodsControllerTest extends TestCase
{
    use GrantsAdminPermissions;
    use RefreshDatabase;

    private AdminUser $adminUser;

    private Role $adminRole;

    private string $adminToken;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminRole = Role::create([
            'name' => 'Admin',
            'code' => GeneralConstants::ROLES['ADMIN']['name'],
        ]);

        $this->grantRolePermissions($this->adminRole, [
            'organizations' => ['view'],
            'payment-methods' => ['view', 'update'],
        ]);

        $this->adminUser = AdminUser::create([
            'role_uuid' => $this->adminRole->uuid,
            'email' => 'admin@test.com',
            'password' => 'password123',
            'first_name' => 'Regular',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $this->adminToken = auth('admin')->login($this->adminUser) ?? '';

        $this->organization = Organization::create([
            'name' => 'Test Merchant',
            'representative_first_name' => 'Jane',
            'representative_last_name' => 'Doe',
            'address' => '123 Test Street',
            'contact_number' => '09171234567',
            'email' => 'merchant@test.com',
            'bank_name' => 'Test Bank',
            'bank_branch' => 'Main',
            'bank_address' => 'Bank Address',
            'bank_account_name' => 'Jane Doe',
            'bank_account_number' => '1234567890',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsNormalizedPaymentMethodsOnShow(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/organizations/' . $this->organization->uuid);

        $response->assertStatus(200)
            ->assertJsonPath('data.uuid', $this->organization->uuid)
            ->assertJsonStructure([
                'data' => [
                    'payment_methods' => [
                        '*' => ['name', 'value', 'provider'],
                    ],
                ],
            ]);

        $paymentMethods = $response->json('data.payment_methods');
        $this->assertCount(count(OrganizationPaymentMethods::allKeys()), $paymentMethods);
        $this->assertSame(
            OrganizationPaymentMethods::defaults(),
            $paymentMethods,
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanUpdateOrganizationPaymentMethods(): void
    {
        $payload = array_map(
            static fn (string $name) => [
                'name' => $name,
                'value' => in_array($name, ['qrph', 'gcash', 'paypal'], true),
            ],
            OrganizationPaymentMethods::allKeys(),
        );

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/v1/organizations/' . $this->organization->uuid . '/payment-methods', [
            'payment_methods' => $payload,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.payment_methods', OrganizationPaymentMethods::normalize($payload));

        $organization = $this->organization->fresh();
        $this->assertSame(
            OrganizationPaymentMethods::normalize($payload),
            $organization->payment_methods,
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesUnknownPaymentMethodNames(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/v1/organizations/' . $this->organization->uuid . '/payment-methods', [
            'payment_methods' => [
                ['name' => 'invalid_method', 'value' => true],
            ],
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payment_methods.0.name']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsValidationErrorWhenUpdatingPaymentMethodsForUnknownOrganization(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->putJson('/api/v1/organizations/' . fake()->uuid() . '/payment-methods', [
            'payment_methods' => OrganizationPaymentMethods::defaults(),
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['uuid']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresAuthenticationForPaymentMethodsEndpoints(): void
    {
        $this->getJson('/api/v1/organizations/' . $this->organization->uuid)
            ->assertStatus(401);

        $this->putJson('/api/v1/organizations/' . $this->organization->uuid . '/payment-methods', [
            'payment_methods' => OrganizationPaymentMethods::defaults(),
        ])->assertStatus(401);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresUpdatePermissionToChangePaymentMethods(): void
    {
        $viewOnlyRole = Role::create([
            'name' => 'Payment Methods Viewer',
            'code' => 'payment-methods-viewer',
        ]);

        $this->grantRolePermissions($viewOnlyRole, [
            'payment-methods' => ['view'],
        ]);

        $viewer = AdminUser::create([
            'role_uuid' => $viewOnlyRole->uuid,
            'email' => 'viewer@test.com',
            'password' => 'password123',
            'first_name' => 'View',
            'last_name' => 'Only',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $viewerToken = auth('admin')->login($viewer) ?? '';

        $this->withHeaders([
            'Authorization' => 'Bearer ' . $viewerToken,
        ])->putJson('/api/v1/organizations/' . $this->organization->uuid . '/payment-methods', [
            'payment_methods' => OrganizationPaymentMethods::defaults(),
        ])->assertStatus(403);
    }
}
