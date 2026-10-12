<?php

namespace Tests\Feature\Coverage\GapAdmin;

use App\Http\Controllers\AdminPlatformPnLController;
use App\Services\Platform\AdminPlatformPnLService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Coverage\GapAdmin\Concerns\CreatesGapAdminUsers;
use Tests\TestCase;

class AdminPlatformPnLControllerTest extends TestCase
{
    use CreatesGapAdminUsers;
    use RefreshDatabase;

    private const URL = '/api/v1/admin/finance/platform-pnl';

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
    public function itReturnsMonthlyReportByDefault(): void
    {
        $this->asFinanceAdmin()
            ->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Platform P&L')
            ->assertJsonPath('data.period.type', AdminPlatformPnLService::PERIOD_MONTHLY)
            ->assertJsonStructure(['data' => ['as_of', 'timezone', 'period' => ['current_range' => ['start', 'end']]]]);
    }

    #[Test]
    public function itAcceptsAsOfAndCustomRange(): void
    {
        $this->asFinanceAdmin()
            ->getJson(self::URL.'?'.http_build_query([
                'as_of' => '2026-03-15',
                'period' => 'CUSTOM',
                'date_from' => '2026-03-01',
                'date_to' => '2026-03-10',
            ]))
            ->assertOk()
            ->assertJsonPath('data.period.type', AdminPlatformPnLService::PERIOD_CUSTOM);
    }

    #[Test]
    public function itRejectsInvalidAsOfDate(): void
    {
        $this->asFinanceAdmin()
            ->getJson(self::URL.'?as_of=not-a-real-date')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Invalid as_of date.']);
    }

    #[Test]
    public function itRejectsUnknownPeriod(): void
    {
        $this->asFinanceAdmin()
            ->getJson(self::URL.'?period=hourly')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Invalid period. Use daily, weekly, monthly, yearly, or custom.']);
    }

    #[Test]
    public function itRequiresBothBoundsForCustomPeriod(): void
    {
        $this->asFinanceAdmin()
            ->getJson(self::URL.'?period=custom&custom_start=2026-03-01')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'custom_start and custom_end are required when period is custom.']);
    }

    #[Test]
    public function itRejectsUnparseableCustomBounds(): void
    {
        $this->asFinanceAdmin()
            ->getJson(self::URL.'?period=custom&custom_start=garbage-date&custom_end=2026-03-10')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Invalid custom_start or custom_end.']);
    }

    #[Test]
    public function itReturnsServiceValidationMessageWhenCustomStartIsAfterEnd(): void
    {
        $this->asFinanceAdmin()
            ->getJson(self::URL.'?period=custom&custom_start=2026-03-10&custom_end=2026-03-01')
            ->assertStatus(422)
            ->assertExactJson(['message' => 'Custom start date must be on or before end date.']);
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
        $response = app(AdminPlatformPnLController::class)->show(
            Request::create(self::URL, 'GET'),
            app(AdminPlatformPnLService::class),
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(['message' => 'Forbidden.'], $response->getData(true));
    }
}
