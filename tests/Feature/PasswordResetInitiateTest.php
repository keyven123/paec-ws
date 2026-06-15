<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Events\OtpWasSent;
use App\Events\PasswordResetExpirationWasRefreshed;
use App\Events\PasswordResetWasCreated;
use App\Models\AdminUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class PasswordResetInitiateTest extends TestCase
{
    use RefreshDatabase;

    private const SUCCESS_MESSAGE = 'If an account is registered with this email, you will receive a password reset link shortly.';

    private Role $customerRole;

    private Role $adminRole;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([
            OtpWasSent::class,
            PasswordResetWasCreated::class,
            PasswordResetExpirationWasRefreshed::class,
        ]);

        $this->customerRole = Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);

        $this->adminRole = Role::create([
            'name' => 'Admin',
            'code' => GeneralConstants::ROLES['ADMIN']['name'],
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itInitiatesPasswordResetForEmailWithHyphenInDomain(): void
    {
        User::create([
            'role_uuid' => $this->customerRole->uuid,
            'email' => 'rj@e-vents.ph',
            'password' => 'password123',
            'first_name' => 'RJ',
            'last_name' => 'Events',
            'birth_date' => '1990-01-01',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $response = $this->postJson('/api/v1/password_reset/initiate', [
            'email' => 'rj@e-vents.ph',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.message', self::SUCCESS_MESSAGE);

        $this->assertDatabaseHas('password_resets', [
            'email' => 'rj@e-vents.ph',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itMatchesCustomerEmailCaseInsensitively(): void
    {
        User::create([
            'role_uuid' => $this->customerRole->uuid,
            'email' => 'RJ@e-vents.ph',
            'password' => 'password123',
            'first_name' => 'RJ',
            'last_name' => 'Events',
            'birth_date' => '1990-01-01',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $response = $this->postJson('/api/v1/password_reset/initiate', [
            'email' => 'rj@e-vents.ph',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('password_resets', [
            'email' => 'rj@e-vents.ph',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsSuccessForUnknownCustomerEmailWithoutCreatingReset(): void
    {
        $response = $this->postJson('/api/v1/password_reset/initiate', [
            'email' => 'rj@e-vents.ph',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.message', self::SUCCESS_MESSAGE);

        $this->assertDatabaseCount('password_resets', 0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsSuccessWhenEmailOnlyExistsOnAdminUsersWithoutCustomerReset(): void
    {
        AdminUser::create([
            'role_uuid' => $this->adminRole->uuid,
            'email' => 'rj@e-vents.ph',
            'password' => 'password123',
            'first_name' => 'RJ',
            'last_name' => 'Events',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $response = $this->postJson('/api/v1/password_reset/initiate', [
            'email' => 'rj@e-vents.ph',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.message', self::SUCCESS_MESSAGE);

        $this->assertDatabaseCount('password_resets', 0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturnsSuccessForInactiveCustomerWithoutCreatingReset(): void
    {
        User::create([
            'role_uuid' => $this->customerRole->uuid,
            'email' => 'inactive@e-vents.ph',
            'password' => 'password123',
            'first_name' => 'Inactive',
            'last_name' => 'User',
            'birth_date' => '1990-01-01',
            'status' => GeneralConstants::GENERAL_STATUSES['INACTIVE'],
        ]);

        $response = $this->postJson('/api/v1/password_reset/initiate', [
            'email' => 'inactive@e-vents.ph',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.message', self::SUCCESS_MESSAGE);

        $this->assertDatabaseCount('password_resets', 0);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itInitiatesAdminPasswordResetForEmailWithHyphenInDomain(): void
    {
        AdminUser::create([
            'role_uuid' => $this->adminRole->uuid,
            'email' => 'rj@e-vents.ph',
            'password' => 'password123',
            'first_name' => 'RJ',
            'last_name' => 'Events',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $response = $this->postJson('/api/v1/password_reset/admin_initiate', [
            'email' => 'rj@e-vents.ph',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.message', self::SUCCESS_MESSAGE);

        $this->assertDatabaseHas('password_resets', [
            'email' => 'rj@e-vents.ph',
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRejectsInvalidEmailFormat(): void
    {
        $response = $this->postJson('/api/v1/password_reset/initiate', [
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }
}
