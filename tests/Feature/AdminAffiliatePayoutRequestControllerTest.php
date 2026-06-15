<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\AffiliatePayoutRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

class AdminAffiliatePayoutRequestControllerTest extends TestCase
{
    use GrantsAdminPermissions;
    use RefreshDatabase;

    private AdminUser $adminUser;
    private Role $adminRole;
    private string $adminToken;
    private User $partner;
    private AffiliatePayoutRequest $pendingPayout;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminRole = Role::create([
            'name' => 'Admin',
            'code' => GeneralConstants::ROLES['ADMIN']['name'],
            'is_admin' => true,
        ]);

        $this->grantAffiliatePayoutAdminPermissions($this->adminRole);

        $this->adminUser = AdminUser::create([
            'role_uuid' => $this->adminRole->uuid,
            'email' => 'admin@test.com',
            'password' => 'password123',
            'first_name' => 'Admin',
            'last_name' => 'User',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        $customerRole = Role::create([
            'name' => 'Customer',
            'code' => GeneralConstants::ROLES['CUSTOMER']['name'],
        ]);

        $this->partner = User::create([
            'role_uuid' => $customerRole->uuid,
            'email' => 'partner@test.com',
            'password' => bcrypt('password123'),
            'first_name' => 'Affiliate',
            'last_name' => 'Partner',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
        $this->partner->userAffiliate()->create([
            'affiliate_status' => GeneralConstants::AFFILIATE_STATUSES['APPROVED'],
            'affiliate_code' => 'ADMINTEST',
            'affiliate_applied_at' => now(),
            'affiliate_approved_at' => now(),
        ]);

        $this->pendingPayout = AffiliatePayoutRequest::create([
            'user_uuid' => $this->partner->uuid,
            'amount_requested' => 2000.00,
            'currency' => 'PHP',
            'status' => AffiliatePayoutRequest::STATUS_PENDING,
        ]);

        $this->adminToken = auth('admin')->login($this->adminUser);
    }

    // --- List ---

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanListPayoutRequests()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/affiliate-payout-requests');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'uuid',
                        'user_uuid',
                        'amount_requested',
                        'currency',
                        'status',
                        'admin_notes',
                        'processed_at',
                        'created_at',
                        'updated_at',
                    ],
                ],
                'meta',
            ]);

        $this->assertCount(1, $response->json('data'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanFilterPayoutsByStatus()
    {
        AffiliatePayoutRequest::create([
            'user_uuid' => $this->partner->uuid,
            'amount_requested' => 1000,
            'currency' => 'PHP',
            'status' => AffiliatePayoutRequest::STATUS_APPROVED,
            'processed_at' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/affiliate-payout-requests?status=pending');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('pending', $data[0]['status']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/affiliate-payout-requests?status=approved');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('approved', $data[0]['status']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itRequiresAuthForListing()
    {
        $response = $this->getJson('/api/v1/affiliate-payout-requests');
        $response->assertStatus(401);
    }

    // --- Approve ---

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanApprovePendingPayout()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->patchJson('/api/v1/affiliate-payout-requests/' . $this->pendingPayout->uuid . '/approve');

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'approved');

        $this->pendingPayout->refresh();
        $this->assertEquals(AffiliatePayoutRequest::STATUS_APPROVED, $this->pendingPayout->status);
        $this->assertNotNull($this->pendingPayout->processed_at);
        $this->assertEquals($this->adminUser->uuid, $this->pendingPayout->processed_by_uuid);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCannotApproveAlreadyApprovedPayout()
    {
        $this->pendingPayout->update([
            'status' => AffiliatePayoutRequest::STATUS_APPROVED,
            'processed_at' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->patchJson('/api/v1/affiliate-payout-requests/' . $this->pendingPayout->uuid . '/approve');

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'This payout request is not pending.']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itReturns404ForNonExistentPayoutApproval()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->patchJson('/api/v1/affiliate-payout-requests/non-existent-uuid/approve');

        $response->assertStatus(404);
    }

    // --- Decline ---

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanDeclinePendingPayout()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->patchJson('/api/v1/affiliate-payout-requests/' . $this->pendingPayout->uuid . '/decline', [
            'admin_notes' => 'Insufficient documentation',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'declined');

        $this->pendingPayout->refresh();
        $this->assertEquals(AffiliatePayoutRequest::STATUS_DECLINED, $this->pendingPayout->status);
        $this->assertEquals('Insufficient documentation', $this->pendingPayout->admin_notes);
        $this->assertNotNull($this->pendingPayout->processed_at);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanDeclinePayoutWithoutNotes()
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->patchJson('/api/v1/affiliate-payout-requests/' . $this->pendingPayout->uuid . '/decline', []);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'declined');

        $this->pendingPayout->refresh();
        $this->assertNull($this->pendingPayout->admin_notes);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCannotDeclineAlreadyDeclinedPayout()
    {
        $this->pendingPayout->update([
            'status' => AffiliatePayoutRequest::STATUS_DECLINED,
            'processed_at' => now(),
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->patchJson('/api/v1/affiliate-payout-requests/' . $this->pendingPayout->uuid . '/decline');

        $response->assertStatus(422)
            ->assertJsonFragment(['message' => 'This payout request is not pending.']);
    }

    // --- Pagination ---

    #[\PHPUnit\Framework\Attributes\Test]
    public function itCanPaginatePayoutRequests()
    {
        for ($i = 0; $i < 20; $i++) {
            AffiliatePayoutRequest::create([
                'user_uuid' => $this->partner->uuid,
                'amount_requested' => 100 + $i,
                'currency' => 'PHP',
                'status' => AffiliatePayoutRequest::STATUS_APPROVED,
                'processed_at' => now(),
            ]);
        }

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->adminToken,
        ])->getJson('/api/v1/affiliate-payout-requests?per_page=5');

        $response->assertStatus(200);
        $this->assertCount(5, $response->json('data'));
        $this->assertGreaterThan(1, $response->json('meta.last_page'));
    }
}
