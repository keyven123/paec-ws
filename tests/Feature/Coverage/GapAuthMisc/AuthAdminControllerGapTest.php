<?php

namespace Tests\Feature\Coverage\GapAuthMisc;

use App\Events\OtpWasSent;
use App\Events\PasswordResetExpirationWasRefreshed;
use App\Events\PasswordResetWasCreated;
use App\Http\Controllers\AuthAdminController;
use App\Http\Repositories\AdminUserRepository;
use App\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthAdminControllerGapTest extends TestCase
{
    use GapAuthMiscFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([
            OtpWasSent::class,
            PasswordResetWasCreated::class,
            PasswordResetExpirationWasRefreshed::class,
        ]);
    }

    #[Test]
    public function loginWithIsAdminFlagRejectsNonAdminRole(): void
    {
        $admin = $this->makeAdmin($this->makeRole([], false));

        $this->postJson('/api/v1/admin/login', [
            'email' => $admin->email,
            'password' => 'password123',
            'is_admin' => true,
        ])->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Invalid credentials');
    }

    #[Test]
    public function loginForMigratedAdminInitiatesPasswordReset(): void
    {
        $admin = $this->makeAdmin($this->makeRole(), ['is_migrated' => true]);

        $this->postJson('/api/v1/admin/login', [
            'email' => $admin->email,
            'password' => 'password123',
        ])->assertStatus(201)
            ->assertJsonPath('data.message', fn (string $m) => str_contains($m, 'reset your password'));

        $this->assertDatabaseHas('password_resets', [
            'email' => $admin->email,
            'resettable_id' => $admin->uuid,
            'resettable_type' => AdminUser::class,
        ]);
        Event::assertDispatched(OtpWasSent::class);
    }

    #[Test]
    public function loginRejectsUserWhoseRoleIsNotAdmin(): void
    {
        $customerAdmin = $this->makeAdmin($this->customerRole());
        $customerAdmin->load('role');

        $this->partialMock(AdminUserRepository::class, function ($mock) use ($customerAdmin) {
            $mock->shouldReceive('findByEmail')->once()->andReturn($customerAdmin);
        });

        $this->postJson('/api/v1/admin/login', [
            'email' => $customerAdmin->email,
            'password' => 'password123',
        ])->assertStatus(422)
            ->assertJsonPath('errors.email.0', 'Access denied. Admin privileges required.');
    }

    #[Test]
    public function meReturnsUnauthorizedWithoutAdminUser(): void
    {
        $response = app(AuthAdminController::class)->me();

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(['success' => false, 'message' => 'Unauthorized'], $response->getData(true));
    }

    #[Test]
    public function refreshReturnsInvalidTokenWhenUserNoLongerExists(): void
    {
        $admin = $this->makeAdmin($this->makeRole());
        $token = auth('admin')->login($admin);
        $admin->forceDelete();

        Auth::forgetGuards();
        auth('admin')->setToken($token);

        $response = app(AuthAdminController::class)->refresh();

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Invalid token', $response->getData(true)['message']);
    }

    #[Test]
    public function refreshReturnsFailureWhenNoTokenPresent(): void
    {
        Auth::forgetGuards();

        $response = app(AuthAdminController::class)->refresh();

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Token refresh failed', $response->getData(true)['message']);
    }

    #[Test]
    public function forgotPasswordReturnsNotFoundForUnknownEmail(): void
    {
        $request = Request::create('/forgot', 'POST', ['email' => 'nobody@test.com']);

        $response = app(AuthAdminController::class)->forgotPassword($request);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('Admin user not found', $response->getData(true)['message']);
        $this->assertDatabaseCount('password_resets', 0);
    }

    #[Test]
    public function forgotPasswordInitiatesResetForKnownAdmin(): void
    {
        $admin = $this->makeAdmin($this->makeRole());
        $request = Request::create('/forgot', 'POST', ['email' => $admin->email]);

        $response = app(AuthAdminController::class)->forgotPassword($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([
            'success' => true,
            'message' => 'Password reset link sent to email',
        ], $response->getData(true));
        $this->assertDatabaseHas('password_resets', ['email' => $admin->email]);
    }

    #[Test]
    public function forgotPasswordValidatesEmail(): void
    {
        $this->expectException(ValidationException::class);

        app(AuthAdminController::class)->forgotPassword(Request::create('/forgot', 'POST', ['email' => 'bad']));
    }

    #[Test]
    public function changePasswordReturnsUnauthorizedWithoutAdminUser(): void
    {
        $request = Request::create('/change', 'POST', [
            'current_password' => 'password123',
            'new_password' => 'newpassword123',
            'new_password_confirmation' => 'newpassword123',
        ]);

        $response = app(AuthAdminController::class)->changePassword($request);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Unauthorized', $response->getData(true)['message']);
    }

    #[Test]
    public function updateProfileReturnsUnauthorizedWithoutAdminUser(): void
    {
        $request = Request::create('/profile', 'PUT', ['first_name' => 'New']);

        $response = app(AuthAdminController::class)->updateProfile($request);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Unauthorized', $response->getData(true)['message']);
    }
}
