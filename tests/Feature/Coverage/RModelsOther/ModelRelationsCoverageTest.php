<?php

namespace Tests\Feature\Coverage\RModelsOther;

use App\Models\ActivityCompliance;
use App\Models\ActivityComplianceHistory;
use App\Models\AdminUser;
use App\Models\AffiliateLinkClick;
use App\Models\Category;
use App\Models\ChatThread;
use App\Models\ConfirmationToken;
use App\Models\EventSection;
use App\Models\MerchantPayoutRequest;
use App\Models\Organization;
use App\Models\OrganizationBank;
use App\Models\PlatformNotification;
use App\Models\Role;
use App\Models\Upload;
use App\Models\User;
use App\Models\UserAffiliate;
use App\Models\Venue;
use App\Models\VenueInquiry;
use App\Models\VenueListing;
use App\Models\VenueSeat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ModelRelationsCoverageTest extends TestCase
{
    use RefreshDatabase;

    private function makeAdmin(string $email = 'admin@paec.test', ?string $organizationUuid = null): AdminUser
    {
        $role = Role::firstOrCreate(['code' => 'admin'], ['name' => 'Admin', 'is_admin' => true]);

        return AdminUser::create([
            'role_uuid' => $role->uuid,
            'organization_uuid' => $organizationUuid,
            'email' => $email,
            'password' => 'secret-password',
            'first_name' => 'Admin',
        ]);
    }

    private function makeUser(): User
    {
        $role = Role::firstOrCreate(['code' => 'customer'], ['name' => 'Customer', 'is_admin' => false]);

        return User::factory()->create(['role_uuid' => $role->uuid]);
    }

    private function makeUpload(array $overrides = []): Upload
    {
        return Upload::create(array_merge([
            'type' => 'image',
            'disk' => 'public',
            'path' => 'uploads/file.jpg',
            'size_bytes' => 100,
        ], $overrides));
    }

    private function makeCompliance(Organization $organization): ActivityCompliance
    {
        return ActivityCompliance::create([
            'activityable_type' => Organization::class,
            'activityable_id' => $organization->uuid,
            'label' => 'City Tax',
            'percentage' => 5,
            'amount_type' => ActivityCompliance::AMOUNT_TYPE['PERCENTAGE'],
            'status' => 'active',
        ]);
    }

    #[Test]
    public function activityComplianceRelationsResolveOwnerHistoryAndAdmin(): void
    {
        $admin = $this->makeAdmin();
        $this->actingAs($admin, 'admin');
        $organization = Organization::factory()->create();
        $compliance = $this->makeCompliance($organization);

        $compliance->update(['label' => 'Amusement Tax']);

        $history = $compliance->histories()->sole();
        $this->assertTrue($compliance->activityable->is($organization));
        $this->assertTrue($compliance->updatedBy->is($admin));
        $this->assertSame(['label' => 'City Tax'], $history->previous_value);
        $this->assertTrue($history->activityCompliance->is($compliance));
        $this->assertTrue($history->creator->is($admin));
    }

    #[Test]
    public function activityComplianceUpdateWithoutAuditedChangesRecordsNoHistory(): void
    {
        $compliance = $this->makeCompliance(Organization::factory()->create());

        $compliance->update(['activityable_id' => 'another-owner']);

        $this->assertSame(0, ActivityComplianceHistory::count());
        $this->assertDatabaseHas('activity_compliances', ['uuid' => $compliance->uuid, 'activityable_id' => 'another-owner']);
    }

    #[Test]
    public function activityComplianceIsActiveAndAuditSnapshot(): void
    {
        $compliance = $this->makeCompliance(Organization::factory()->create());
        $fixed = new ActivityCompliance([
            'label' => 'Service Fee',
            'percentage' => 0,
            'fixed_amount' => '25.50',
            'amount_type' => ActivityCompliance::AMOUNT_TYPE['FIXED'],
            'status' => 'inactive',
        ]);

        $this->assertTrue($compliance->isActive());
        $this->assertFalse($fixed->isActive());
        $this->assertSame([
            'label' => 'City Tax',
            'percentage' => 5.0,
            'fixed_amount' => null,
            'amount_type' => 'percentage',
            'status' => 'active',
        ], $compliance->auditSnapshot());
        $this->assertSame(25.5, $fixed->auditSnapshot()['fixed_amount']);
    }

    #[Test]
    public function affiliateModelsResolveTheirUser(): void
    {
        $user = $this->makeUser();
        $click = AffiliateLinkClick::create(['partner_user_uuid' => $user->uuid, 'ref_code' => 'REF123']);
        $affiliate = UserAffiliate::create(['user_uuid' => $user->uuid, 'affiliate_status' => 'approved']);

        $this->assertTrue($click->partner->is($user));
        $this->assertTrue($affiliate->user->is($user));
    }

    #[Test]
    public function chatThreadResolvesInquiryAndListing(): void
    {
        $inquiry = VenueInquiry::factory()->create();
        $thread = ChatThread::create([
            'venue_inquiry_uuid' => $inquiry->uuid,
            'venue_listing_uuid' => $inquiry->venue_listing_uuid,
        ]);

        $this->assertTrue($thread->venueInquiry->is($inquiry));
        $this->assertTrue($thread->venueListing->is($inquiry->venueListing));
    }

    #[Test]
    public function confirmationTokenExposesPlainTokenAndUser(): void
    {
        $user = $this->makeUser();
        $token = ConfirmationToken::createForUser($user->uuid);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $token->getPlainToken());
        $this->assertSame($token->token, $token->getPlainToken());
        $this->assertTrue($token->user->is($user));
    }

    #[Test]
    public function merchantPayoutRequestResolvesAdminActors(): void
    {
        $organization = Organization::factory()->create();
        $voider = $this->makeAdmin('void@paec.test');
        $processor = $this->makeAdmin('process@paec.test');
        $requester = $this->makeAdmin('request@paec.test', $organization->uuid);

        $payout = MerchantPayoutRequest::create([
            'organization_uuid' => $organization->uuid,
            'organization_bank_uuid' => $organization->banks()->firstOrFail()->uuid,
            'amount_requested' => 1000,
            'status' => MerchantPayoutRequest::STATUS_VOID,
            'void_by_uuid' => $voider->uuid,
            'processed_by_uuid' => $processor->uuid,
            'requested_by_admin_uuid' => $requester->uuid,
        ]);

        $this->assertTrue($payout->voidBy->is($voider));
        $this->assertTrue($payout->processedBy->is($processor));
        $this->assertTrue($payout->requestedBy->is($requester));
        $this->assertSame([$payout->uuid], $organization->merchantPayoutRequests()->pluck('uuid')->all());
    }

    #[Test]
    public function organizationResolvesApproverAdminUsersAndBankOwner(): void
    {
        $approver = $this->makeAdmin('approver@paec.test');
        $organization = Organization::factory()->create(['approved_by' => $approver->uuid]);
        $member = $this->makeAdmin('member@paec.test', $organization->uuid);
        $bank = OrganizationBank::where('organization_uuid', $organization->uuid)->firstOrFail();

        $this->assertTrue($organization->approvedBy->is($approver));
        $this->assertSame([$member->uuid], $organization->adminUser()->pluck('uuid')->all());
        $this->assertTrue($bank->organization->is($organization));
    }

    #[Test]
    public function platformNotificationResolvesNotifiableAndReadState(): void
    {
        $user = $this->makeUser();
        $notification = PlatformNotification::create([
            'notifiable_type' => User::class,
            'notifiable_uuid' => $user->uuid,
            'type' => 'info',
            'title' => 'Hello',
        ]);

        $this->assertTrue($notification->notifiable->is($user));
        $this->assertFalse($notification->isRead());

        $notification->markRead();

        $this->assertTrue($notification->fresh()->isRead());
    }

    #[Test]
    public function venueResolvesCreatorUpdaterImageAndSeats(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user, 'api');
        $image = $this->makeUpload();
        $venue = Venue::create([
            'name' => 'Arena', 'code' => 'ARN', 'type' => Venue::TYPES['SPORT'], 'image_uuid' => $image->uuid,
        ]);
        $seat = VenueSeat::create([
            'venue_uuid' => $venue->uuid, 'col' => 1, 'row' => 'A', 'seat_no' => 1, 'category' => 'vip', 'color' => '#fff',
        ]);

        $this->assertTrue($venue->creator->is($user));
        $this->assertTrue($venue->updater->is($user));
        $this->assertTrue($venue->image->is($image));
        $this->assertSame([$seat->uuid], $venue->venueSeats()->pluck('uuid')->all());
    }

    #[Test]
    public function categoryAndEventSectionResolveCreatorAndUpdaterUsers(): void
    {
        $creator = $this->makeUser();
        $updater = $this->makeUser();
        $category = Category::create(['name' => 'Concert', 'code' => 'concert']);
        $section = EventSection::create(['name' => 'eat', 'title' => 'Eat']);
        Category::whereKey($category->uuid)->update(['created_by' => $creator->uuid, 'updated_by' => $updater->uuid]);
        EventSection::whereKey($section->uuid)->update(['created_by' => $creator->uuid, 'updated_by' => $updater->uuid]);

        $category->refresh();
        $section->refresh();

        $this->assertTrue($category->creator->is($creator));
        $this->assertTrue($category->updater->is($updater));
        $this->assertTrue($section->creator->is($creator));
        $this->assertTrue($section->updater->is($updater));
    }

    #[Test]
    public function venueInquiryResolvesProposalUpload(): void
    {
        $proposal = $this->makeUpload(['type' => 'document', 'path' => 'proposals/p.pdf']);
        $inquiry = VenueInquiry::factory()->create(['proposal_upload_uuid' => $proposal->uuid]);

        $this->assertTrue($inquiry->proposalUpload->is($proposal));
    }

    #[Test]
    public function venueListingUploadsAreOrderedAndResolveTheirOwner(): void
    {
        $listing = VenueListing::factory()->create();
        $second = $this->makeUpload([
            'uploadable_type' => VenueListing::class, 'uploadable_uuid' => $listing->uuid, 'order_number' => 2,
        ]);
        $first = $this->makeUpload([
            'uploadable_type' => VenueListing::class, 'uploadable_uuid' => $listing->uuid, 'order_number' => 1,
        ]);

        $this->assertSame([$first->uuid, $second->uuid], $listing->uploads()->pluck('uuid')->all());
        $this->assertTrue($first->uploadable->is($listing));
    }
}
