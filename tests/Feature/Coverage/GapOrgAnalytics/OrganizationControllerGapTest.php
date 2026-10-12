<?php

namespace Tests\Feature\Coverage\GapOrgAnalytics;

use App\Constants\GeneralConstants;
use App\Exceptions\NoOrganizationFoundException;
use App\Exceptions\UnauthorizedException;
use App\Http\Controllers\OrganizationController;
use App\Http\Repositories\OrganizationRepository;
use App\Http\Requests\Organization\CreateOrganizationRequest;
use App\Http\Requests\Organization\RegisterOrganizationRequest;
use App\Models\AdminUser;
use App\Models\Organization;
use App\Models\OrganizationBank;
use App\Models\OrganizationPlatformCom;
use App\Models\Role;
use App\Notifications\SendInvitesToOrganizer;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

class OrganizationControllerGapTest extends TestCase
{
    use GrantsAdminPermissions;
    use RefreshDatabase;

    private AdminUser $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create([
            'name' => 'Org Gap Admin',
            'code' => 'org-gap-admin',
            'is_admin' => true,
        ]);
        $this->grantRolePermissions($role, [
            'organizations' => ['view', 'create', 'update', 'delete'],
            'commissions' => ['update'],
            'payment-methods' => ['update'],
        ]);

        $this->admin = AdminUser::create([
            'role_uuid' => $role->uuid,
            'email' => 'org-gap-admin@test.com',
            'password' => 'password123',
            'first_name' => 'Org',
            'last_name' => 'Gap',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);
        $this->token = auth('admin')->login($this->admin);
    }

    private function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    private function makeOrganization(array $attributes = []): Organization
    {
        return Organization::create(array_merge([
            'name' => 'Gap Org '.Str::random(5),
            'representative_first_name' => 'Rep',
            'representative_last_name' => 'Resentative',
            'email' => 'gap-org-'.Str::lower(Str::random(6)).'@test.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['PENDING'],
            'commission_percentage' => 10,
        ], $attributes));
    }

    private function organizerRole(): Role
    {
        return Role::firstOrCreate(
            ['code' => GeneralConstants::ROLES['ORGANIZER']['name']],
            ['name' => 'Organizer', 'is_admin' => false],
        );
    }

    private function mockRepositoryFetchThrowing(\Throwable $exception): void
    {
        $this->mock(OrganizationRepository::class, function ($mock) use ($exception) {
            $mock->shouldReceive('fetchOrThrow')->once()->andThrow($exception);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Legacy Org',
            'business_type' => Organization::BUSINESS_TYPE_CORPORATION,
            'representative_first_name' => 'Lea',
            'representative_last_name' => 'Gacy',
            'address' => '1 Legacy St',
            'contact_number' => '09171234567',
            'email' => 'legacy-org@test.com',
            'description' => 'Old style registration',
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
        ], $overrides);
    }

    #[Test]
    public function publicOrganizationsFiltersByTrimmedSearchTerm(): void
    {
        $match = $this->makeOrganization([
            'name' => 'Sunrise Concerts',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ]);
        $this->makeOrganization([
            'name' => 'Moonlight Theatre',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
        ]);
        $this->makeOrganization([
            'name' => 'Sunrise Pending',
            'status' => GeneralConstants::ORGANIZER_STATUSES['PENDING'],
        ]);

        $response = $this->getJson('/api/v1/public/organizations?q='.urlencode('  Sunrise  '))->assertOk();

        $this->assertSame([$match->uuid], array_column($response->json('data'), 'uuid'));
    }

    #[Test]
    public function statsCountsOrganizationsByStatus(): void
    {
        $this->makeOrganization(['status' => GeneralConstants::ORGANIZER_STATUSES['PENDING']]);
        $this->makeOrganization(['status' => GeneralConstants::ORGANIZER_STATUSES['PENDING']]);
        $this->makeOrganization(['status' => GeneralConstants::ORGANIZER_STATUSES['ONBOARDED']]);
        $this->makeOrganization(['status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED']]);
        $this->makeOrganization(['status' => GeneralConstants::ORGANIZER_STATUSES['REJECTED']]);

