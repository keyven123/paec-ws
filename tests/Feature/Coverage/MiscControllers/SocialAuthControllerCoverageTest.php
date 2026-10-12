<?php

namespace Tests\Feature\Coverage\MiscControllers;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SocialAuthControllerCoverageTest extends TestCase
{
    use MiscFixtures;
    use RefreshDatabase;

    private const FRONTEND = 'https://frontend.test';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.frontend_url' => self::FRONTEND]);
    }

    private function mockProvider(string $driver): MockInterface
    {
        $provider = Mockery::mock();
        $provider->shouldReceive('stateless')->andReturnSelf();
        Socialite::shouldReceive('driver')->with($driver)->andReturn($provider);

        return $provider;
    }

    private function socialUser(array $attributes): SocialiteUser
    {
        return (new SocialiteUser())->map(array_merge([
            'id' => 'social-123',
            'name' => 'jane doe',
            'nickname' => null,
            'email' => 'jane@social.test',
            'avatar' => 'https://avatars.test/jane.png',
        ], $attributes));
    }

    private function callbackQuery(string $location): array
    {
        $this->assertStringStartsWith(self::FRONTEND . '/auth/callback?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return $query;
    }

    public static function providers(): array
    {
        return [
            'google' => ['google'],
            'facebook' => ['facebook'],
        ];
    }

    #[Test]
    #[DataProvider('providers')]
    public function redirectPassesSafeReturnUrlAsState(string $driver): void
    {
        $provider = $this->mockProvider($driver);
        $provider->shouldReceive('with')->once()->with(['state' => '/event/summer-fest'])->andReturnSelf();
        $provider->shouldReceive('redirect')->once()->andReturn(new RedirectResponse("https://{$driver}.test/oauth"));

        $this->get("/api/v1/auth/{$driver}/redirect?return_url=" . urlencode('/event/summer-fest'))
            ->assertRedirect("https://{$driver}.test/oauth");
    }

    #[Test]
    #[DataProvider('providers')]
    public function redirectIgnoresProtocolRelativeReturnUrl(string $driver): void
    {
        $provider = $this->mockProvider($driver);
        $provider->shouldNotReceive('with');
        $provider->shouldReceive('redirect')->once()->andReturn(new RedirectResponse("https://{$driver}.test/oauth"));

        $this->get("/api/v1/auth/{$driver}/redirect?return_url=" . urlencode('//evil.test/phish'))
            ->assertRedirect("https://{$driver}.test/oauth");
    }

    #[Test]
    #[DataProvider('providers')]
    public function callbackCreatesCustomerAndRedirectsWithToken(string $driver): void
    {
        $role = $this->customerRole();
        $this->mockProvider($driver)->shouldReceive('user')->once()->andReturn($this->socialUser([]));

        $location = $this->get("/api/v1/auth/{$driver}/callback?state=" . urlencode('/event/summer-fest'))
            ->assertRedirect()
            ->headers->get('Location');

        $query = $this->callbackQuery($location);
        $user = User::where('email', 'jane@social.test')->firstOrFail();

        $this->assertSame($role->uuid, $user->role_uuid);
        $this->assertSame('Jane', $user->first_name);
        $this->assertSame('Doe', $user->last_name);
        $this->assertSame($driver, $user->provider);
        $this->assertSame('social-123', $user->provider_id);
        $this->assertSame('https://avatars.test/jane.png', $user->avatar);
        $this->assertNotNull($user->email_verified_at);

        $this->assertNotEmpty($query['access_token']);
        $this->assertSame('customer', $query['role']);
        $this->assertSame('/event/summer-fest', $query['redirect']);
        $decoded = json_decode(base64_decode($query['user']), true);
        $this->assertSame($user->uuid, $decoded['uuid']);
        $this->assertSame('jane@social.test', $decoded['email']);
    }

    #[Test]
    public function callbackUpdatesExistingUserAndFallsBackToNickname(): void
    {
        $existing = $this->createCustomer([
            'email' => 'jane@social.test',
            'first_name' => 'Old',
            'last_name' => 'Name',
            'email_verified_at' => now()->subYear(),
        ]);
        $verifiedAt = $existing->email_verified_at->toDateTimeString();

        $this->mockProvider('google')->shouldReceive('user')->once()->andReturn(
            $this->socialUser(['name' => null, 'nickname' => 'janey'])
        );

        $location = $this->get('/api/v1/auth/google/callback?state=' . urlencode('//evil.test'))
            ->assertRedirect()
            ->headers->get('Location');

        $query = $this->callbackQuery($location);
        $this->assertArrayNotHasKey('redirect', $query);

        $existing->refresh();
        $this->assertSame(1, User::where('email', 'jane@social.test')->count());
        $this->assertSame('Janey', $existing->first_name);
        $this->assertSame('', (string) $existing->last_name);
        $this->assertSame($verifiedAt, $existing->email_verified_at->toDateTimeString());
    }

    #[Test]
    public function callbackUsesDefaultNameWhenProviderReturnsNone(): void
    {
        $this->customerRole();
        $this->mockProvider('facebook')->shouldReceive('user')->once()->andReturn(
            $this->socialUser(['name' => null, 'nickname' => null])
        );

        $this->get('/api/v1/auth/facebook/callback')->assertRedirect();

        $user = User::where('email', 'jane@social.test')->firstOrFail();
        $this->assertSame('User', $user->first_name);
        $this->assertSame('Account', $user->last_name);
    }

    #[Test]
    public function callbackRedirectsWithErrorWhenProviderReturnsNoEmail(): void
    {
        $this->customerRole();
        $this->mockProvider('google')->shouldReceive('user')->once()->andReturn($this->socialUser(['email' => null]));

        $location = $this->get('/api/v1/auth/google/callback')->assertRedirect()->headers->get('Location');

        $this->assertSame(
            'No email returned by provider. Please ensure email permission is enabled.',
            $this->callbackQuery($location)['error'],
        );
        $this->assertSame(0, User::count());
    }

    #[Test]
    public function safeRedirectGuardRejectsEmptyPaths(): void
    {
        $guard = new \ReflectionMethod(\App\Http\Controllers\SocialAuthController::class, 'isSafeRedirectPath');
        $controller = new \App\Http\Controllers\SocialAuthController();

        $this->assertFalse($guard->invoke($controller, null));
        $this->assertFalse($guard->invoke($controller, ''));
        $this->assertTrue($guard->invoke($controller, '/events'));
    }

    #[Test]
    public function callbackRedirectsWithErrorWhenCustomerRoleIsMissing(): void
    {
        $this->mockProvider('google')->shouldReceive('user')->once()->andReturn($this->socialUser([]));

        $location = $this->get('/api/v1/auth/google/callback')->assertRedirect()->headers->get('Location');

        $this->assertSame('Customer role not found. Please contact support.', $this->callbackQuery($location)['error']);
        $this->assertSame(0, User::count());
    }
}
