<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\Role;
use App\Models\User;
use App\Models\ConfirmationToken;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Notifications\EmailVerificationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Carbon\Carbon;

class AuthControllerTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    private Role $customerRole;
    private Role $adminRole;
    private User $verifiedUser;
    private User $unverifiedUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles
        $this->customerRole = Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);

        $this->adminRole = Role::create([
            'name' => 'Admin',
            'code' => GeneralConstants::ROLES['ADMIN']['name'],
        ]);

        // Create a verified user
        $this->verifiedUser = User::create([
            'role_uuid' => $this->customerRole->uuid,
            'email' => 'verified@test.com',
            'password' => 'password123',
            'first_name' => 'Verified',
            'last_name' => 'User',
            'birth_date' => '1990-01-01',
            'email_verified_at' => now(),
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        // Create an unverified user
        $this->unverifiedUser = User::create([
            'role_uuid' => $this->customerRole->uuid,
            'email' => 'unverified@test.com',
            'password' => 'password123',
            'first_name' => 'Unverified',
            'last_name' => 'User',
            'birth_date' => '1990-01-01',
            'email_verified_at' => null,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanRegisterANewUser()
    {
        $userData = [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'address' => '123 Test Street, Manila',
            'email' => 'john@example.com',
            'phone_number' => '+639171234567',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'terms_accepted' => true,
        ];

        $response = $this->postJson('/api/v1/register', $userData);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'access_token',
                'user' => [
                    'uuid',
                    'first_name',
                    'last_name',
                    'email',
                ],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'john@example.com',
            'phone_number' => '+639171234567',
            'first_name' => 'John',
            'last_name' => 'Doe',
            'address_line_1' => '123 Test Street, Manila',
        ]);

        $user = User::where('email', 'john@example.com')->first();
        $this->assertNotNull($user->email_verified_at);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesRegistrationData()
    {
        $response = $this->postJson('/api/v1/register', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'first_name',
                'last_name',
                'address',
                'email',
                'phone_number',
                'password',
                'terms_accepted',
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itPreventsRegistrationWithDuplicateEmail()
    {
        $userData = [
            'first_name' => 'John',
            'last_name' => 'Doe',
            'address' => '123 Test Street, Manila',
            'email' => 'verified@test.com',
            'phone_number' => '+639181234567',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'terms_accepted' => true,
        ];

        $response = $this->postJson('/api/v1/register', $userData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itPreventsRegistrationWithDuplicatePhoneNumber()
    {
        User::where('email', 'verified@test.com')->update(['phone_number' => '+639171111111']);

        $userData = [
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'birth_date' => '1991-05-05',
            'email' => 'jane.unique@example.com',
            'phone_number' => '+639171111111',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'marketing_consent' => true,
        ];

        $response = $this->postJson('/api/v1/register', $userData);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone_number']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanLoginWithValidCredentials()
    {
        $response = $this->postJson('/api/v1/login', [
            'email' => 'verified@test.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'expires_in',
            ]);

        $this->assertEquals('Bearer', $response->json('token_type'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCannotLoginWithInvalidCredentials()
    {
        $response = $this->postJson('/api/v1/login', [
            'email' => 'verified@test.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCannotLoginWithUnverifiedEmail()
    {
        $response = $this->postJson('/api/v1/login', [
            'email' => 'unverified@test.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'message' => 'Email not verified'
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanGetAuthenticatedUserInfo()
    {
        $token = JWTAuth::fromUser($this->verifiedUser);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->getJson('/api/v1/me');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'uuid',
                    'email',
                    'first_name',
                    'last_name',
                ]
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanRefreshToken()
    {
        $token = JWTAuth::fromUser($this->verifiedUser);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/v1/refresh');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'expires_in',
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanLogout()
    {
        $token = JWTAuth::fromUser($this->verifiedUser);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $token,
        ])->postJson('/api/v1/logout');

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Logged out'
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanVerifyEmail()
    {
        // Create confirmation token
        $confirmationToken = ConfirmationToken::createForUser($this->unverifiedUser->uuid, 60);

        $response = $this->postJson('/api/v1/verify-email', [
            'email' => $this->unverifiedUser->email,
            'token' => $confirmationToken->token,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => __('auth.email_verified_successfully'),
            ])
            ->assertJsonStructure([
                'access_token',
                'token_type',
                'user',
                'role',
                'expires_in',
            ]);

        $this->unverifiedUser->refresh();
        $this->assertNotNull($this->unverifiedUser->email_verified_at);
        $this->assertNotNull($response->json('user.email_verified_at'));

        // Token should be deleted
        $this->assertDatabaseMissing('confirmation_tokens', [
            'id' => $confirmationToken->id,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCannotVerifyEmailWithInvalidToken()
    {
        $response = $this->postJson('/api/v1/verify-email', [
            'email' => $this->unverifiedUser->email,
            'token' => '123456',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => __('auth.invalid_or_expired_token'),
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCannotVerifyEmailForNonExistentUser()
    {
        $response = $this->postJson('/api/v1/verify-email', [
            'email' => 'nonexistent@test.com',
            'token' => '123456',
        ]);

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => __('auth.user_not_found'),
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCannotVerifyAlreadyVerifiedEmail()
    {
        $confirmationToken = ConfirmationToken::createForUser($this->verifiedUser->uuid, 60);

        $response = $this->postJson('/api/v1/verify-email', [
            'email' => $this->verifiedUser->email,
            'token' => $confirmationToken->token,
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => __('auth.email_already_verified'),
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanResendVerificationEmail()
    {
        Notification::fake();

        $response = $this->postJson('/api/v1/resend-verification', [
            'email' => $this->unverifiedUser->email,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'message' => __('auth.verification_email_sent'),
            ]);

        // Assert notification was sent
        Notification::assertSentTo($this->unverifiedUser, EmailVerificationNotification::class);

        // Assert new confirmation token was created
        $this->assertDatabaseHas('confirmation_tokens', [
            'user_uuid' => $this->unverifiedUser->uuid,
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCannotResendVerificationForVerifiedUser()
    {
        $response = $this->postJson('/api/v1/resend-verification', [
            'email' => $this->verifiedUser->email,
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => __('auth.email_already_verified'),
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCannotResendVerificationForNonExistentUser()
    {
        $response = $this->postJson('/api/v1/resend-verification', [
            'email' => 'nonexistent@test.com',
        ]);

        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => __('auth.user_not_found'),
            ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesEmailVerificationData()
    {
        $response = $this->postJson('/api/v1/verify-email', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'token']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesTokenLength()
    {
        $response = $this->postJson('/api/v1/verify-email', [
            'email' => 'test@test.com',
            'token' => '12345', // Too short
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['token']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itValidatesTokenIsSixDigits()
    {
        $response = $this->postJson('/api/v1/verify-email', [
            'email' => 'test@test.com',
            'token' => 'abcdef',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['token']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itHandlesExpiredTokens()
    {
        // Create an expired token
        $confirmationToken = ConfirmationToken::create([
            'user_uuid' => $this->unverifiedUser->uuid,
            'token' => '123456',
            'expires_at' => Carbon::now()->subHour(), // Expired
        ]);

        $response = $this->postJson('/api/v1/verify-email', [
            'email' => $this->unverifiedUser->email,
            'token' => $confirmationToken->token,
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'success' => false,
                'message' => __('auth.invalid_or_expired_token'),
            ]);
    }
}
