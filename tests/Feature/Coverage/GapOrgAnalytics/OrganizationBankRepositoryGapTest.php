<?php

namespace Tests\Feature\Coverage\GapOrgAnalytics;

use App\Constants\GeneralConstants;
use App\Http\Repositories\OrganizationBankRepository;
use App\Models\Organization;
use App\Models\OrganizationBank;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OrganizationBankRepositoryGapTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::create([
            'name' => 'Bank Gap Org',
            'representative_first_name' => 'Rep',
            'representative_last_name' => 'Resentative',
            'email' => 'bank-gap@test.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
            'commission_percentage' => 10,
        ]);
    }

    private function repository(): OrganizationBankRepository
    {
        return app(OrganizationBankRepository::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function bankPayload(string $number, bool $isDefault): array
    {
        return [
            'account_type' => 'savings',
            'bank_name' => 'BDO',
            'bank_branch' => 'Makati',
            'bank_address' => 'Ayala Ave',
            'bank_account_name' => 'Bank Gap Org',
            'bank_account_number' => $number,
            'is_default' => $isDefault,
        ];
    }

    #[Test]
    public function upsertDefaultBankIgnoresPayloadWithoutBankFields(): void
    {
        $this->assertNull($this->repository()->upsertDefaultBank($this->organization, ['name' => 'Not a bank field']));
        $this->assertDatabaseCount('organization_banks', 0);
    }

    #[Test]
    public function upsertDefaultBankIgnoresBlankBankValues(): void
    {
        $result = $this->repository()->upsertDefaultBank($this->organization, [
            'bank_name' => '   ',
            'bank_account_number' => null,
        ]);

        $this->assertNull($result);
        $this->assertDatabaseCount('organization_banks', 0);
    }

    #[Test]
    public function syncWithEmptyPayloadRemovesAllBanksWithoutPickingADefault(): void
    {
        $this->repository()->createForOrganization($this->organization, $this->bankPayload('0001', true));

        $banks = $this->repository()->syncForOrganization($this->organization, []);

        $this->assertCount(0, $banks);
        $this->assertDatabaseCount('organization_banks', 0);
    }

    #[Test]
    public function updateBankPromotesFirstBankWhenNoDefaultRemains(): void
    {
        $bank = $this->repository()->createForOrganization($this->organization, $this->bankPayload('0001', true));

        $updated = $this->repository()->updateBank($bank, $this->bankPayload('0002', false));

        $this->assertSame('0002', $updated->bank_account_number);
        $this->assertTrue((bool) $updated->is_default);
        $this->assertSame(1, OrganizationBank::where('organization_uuid', $this->organization->uuid)->where('is_default', true)->count());
    }
}
