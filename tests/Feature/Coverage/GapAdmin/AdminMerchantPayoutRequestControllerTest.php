<?php

namespace Tests\Feature\Coverage\GapAdmin;

use App\Models\AdminUser;
use App\Models\MerchantPayoutRequest;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreatesMerchantPayoutTestData;
use Tests\Feature\Coverage\GapAdmin\Concerns\CreatesGapAdminUsers;
use Tests\TestCase;

class AdminMerchantPayoutRequestControllerTest extends TestCase
{
    use CreatesGapAdminUsers;
    use CreatesMerchantPayoutTestData;
    use RefreshDatabase;

    private const BASE = '/api/v1/merchant-payout-requests';

    private AdminUser $admin;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createGapAdmin(['organizations-view', 'organizations-update']);
        $this->organization = Organization::factory()->create(['name' => 'Payout Merchant']);
    }

    private function asOrgAdmin(): static
    {
        return $this->withHeaders($this->gapAdminHeaders($this->admin));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPayout(array $overrides = []): MerchantPayoutRequest
    {
        return MerchantPayoutRequest::query()->create($this->merchantPayoutAttributes($this->organization, $overrides));
    }

    #[Test]
    public function itListsPayoutRequestsWithOrganizationDetails(): void
    {
        $processedAt = Carbon::parse('2026-05-01 10:00:00');
        $row = $this->createPayout([
            'amount_requested' => 1234.5,
            'status' => MerchantPayoutRequest::STATUS_APPROVED,
            'merchant_note' => 'Please remit',
            'admin_notes' => 'Done',
            'processed_at' => $processedAt,
        ]);

        $this->asOrgAdmin()
            ->getJson(self::BASE)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('data.0.uuid', $row->uuid)
            ->assertJsonPath('data.0.organization_uuid', $this->organization->uuid)
            ->assertJsonPath('data.0.organization_name', 'Payout Merchant')
            ->assertJsonPath('data.0.amount_requested', 1234.5)
            ->assertJsonPath('data.0.currency', 'PHP')
            ->assertJsonPath('data.0.status', MerchantPayoutRequest::STATUS_APPROVED)
            ->assertJsonPath('data.0.merchant_note', 'Please remit')
            ->assertJsonPath('data.0.admin_notes', 'Done')
            ->assertJsonPath(
                'data.0.processed_at',
                $processedAt->copy()->timezone(config('app.timezone', 'UTC'))->toIso8601String(),
            );
    }

    #[Test]
    public function itFiltersPayoutRequestsByStatus(): void
    {
        $pending = $this->createPayout(['status' => MerchantPayoutRequest::STATUS_PENDING]);
        $this->createPayout(['status' => MerchantPayoutRequest::STATUS_DECLINED, 'processed_at' => now()]);

        $this->asOrgAdmin()
            ->getJson(self::BASE.'?status=pending&per_page=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.per_page', 5)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.uuid', $pending->uuid)
            ->assertJsonPath('data.0.processed_at', null);
    }

    #[Test]
    public function itApprovesPendingPayoutRequest(): void
    {
        $row = $this->createPayout([
            'status' => MerchantPayoutRequest::STATUS_PENDING,
            'admin_notes' => 'stale note',
        ]);

        $this->asOrgAdmin()
            ->patchJson(self::BASE.'/'.$row->uuid.'/approve')
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Payout approved',
                'data' => ['uuid' => $row->uuid],
            ]);

        $row->refresh();
        $this->assertSame(MerchantPayoutRequest::STATUS_APPROVED, $row->status);
        $this->assertSame($this->admin->uuid, $row->processed_by_uuid);
        $this->assertNotNull($row->processed_at);
        $this->assertNull($row->admin_notes);
    }

    #[Test]
    public function itReturns404WhenApprovingUnknownPayout(): void
    {
        $this->asOrgAdmin()
            ->patchJson(self::BASE.'/'.Str::uuid().'/approve')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Payout request not found.']);
    }

    #[Test]
    public function itRejectsApprovingNonPendingPayout(): void
    {
        $row = $this->createPayout(['status' => MerchantPayoutRequest::STATUS_DECLINED, 'processed_at' => now()]);

        $this->asOrgAdmin()
            ->patchJson(self::BASE.'/'.$row->uuid.'/approve')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'This payout request is not pending.']);

        $this->assertSame(MerchantPayoutRequest::STATUS_DECLINED, $row->fresh()->status);
    }

    #[Test]
    public function itDeclinesPendingPayoutWithAdminNotes(): void
    {
        $row = $this->createPayout(['status' => MerchantPayoutRequest::STATUS_PENDING]);

        $this->asOrgAdmin()
            ->patchJson(self::BASE.'/'.$row->uuid.'/decline', ['admin_notes' => 'Bank details mismatch'])
            ->assertOk()
            ->assertExactJson([
                'success' => true,
                'message' => 'Payout declined',
                'data' => ['uuid' => $row->uuid],
            ]);

        $this->assertDatabaseHas('merchant_payout_requests', [
            'uuid' => $row->uuid,
            'status' => MerchantPayoutRequest::STATUS_DECLINED,
            'processed_by_uuid' => $this->admin->uuid,
            'admin_notes' => 'Bank details mismatch',
        ]);
    }

    #[Test]
    public function itDeclinesPendingPayoutWithoutNotes(): void
    {
        $row = $this->createPayout(['status' => MerchantPayoutRequest::STATUS_PENDING]);

        $this->asOrgAdmin()
            ->patchJson(self::BASE.'/'.$row->uuid.'/decline')
            ->assertOk();

        $row->refresh();
        $this->assertSame(MerchantPayoutRequest::STATUS_DECLINED, $row->status);
        $this->assertNull($row->admin_notes);
    }

    #[Test]
    public function itReturns404WhenDecliningUnknownPayout(): void
    {
        $this->asOrgAdmin()
            ->patchJson(self::BASE.'/'.Str::uuid().'/decline')
            ->assertNotFound()
            ->assertExactJson(['message' => 'Payout request not found.']);
    }

    #[Test]
    public function itRejectsDecliningNonPendingPayout(): void
    {
        $row = $this->createPayout(['status' => MerchantPayoutRequest::STATUS_APPROVED, 'processed_at' => now()]);

        $this->asOrgAdmin()
            ->patchJson(self::BASE.'/'.$row->uuid.'/decline', ['admin_notes' => 'Too late'])
            ->assertStatus(422)
            ->assertExactJson(['message' => 'This payout request is not pending.']);

        $this->assertSame(MerchantPayoutRequest::STATUS_APPROVED, $row->fresh()->status);
    }
}
