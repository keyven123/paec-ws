<?php

namespace Tests\Feature\Coverage\Middleware;

use App\Constants\GeneralConstants;
use App\Exceptions\UnauthorizedException;
use App\Http\Middleware\AuthenticateAdmin;
use App\Http\Middleware\CheckAbility;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\CheckRole;
use App\Models\AdminUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

class MiddlewareCoverageTest extends TestCase
{
    use GrantsAdminPermissions;
    use RefreshDatabase;

    #[Test]
    public function authenticateAdminRejectsGuestsAndPassesSignedInAdmins(): void
    {
        $middleware = new AuthenticateAdmin();

        $this->assertUnauthenticated($middleware->handle(new Request(), $this->next()));

        auth('admin')->setUser($this->admin(null));
        $this->assertPassedThrough($middleware->handle(new Request(), $this->next()));
    }

    #[Test]
    public function checkPermissionRejectsGuests(): void
    {
        $this->assertUnauthenticated((new CheckPermission())->handle(new Request(), $this->next(), 'events-view'));
    }

    #[Test]
    public function checkPermissionRejectsUsersWithoutARole(): void
    {
        auth('api')->setUser((new User())->forceFill(['uuid' => (string) Str::uuid(), 'role_uuid' => null]));

        $response = (new CheckPermission())->handle(new Request(), $this->next(), 'events-view');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('User has no role assigned', $response->getData(true)['message']);
    }

    #[Test]
    public function checkPermissionRequiresOneOfTheListedPermissions(): void
    {
        $role = Role::create(['name' => 'Customer', 'code' => 'customer-middleware', 'is_admin' => false]);
        $this->grantRolePermissions($role, ['tickets' => ['view']]);
        auth('api')->setUser(User::factory()->create(['role_uuid' => $role->uuid]));

        $denied = (new CheckPermission())->handle(new Request(), $this->next(), 'events-view', 'events-update');
        $this->assertSame(403, $denied->getStatusCode());
        $this->assertSame(
            'Insufficient permissions. Required permission: events-view, events-update',
            $denied->getData(true)['message'],
        );

        $this->assertPassedThrough((new CheckPermission())->handle(new Request(), $this->next(), 'events-view', 'tickets-view'));
    }

    #[Test]
    public function checkRoleRejectsRequestsWithoutABearerToken(): void
    {
        auth('admin')->setUser($this->admin(null));

        $this->assertUnauthenticated((new CheckRole())->handle(new Request(), $this->next(), 'admin'));
    }

    #[Test]
    public function checkRoleRejectsAdminsWithoutARole(): void
    {
        auth('admin')->setUser($this->admin(null));

        $response = (new CheckRole())->handle($this->bearerRequest('token'), $this->next(), 'admin');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('User has no role assigned', $response->getData(true)['message']);
    }

    #[Test]
    public function checkRoleOnlyAdmitsListedRoleCodes(): void
    {
        $role = Role::create(['name' => 'Organizer', 'code' => 'organizer-middleware', 'is_admin' => true]);
        auth('admin')->setUser($this->admin($role->uuid));

        $denied = (new CheckRole())->handle($this->bearerRequest('token'), $this->next(), 'admin', 'superadmin');
        $this->assertSame(403, $denied->getStatusCode());
        $this->assertSame('Insufficient permissions. Required role: admin, superadmin', $denied->getData(true)['message']);

        $this->assertPassedThrough((new CheckRole())->handle($this->bearerRequest('token'), $this->next(), 'organizer-middleware'));
    }

    #[Test]
    public function checkAbilityAcceptsPermissionsEncodedAsAJsonObject(): void
    {
        $token = $this->jwt([
            'role' => 'organizer',
            'permissions' => (object) ['0' => 'tickets-view', '1' => 'events-view'],
        ]);

        $this->assertPassedThrough((new CheckAbility())->handle($this->bearerRequest($token), $this->next(), 'events-view'));
    }

    #[Test]
    public function checkAbilityLetsSuperadminsThroughWithoutPermissions(): void
    {
        $token = $this->jwt(['role' => GeneralConstants::ROLES['SUPER_ADMIN']['name']]);

        $this->assertPassedThrough((new CheckAbility())->handle($this->bearerRequest($token), $this->next(), 'anything-delete'));
    }

    #[Test]
    public function checkAbilityDeniesMissingPermissionsWith403(): void
    {
        $token = $this->jwt(['role' => 'organizer', 'permissions' => ['tickets-view']]);

        try {
            (new CheckAbility())->handle($this->bearerRequest($token), $this->next(), 'events-delete');
            $this->fail('Expected a permission error.');
        } catch (UnauthorizedException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('Permission denied', $e->getMessage());
        }
    }

    #[Test]
    public function checkAbilityTurnsUnreadableTokensInto401(): void
    {
        try {
            (new CheckAbility())->handle($this->bearerRequest('not-a-jwt'), $this->next(), 'events-view');
            $this->fail('Expected an authentication error.');
        } catch (UnauthorizedException $e) {
            $this->assertSame(401, $e->getStatusCode());
            $this->assertSame('Unauthenticated', $e->getMessage());
        }
    }

    private function admin(?string $roleUuid): AdminUser
    {
        return (new AdminUser())->forceFill(['uuid' => (string) Str::uuid(), 'role_uuid' => $roleUuid]);
    }

    private function bearerRequest(string $token): Request
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer ' . $token);

        return $request;
    }

    private function jwt(array $claims): string
    {
        return 'header.' . base64_encode(json_encode($claims + ['exp' => time() + 600])) . '.signature';
    }

    private function next(): \Closure
    {
        return fn () => response()->json(['passed' => true]);
    }

    private function assertPassedThrough(Response $response): void
    {
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['passed' => true], $response->getData(true));
    }

    private function assertUnauthenticated(Response $response): void
    {
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(['success' => false, 'message' => 'Unauthenticated'], $response->getData(true));
    }
}
