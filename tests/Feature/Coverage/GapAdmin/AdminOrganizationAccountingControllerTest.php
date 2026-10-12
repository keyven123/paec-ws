<?php

namespace Tests\Feature\Coverage\GapAdmin;

use App\Models\AdminUser;
use App\Models\Organization;
use App\Services\Organizer\OrganizerAccountingReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Coverage\GapAdmin\Concerns\CreatesGapAdminUsers;
use Tests\TestCase;

class AdminOrganizationAccountingControllerTest extends TestCase
{
    use CreatesGapAdminUsers;
    use RefreshDatabase;

    private AdminUser $admin;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createGapAdmin(['organizations-view']);
        $this->organization = Organization::factory()->create();
    }

    private function transactionsUrl(string $uuid, array $query = []): string
    {
        return '/api/v1/organizations/'.$uuid.'/accounting/transactions'
            .($query === [] ? '' : '?'.http_build_query($query));
    }

    #[Test]
    public function itListsAvailableBucketTransactions(): void
    {
        $response = $this->withHeaders($this->gapAdminHeaders($this->admin))
            ->getJson($this->transactionsUrl($this->organization->uuid, ['bucket' => 'available']))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Organization accounting transactions');

        $this->assertIsArray($response->json('data'));
    }

    #[Test]
    public function itPassesBucketAndPaginationToReportService(): void
    {
        $this->mock(OrganizerAccountingReportService::class, function ($mock) {
            $mock->shouldReceive('transactions')
                ->once()
                ->with($this->organization->uuid, 'pending', 3, 25, null)
                ->andReturn(['rows' => [['uuid' => 'tx-1']], 'meta' => ['total' => 1]]);
        });

        $this->withHeaders($this->gapAdminHeaders($this->admin))
            ->getJson($this->transactionsUrl($this->organization->uuid, [
                'bucket' => 'pending',
                'page' => 3,
                'per_page' => 25,
            ]))
            ->assertOk()
            ->assertJsonPath('data.rows.0.uuid', 'tx-1')
            ->assertJsonPath('data.meta.total', 1);
    }

    #[Test]
    public function itRequiresValidBucket(): void
    {
        $this->withHeaders($this->gapAdminHeaders($this->admin))
            ->getJson($this->transactionsUrl($this->organization->uuid, ['bucket' => 'settled']))
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Query parameter "bucket" is required and must be "available" or "pending".']);
    }

    #[Test]
    public function itReturns404ForUnknownOrganization(): void
    {
        $this->withHeaders($this->gapAdminHeaders($this->admin))
            ->getJson($this->transactionsUrl((string) Str::uuid(), ['bucket' => 'available']))
            ->assertNotFound();
    }
}
