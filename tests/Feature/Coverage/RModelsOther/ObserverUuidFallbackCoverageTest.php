<?php

namespace Tests\Feature\Coverage\RModelsOther;

use App\Models;
use App\Observers;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * HasUuids assigns the key before observers run, so the observers' own uuid fallback
 * is only reachable when the hook is invoked on a model that has no uuid yet.
 */
class ObserverUuidFallbackCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{class-string, class-string<Model>}>
     */
    public static function observerProvider(): array
    {
        return [
            'activity compliance' => [Observers\ActivityComplianceObserver::class, Models\ActivityCompliance::class],
            'admin user' => [Observers\AdminUserObserver::class, Models\AdminUser::class],
            'blocked date' => [Observers\BlockedDateObserver::class, Models\BlockedDate::class],
            'branch' => [Observers\BranchObserver::class, Models\Branch::class],
            'category' => [Observers\CategoryObserver::class, Models\Category::class],
            'event' => [Observers\EventObserver::class, Models\Event::class],
            'event section' => [Observers\EventSectionObserver::class, Models\EventSection::class],
            'event ticket' => [Observers\EventTicketObserver::class, Models\EventTicket::class],
            'organization platform com' => [Observers\OrganizationPlatformComObserver::class, Models\OrganizationPlatformCom::class],
            'permission' => [Observers\PermissionObserver::class, Models\Permission::class],
            'promo code' => [Observers\PromoCodeObserver::class, Models\PromoCode::class],
            'role' => [Observers\RoleObserver::class, Models\Role::class],
            'schedule' => [Observers\ScheduleObserver::class, Models\Schedule::class],
            'schedule time' => [Observers\ScheduleTimeObserver::class, Models\ScheduleTime::class],
            'temp transaction' => [Observers\TempTransactionObserver::class, Models\TempTransaction::class],
            'ticket' => [Observers\TicketObserver::class, Models\Ticket::class],
            'ticket seat' => [Observers\TicketSeatObserver::class, Models\TicketSeat::class],
            'transaction' => [Observers\TransactionObserver::class, Models\Transaction::class],
            'upload' => [Observers\UploadObserver::class, Models\Upload::class],
            'user' => [Observers\UserObserver::class, Models\User::class],
            'venue' => [Observers\VenueObserver::class, Models\Venue::class],
            'venue seat' => [Observers\VenueSeatObserver::class, Models\VenueSeat::class],
        ];
    }

    #[Test]
    #[DataProvider('observerProvider')]
    public function creatingHookGeneratesUuidWhenModelHasNone(string $observerClass, string $modelClass): void
    {
        $model = new $modelClass();
        $this->assertEmpty($model->uuid);

        app($observerClass)->creating($model);

        $this->assertTrue(Str::isUuid($model->uuid));
    }

    #[Test]
    #[DataProvider('observerProvider')]
    public function creatingHookKeepsAnExistingUuid(string $observerClass, string $modelClass): void
    {
        $model = new $modelClass();
        $model->uuid = $existing = (string) Str::uuid();

        app($observerClass)->creating($model);

        $this->assertSame($existing, $model->uuid);
    }
}
