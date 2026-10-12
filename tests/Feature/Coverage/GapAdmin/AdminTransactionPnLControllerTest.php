<?php

namespace Tests\Feature\Coverage\GapAdmin;

use App\Http\Controllers\AdminTransactionPnLController;
use App\Services\Platform\AdminTransactionPnLService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Coverage\GapAdmin\Concerns\CreatesGapAdminUsers;
use Tests\TestCase;

class AdminTransactionPnLControllerTest extends TestCase
{
    use CreatesGapAdminUsers;
    use RefreshDatabase;

    private const URL = '/api/v1/admin/finance/transaction-pnl';

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerSqliteDateFormat();
    }

    private function asFinanceAdmin(): static
    {
        return $this->withHeaders($this->gapAdminHeaders($this->createGapAdmin(['finance-view'])));
    }

    #[Test]
    public function itReturnsLeaderboardWithDefaults(): void
    {
        $response = $this->asFinanceAdmin()
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Transaction P&L leaderboard');

        $this->assertIsArray($response->json('data'));
    }

    #[Test]
    public function itPassesNormalizedQueryToService(): void
    {
        $this->mock(AdminTransactionPnLService::class, function ($mock) {
            $mock->shouldReceive('build')
                ->once()
                ->with(AdminTransactionPnLService::VIEW_FUN, '2026-02', 'margin', 3, 100)
                ->andReturn(['rows' => [], 'stub' => true]);
        });

        $this->asFinanceAdmin()
            ->getJson(self::URL.'?'.http_build_query([
                'view' => 'FUN',
                'sort' => 'Margin',
                'month' => '2026-02',
                'page' => 3,
                'per_page' => 500,
            ]))
            ->assertOk()
            ->assertJsonPath('data.stub', true);
    }

    #[Test]
    public function itClampsPageAndPerPageAndOmitsMonth(): void
    {
        $this->mock(AdminTransactionPnLService::class, function ($mock) {
            $mock->shouldReceive('build')
                ->once()
                ->with(AdminTransactionPnLService::VIEW_EVENTS, null, 'gmv', 1, 1)
                ->andReturn(['rows' => []]);
        });

        $this->asFinanceAdmin()
            ->getJson(self::URL.'?sort=gmv&page=-4&per_page=0')
            ->assertOk()
            ->assertJsonPath('data.rows', []);
    }

    #[Test]
    public function itRejectsInvalidView(): void
    {
        $this->asFinanceAdmin()
            ->getJson(self::URL.'?view=venues')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Invalid view. Use events or fun.']);
    }

    #[Test]
    public function itRejectsInvalidSort(): void
    {
        $this->asFinanceAdmin()
            ->getJson(self::URL.'?sort=tickets')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Invalid sort. Use revenue, gmv, or margin.']);
    }

    #[Test]
    public function itRejectsInvalidMonthFormat(): void
    {
        $this->asFinanceAdmin()
            ->getJson(self::URL.'?month=2026-2')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Invalid month format. Use YYYY-MM.']);
    }

    #[Test]
    public function itForbidsNonAdminRoleEvenWithFinancePermission(): void
    {
        $staff = $this->createGapAdmin(['finance-view'], isAdmin: false);

        $this->withHeaders($this->gapAdminHeaders($staff))
            ->getJson(self::URL)
            ->assertForbidden()
            ->assertExactJson(['message' => 'Forbidden.']);
    }

    #[Test]
    public function itForbidsWhenNoAdminIsAuthenticated(): void
    {
        $service = Mockery::mock(AdminTransactionPnLService::class);
        $service->shouldNotReceive('build');

        $response = app(AdminTransactionPnLController::class)->index(Request::create(self::URL, 'GET'), $service);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['message' => 'Forbidden.'], $response->getData(true));
    }
}
