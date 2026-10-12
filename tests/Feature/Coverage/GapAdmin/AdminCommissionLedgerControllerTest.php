<?php

namespace Tests\Feature\Coverage\GapAdmin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Coverage\GapAdmin\Concerns\CreatesGapAdminUsers;
use Tests\TestCase;

class AdminCommissionLedgerControllerTest extends TestCase
{
    use CreatesGapAdminUsers;
    use RefreshDatabase;

    #[Test]
    public function itForbidsNonAdminRoleEvenWithFinancePermission(): void
    {
        $staff = $this->createGapAdmin(['finance-view'], isAdmin: false);

        $this->withHeaders($this->gapAdminHeaders($staff))
            ->getJson('/api/v1/admin/finance/commission-ledger?from=2026-01-01&to=2026-01-31')
            ->assertForbidden()
            ->assertExactJson(['message' => 'Forbidden.']);
    }
}
