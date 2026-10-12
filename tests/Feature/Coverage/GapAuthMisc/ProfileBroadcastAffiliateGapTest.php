<?php

namespace Tests\Feature\Coverage\GapAuthMisc;

use App\Constants\GeneralConstants;
use App\Http\Controllers\Customer\ProfileController;
use App\Http\Controllers\UploadController;
use App\Http\Repositories\UploadRepository;
use App\Http\Resources\UploadResource;
use App\Support\UploadDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Request as RequestFacade;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProfileBroadcastAffiliateGapTest extends TestCase
{
    use GapAuthMiscFixtures;
    use RefreshDatabase;

    #[Test]
    public function customerUpdatesOwnProfile(): void
    {
        $customer = $this->makeCustomer();

        $this->withHeaders($this->customerHeaders($customer))
            ->putJson('/api/v1/profile', [
                'first_name' => 'Updated',
                'last_name' => 'Person',
                'city' => 'Makati',
            ])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Updated')
            ->assertJsonPath('data.last_name', 'Person');

        $this->assertDatabaseHas('users', [
            'uuid' => $customer->uuid,
            'first_name' => 'Updated',
            'last_name' => 'Person',
            'city' => 'Makati',
        ]);
    }

    #[Test]
    public function updateProfileImageCannotForwardFacadeRequestToUploadController(): void
    {
        Storage::fake('public');
        $inner = HttpRequest::create('/profile-image', 'POST', [], [], [
            'file' => UploadedFile::fake()->image('avatar.png', 10, 10),
        ]);
        $proxy = new class($inner) extends RequestFacade
        {
            public function __construct(private HttpRequest $inner)
            {
            }

            public function __call(string $method, array $arguments): mixed
            {
                return $this->inner->{$method}(...$arguments);
            }
        };

        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('UploadRequest');

        app(ProfileController::class)->updateProfileImage($proxy);
    }

    #[Test]
    public function updateProfileImageAttachesUploadReturnedByUploadController(): void
    {
        Storage::fake(UploadDisk::forUploads());
        $customer = $this->makeCustomer();
        $inner = HttpRequest::create('/profile-image', 'POST', [], [], [
            'file' => UploadedFile::fake()->image('avatar.png', 12, 8),
        ]);
        $inner->setUserResolver(fn () => $customer);
        $proxy = new class($inner) extends RequestFacade
        {
            public function __construct(private HttpRequest $inner)
            {
            }

            public function __call(string $method, array $arguments): mixed
            {
                return $this->inner->{$method}(...$arguments);
            }
        };
        $this->app->instance(UploadController::class, new class(app(UploadRepository::class)) extends UploadController
        {
            public function store($request)
            {
                $upload = $this->uploadRepository->create(['file' => $request->file('file')]);

                return response()->json(['upload' => (new UploadResource($upload))->resolve()]);
            }
        });

        $response = app(ProfileController::class)->updateProfileImage($proxy);

        $data = $response->getData(true);
        $this->assertSame('Profile image updated.', $data['message']);
        $this->assertNotNull($data['url']);
        $this->assertDatabaseHas('uploads', ['uuid' => $data['profile_image_uuid'], 'type' => 'image', 'width' => 12]);
        $this->assertSame($data['profile_image_uuid'], $customer->fresh()->profile_image_uuid);
    }

    #[Test]
    public function organizerReadsAndUpdatesOwnProfile(): void
    {
        $organization = $this->makeOrganization();
        $organizer = $this->makeAdmin(
            $this->makeRole(['profile' => ['view', 'update']], false),
            ['organization_uuid' => $organization->uuid],
        );
        $headers = $this->adminHeaders($organizer);

        $this->withHeaders($headers)
            ->getJson('/api/v1/organizer/profile')
            ->assertOk()
            ->assertJsonPath('data.uuid', $organizer->uuid)
            ->assertJsonPath('data.email', $organizer->email);

        $this->withHeaders($headers)
            ->putJson('/api/v1/organizer/profile', [
                'first_name' => 'Olive',
                'last_name' => 'Organizer',
                'phone_number' => '09170000000',
            ])
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Olive');

        $this->assertDatabaseHas('admin_users', [
            'uuid' => $organizer->uuid,
            'first_name' => 'Olive',
            'last_name' => 'Organizer',
            'phone_number' => '09170000000',
        ]);
    }

    #[Test]
    public function organizerSetsExplicitCommissionPercentage(): void
    {
        $organization = $this->makeOrganization();
        $organizer = $this->makeAdmin(
            $this->makeRole(['profile' => ['view', 'update']], false),
            ['organization_uuid' => $organization->uuid],
        );

        $this->withHeaders($this->adminHeaders($organizer))
            ->putJson('/api/v1/organizer/profile/organization', [
                'business_type' => $organization->business_type,
                'address' => 'New Address',
                'contact_number' => '09171234567',
                'email' => $organization->email,
                'description' => 'Updated',
                'commission_percentage' => 12.5,
            ])
            ->assertOk()
            ->assertJsonPath('data.address', 'New Address');

        $this->assertEquals(12.5, (float) $organization->fresh()->commission_percentage);
    }

    #[Test]
    public function customerBroadcastAuthDelegatesToBroadcaster(): void
    {
        $customer = $this->makeCustomer();
        $headers = $this->customerHeaders($customer);

        Broadcast::shouldReceive('auth')
            ->once()
            ->with(Mockery::on(fn ($request) => $request->user()?->uuid === $customer->uuid))
            ->andReturn(['auth' => 'signed-payload']);

        $this->withHeaders($headers)
            ->postJson('/api/v1/broadcasting/auth', [
                'channel_name' => "private-user.{$customer->uuid}",
                'socket_id' => '123.456',
            ])
            ->assertOk()
            ->assertExactJson(['auth' => 'signed-payload']);
    }

    #[Test]
    public function suspendedAffiliateSeesSuspensionDetails(): void
    {
        $customer = $this->makeCustomer();
        $customer->userAffiliate()->create([
            'affiliate_status' => GeneralConstants::AFFILIATE_STATUSES['SUSPENDED'],
            'affiliate_code' => 'GAPSUS1',
            'affiliate_suspend_reason' => 'Policy violation',
            'affiliate_suspended_at' => now(),
        ]);

        $this->withHeaders($this->customerHeaders($customer))
            ->getJson('/api/v1/customer/affiliate')
            ->assertOk()
            ->assertJsonPath('data.status', 'suspended')
            ->assertJsonPath('data.referral_link', null)
            ->assertJsonPath('data.affiliate_suspend_reason', 'Policy violation')
            ->assertJsonPath('data.affiliate_suspended_at', fn ($value) => $value !== null);
    }

    #[Test]
    public function suspendedAffiliateCannotReapply(): void
    {
        $customer = $this->makeCustomer();
        $customer->userAffiliate()->create([
            'affiliate_status' => GeneralConstants::AFFILIATE_STATUSES['SUSPENDED'],
            'affiliate_code' => 'GAPSUS2',
        ]);

        $this->withHeaders($this->customerHeaders($customer))
            ->postJson('/api/v1/customer/affiliate/apply')
            ->assertStatus(422)
            ->assertExactJson([
                'message' => 'Your affiliate account is suspended. Contact support if you have questions.',
            ]);

        $this->assertDatabaseHas('user_affiliates', [
            'user_uuid' => $customer->uuid,
            'affiliate_status' => 'suspended',
        ]);
    }
}