        $this->getJson('/api/v1/organizations/stats', $this->headers())
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'data' => ['pending' => 2, 'onboarded' => 1, 'approved' => 1, 'total' => 5],
            ]);
    }

    #[Test]
    public function storeOverHttpFailsBecauseRequestNeverCollectsRepresentativeFirstAndLastName(): void
    {
        $response = $this->postJson('/api/v1/organizations', [
            'name' => 'Http Org',
            'representative_name' => 'Rep Name',
            'address' => '1 Street',
            'contact_number' => '09171234567',
            'email' => 'http-org@test.com',
            'bank_name' => 'BDO',
            'bank_branch' => 'Makati',
            'bank_address' => 'Ayala Ave',
            'bank_account_name' => 'Http Org',
            'bank_account_number' => '0001',
        ], $this->headers());

        $response->assertStatus(500);
        $this->assertInstanceOf(QueryException::class, $response->exception);
        $this->assertStringContainsString('representative_first_name', $response->exception->getMessage());
        $this->assertDatabaseMissing('organizations', ['email' => 'http-org@test.com']);
    }

    #[Test]
    public function storeCreatesOrganizationWithDefaultBankAndReturns201(): void
    {
        $payload = [
            'name' => 'Direct Org',
            'representative_first_name' => 'Dee',
            'representative_last_name' => 'Rect',
            'address' => '1 Street',
            'contact_number' => '09171234567',
            'email' => 'direct-org@test.com',
            'account_type' => 'savings',
            'bank_name' => 'BDO',
            'bank_branch' => 'Makati',
            'bank_address' => 'Ayala Ave',
            'bank_account_name' => 'Direct Org',
            'bank_account_number' => '0001',
        ];
        $request = CreateOrganizationRequest::create('/api/v1/organizations', 'POST', $payload);
        $request->setValidator(Validator::make($payload, array_fill_keys(array_keys($payload), ['required', 'string'])));

        $response = app(OrganizationController::class)->store($request);

        $this->assertSame(201, $response->getStatusCode());
        $data = $response->getData(true)['data'];
        $this->assertSame('Direct Org', $data['name']);
        $this->assertSame('+639171234567', $data['contact_number']);
        $this->assertSame('BDO', $data['bank_name']);
        $this->assertDatabaseHas('organizations', ['email' => 'direct-org@test.com', 'contact_number' => '+639171234567']);
        $this->assertDatabaseHas('organization_banks', [
            'organization_uuid' => $data['uuid'],
            'bank_account_number' => '0001',
            'is_default' => true,
        ]);
    }

    #[Test]
    public function showReturns404WhenRepositoryReportsMissingOrganization(): void
    {
        $this->mockRepositoryFetchThrowing(new NoOrganizationFoundException());

        $this->getJson('/api/v1/organizations/'.Str::uuid(), $this->headers())
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Organization not found']);
    }

    #[Test]
    public function updateReturns404WhenRepositoryReportsMissingOrganization(): void
    {
        $organization = $this->makeOrganization();
        $this->mockRepositoryFetchThrowing(new NoOrganizationFoundException());

        $this->putJson('/api/v1/organizations/'.$organization->uuid, ['name' => 'Renamed'], $this->headers())
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Organization not found']);
        $this->assertDatabaseHas('organizations', ['uuid' => $organization->uuid, 'name' => $organization->name]);
    }

    #[Test]
    public function updateCommissionPercentageLogsChangeFromNullCommission(): void
    {
        $organization = $this->makeOrganization(['commission_percentage' => null]);

        $this->putJson(
            '/api/v1/organizations/'.$organization->uuid.'/commission-percentage',
            ['commission_percentage' => 12.5],
            $this->headers(),
        )->assertOk()->assertJsonPath('data.uuid', $organization->uuid);

        $this->assertEquals(12.5, (float) $organization->fresh()->commission_percentage);
        $this->assertDatabaseHas(OrganizationPlatformCom::class, [
            'organization_uuid' => $organization->uuid,
            'previous_coms' => null,
            'created_by' => $this->admin->uuid,
        ]);
    }

    #[Test]
    public function updateCommissionPercentageReturns404WhenRepositoryReportsMissingOrganization(): void
    {
        $this->mockRepositoryFetchThrowing(new NoOrganizationFoundException());

        $this->putJson(
            '/api/v1/organizations/'.Str::uuid().'/commission-percentage',
            ['commission_percentage' => 5],
            $this->headers(),
        )->assertNotFound()->assertExactJson(['success' => false, 'message' => 'Organization not found']);
        $this->assertDatabaseCount(OrganizationPlatformCom::class, 0);
    }

    #[Test]
    public function updatePaymentMethodsReturns404WhenRepositoryReportsMissingOrganization(): void
    {
        $organization = $this->makeOrganization();
        $this->mockRepositoryFetchThrowing(new NoOrganizationFoundException());

        $this->putJson(
            '/api/v1/organizations/'.$organization->uuid.'/payment-methods',
            ['payment_methods' => [['name' => 'card', 'value' => true, 'provider' => 'paymongo']]],
            $this->headers(),
        )->assertNotFound()->assertExactJson(['success' => false, 'message' => 'Organization not found']);
    }

    #[Test]
    public function destroySoftDeletesTheOrganization(): void
    {
        $organization = $this->makeOrganization();

        $this->deleteJson('/api/v1/organizations/'.$organization->uuid, [], $this->headers())->assertNoContent();

        $this->assertSoftDeleted('organizations', ['uuid' => $organization->uuid]);
    }

    #[Test]
    public function destroyReturns404WhenRepositoryReportsMissingOrganization(): void
    {
        $this->mockRepositoryFetchThrowing(new NoOrganizationFoundException());

        $this->deleteJson('/api/v1/organizations/'.Str::uuid(), [], $this->headers())
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Organization not found']);
    }

    #[Test]
    public function destroyReturns403WhenDeletionIsNotAllowed(): void
    {
        $organization = $this->makeOrganization();
        $this->mock(OrganizationRepository::class, function ($mock) use ($organization) {
            $mock->shouldReceive('fetchOrThrow')->once()->with('uuid', $organization->uuid)->andReturn($organization);
            $mock->shouldReceive('delete')->once()->andThrow(new UnauthorizedException('Organization has sold tickets', 403));
        });

        $this->deleteJson('/api/v1/organizations/'.$organization->uuid, [], $this->headers())
            ->assertForbidden()
            ->assertExactJson(['success' => false, 'message' => 'Organization has sold tickets']);
        $this->assertNotSoftDeleted('organizations', ['uuid' => $organization->uuid]);
    }

    #[Test]
    public function registerSkipsCommissionLogWhenCreatedOrganizationHasNoCommission(): void
    {
        $this->organizerRole();
        $repository = Mockery::mock(OrganizationRepository::class, [
            app(Organization::class),
            app(AdminUser::class),
            app(Role::class),
        ])->makePartial();
        $repository->shouldReceive('create')->once()->andReturnUsing(fn (array $payload) => Organization::create([
            'name' => $payload['name'],
            'email' => $payload['email'],
            'representative_first_name' => $payload['representative_first_name'],
            'representative_last_name' => $payload['representative_last_name'],
            'status' => GeneralConstants::ORGANIZER_STATUSES['PENDING'],
            'commission_percentage' => null,
        ]));
        $this->app->instance(OrganizationRepository::class, $repository);

        $response = $this->postJson('/api/v1/organizations/register', $this->registrationPayload([
            'email' => 'no-coms@test.com',
            'name' => 'No Coms Org',
        ]))->assertOk();

        $this->assertSame('no-coms@test.com', $response->json('admin_user.email'));
        $this->assertFalse($response->json('is_admin'));
        $this->assertDatabaseHas('organizations', [
            'email' => 'no-coms@test.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
            'commission_percentage' => null,
        ]);
        $this->assertDatabaseCount(OrganizationPlatformCom::class, 0);
    }

    #[Test]
    public function oldRegisterCreatesPendingOrganizationWithDefaults(): void
    {
        $request = RegisterOrganizationRequest::create('/api/v1/organizations/register', 'POST', $this->registrationPayload());
        $request->setContainer($this->app)->setRedirector($this->app['redirect']);
        $request->validateResolved();

        $response = app(OrganizationController::class)->oldRegister($request);

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame([
            'success' => true,
            'message' => 'We will review your organization and get back to you soon.',
        ], $response->getData(true));

        $organization = Organization::where('email', 'legacy-org@test.com')->firstOrFail();
        $this->assertSame('+639171234567', $organization->contact_number);
        $this->assertEquals(0, (float) $organization->commission_percentage);
        $this->assertNotEmpty($organization->payment_methods);
        $this->assertDatabaseCount('admin_users', 1);
    }

    #[Test]
    public function oldCreateKeepsProvidedCommissionAndPaymentMethods(): void
    {
        $methods = [['name' => 'card', 'value' => true, 'provider' => 'paymongo']];

        $organization = app(OrganizationRepository::class)->oldCreate([
            'name' => 'Explicit Org',
            'representative_first_name' => 'Ex',
            'representative_last_name' => 'Plicit',
            'email' => 'explicit@test.com',
            'contact_number' => '123',
            'commission_percentage' => 7.5,
            'payment_methods' => $methods,
            'unknown_field' => 'dropped',
        ]);

        $this->assertSame('123', $organization->contact_number);
        $this->assertEquals(7.5, (float) $organization->fresh()->commission_percentage);
        $this->assertSame($methods, $organization->fresh()->payment_methods);
    }

    #[Test]
    public function approveMarksOrganizationApprovedByTheAdmin(): void
    {
        Carbon::setTestNow('2026-05-20 10:00:00');
        $organization = $this->makeOrganization();

        $this->patchJson('/api/v1/organizations/'.$organization->uuid.'/approve', [], $this->headers())
            ->assertOk()
            ->assertExactJson(['success' => true, 'message' => 'Organization approved successfully']);

        $fresh = $organization->fresh();
        $this->assertSame(GeneralConstants::ORGANIZER_STATUSES['APPROVED'], $fresh->status);
        $this->assertSame($this->admin->uuid, $fresh->approved_by);
        $this->assertNotNull($fresh->approved_at);
        Carbon::setTestNow();
    }

    #[Test]
    public function rejectMarksOrganizationRejected(): void
    {
        $organization = $this->makeOrganization();

        $this->patchJson('/api/v1/organizations/'.$organization->uuid.'/reject', [], $this->headers())
            ->assertOk()
            ->assertExactJson(['success' => true, 'message' => 'Organization rejected successfully']);

        $this->assertDatabaseHas('organizations', [
            'uuid' => $organization->uuid,
            'status' => GeneralConstants::ORGANIZER_STATUSES['REJECTED'],
        ]);
    }

    #[Test]
    public function onboardMarksOrganizationOnboarded(): void
    {
        $organization = $this->makeOrganization();

        $this->patchJson('/api/v1/organizations/'.$organization->uuid.'/onboard', [], $this->headers())
            ->assertOk()
            ->assertExactJson(['success' => true, 'message' => 'Organization onboarded successfully']);

        $this->assertDatabaseHas('organizations', [
            'uuid' => $organization->uuid,
            'status' => GeneralConstants::ORGANIZER_STATUSES['ONBOARDED'],
        ]);
    }

    #[Test]
    public function sendInvitationStoresHashedSecretAndNotifiesOrganization(): void
    {
        Notification::fake();
        $organization = $this->makeOrganization(['send_invite_count' => 2]);

        $this->patchJson('/api/v1/organizations/'.$organization->uuid.'/send-invite', [], $this->headers())
            ->assertOk()
            ->assertExactJson(['success' => true, 'message' => 'Invitation sent successfully']);

        $fresh = $organization->fresh();
        $this->assertSame(3, (int) $fresh->send_invite_count);
        $this->assertNotNull($fresh->secret_expired_at);
        $this->assertTrue(Carbon::parse($fresh->secret_expired_at)->isFuture());

        Notification::assertSentTo($fresh, SendInvitesToOrganizer::class, function (SendInvitesToOrganizer $notification) use ($fresh) {
            $this->assertMatchesRegularExpression('/^\d{6}$/', $notification->secret);

            return Hash::check($notification->secret, $fresh->secret);
        });
    }

    #[Test]
    public function onboardingRejectsExpiredInvitation(): void
    {
        $organization = $this->makeOrganization([
            'secret' => Hash::make('123456'),
            'secret_expired_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/v1/organizer/onboarding', ['email' => $organization->email, 'secret' => '123456'])
            ->assertStatus(400)
            ->assertExactJson([
                'success' => false,
                'message' => 'Expired invitation please request for a new invitation to the admin.',
            ]);
    }

    #[Test]
    public function onboardingRejectsInvalidSecret(): void
    {
        $organization = $this->makeOrganization([
            'secret' => Hash::make('123456'),
            'secret_expired_at' => now()->addDay(),
        ]);

        $this->postJson('/api/v1/organizer/onboarding', ['email' => $organization->email, 'secret' => '654321'])
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'message' => 'Invalid secret']);
    }

    #[Test]
    public function onboardingRotatesSecretAndReturnsOrganization(): void
    {
        $organization = $this->makeOrganization([
            'secret' => Hash::make('123456'),
            'secret_expired_at' => now()->addDay(),
        ]);

        $response = $this->getJson('/api/v1/organizations/onboarding?'.http_build_query([
            'email' => $organization->email,
            'secret' => '123456',
        ]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Onboarding successful')
            ->assertJsonPath('data.uuid', $organization->uuid);

        $newSecret = $response->json('secret');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $newSecret);
        $fresh = $organization->fresh();
        $this->assertTrue(Hash::check($newSecret, $fresh->secret));
        $this->assertTrue(Carbon::parse($fresh->secret_expired_at)->lessThanOrEqualTo(now()->addHour()->addSecond()));
    }

    /**
     * @return array<string, mixed>
     */
    private function onboardingRegisterPayload(string $email, string $secret): array
    {
        return [
            'secret' => $secret,
            'first_name' => 'Onboard',
            'last_name' => 'User',
            'email' => $email,
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
            'accepted_terms' => 1,
            'phone_number' => '09171234567',
        ];
    }

    #[Test]
    public function onboardingRegisterRejectsExpiredInvitation(): void
    {
        $organization = $this->makeOrganization([
            'secret' => Hash::make('123456'),
            'secret_expired_at' => now()->subMinute(),
        ]);

        $this->postJson('/api/v1/organizer/onboarding/register', $this->onboardingRegisterPayload($organization->email, '123456'))
            ->assertStatus(400)
            ->assertJsonPath('message', 'Expired invitation please request for a new invitation to the admin.');
        $this->assertDatabaseMissing('admin_users', ['email' => $organization->email]);
    }

    #[Test]
    public function onboardingRegisterRejectsInvalidSecret(): void
    {
        $organization = $this->makeOrganization([
            'secret' => Hash::make('123456'),
            'secret_expired_at' => now()->addDay(),
        ]);

        $this->postJson('/api/v1/organizer/onboarding/register', $this->onboardingRegisterPayload($organization->email, '000000'))
            ->assertStatus(400)
            ->assertExactJson(['success' => false, 'message' => 'Invalid secret']);
        $this->assertDatabaseMissing('admin_users', ['email' => $organization->email]);
    }

    #[Test]
    public function onboardingRegisterCreatesOrganizerAdminAndMarksOrganizationOnboarded(): void
    {
        $role = $this->organizerRole();
        $organization = $this->makeOrganization([
            'secret' => Hash::make('123456'),
            'secret_expired_at' => now()->addDay(),
        ]);

        $this->postJson('/api/v1/organizer/onboarding/register', $this->onboardingRegisterPayload($organization->email, '123456'))
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Onboarding registration successful, you can now login to your account',
            ]);

        $this->assertDatabaseHas('admin_users', [
            'email' => $organization->email,
            'organization_uuid' => $organization->uuid,
            'role_uuid' => $role->uuid,
            'phone_number' => '+639171234567',
        ]);
        $this->assertDatabaseHas('organizations', [
            'uuid' => $organization->uuid,
            'status' => GeneralConstants::ORGANIZER_STATUSES['ONBOARDED'],
            'secret' => null,
            'secret_expired_at' => null,
        ]);
    }

    #[Test]
    public function repositoryUpdateDropsUnknownBundleTicketsKey(): void
    {
        $organization = $this->makeOrganization();

        $result = app(OrganizationRepository::class)->update($organization, [
            'name' => 'Renamed Org',
            'bundle_tickets' => [$organization->uuid, (string) Str::uuid()],
        ]);

        $this->assertSame($organization, $result);
        $this->assertDatabaseHas('organizations', ['uuid' => $organization->uuid, 'name' => 'Renamed Org']);
        $this->assertSame(0, OrganizationBank::where('organization_uuid', $organization->uuid)->count());
    }
}
