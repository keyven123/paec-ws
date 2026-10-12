<?php

namespace Tests\Feature\Coverage\GapAdmin;

use App\Constants\GeneralConstants;
use App\Http\Repositories\ActivityComplianceRepository;
use App\Models\ActivityCompliance;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\Organization;
use App\Models\TransactionCompliance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Coverage\GapAdmin\Concerns\CreatesGapAdminUsers;
use Tests\Feature\Coverage\GapAdmin\Concerns\CreatesGapPaidTransactions;
use Tests\TestCase;

class ActivityComplianceControllerTest extends TestCase
{
    use CreatesGapAdminUsers;
    use CreatesGapPaidTransactions;
    use RefreshDatabase;

    private const BASE = '/api/v1/activity-compliances';

    private AdminUser $admin;

    private Organization $organization;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createGapAdmin([
            'activity-compliances-view',
            'activity-compliances-create',
            'activity-compliances-update',
            'activity-compliances-delete',
        ]);
        $this->organization = Organization::factory()->create();
        $this->event = Event::withoutEvents(fn () => Event::factory()->create([
            'organization_uuid' => $this->organization->uuid,
        ]));
    }

    private function asComplianceAdmin(): static
    {
        return $this->withHeaders($this->gapAdminHeaders($this->admin));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createCompliance(array $overrides = []): ActivityCompliance
    {
        return ActivityCompliance::query()->create(array_merge([
            'activityable_type' => 'event',
            'activityable_id' => $this->event->uuid,
            'label' => 'VAT '.Str::random(4),
            'percentage' => 12,
            'amount_type' => ActivityCompliance::AMOUNT_TYPE['PERCENTAGE'],
            'status' => GeneralConstants::GENERAL_STATUSES['INACTIVE'],
        ], $overrides));
    }

    #[Test]
    public function itReturns404WhenStoringForSoftDeletedEvent(): void
    {
        $this->event->delete();

        $this->asComplianceAdmin()
            ->postJson(self::BASE, [
                'organization_uuid' => $this->organization->uuid,
                'event_uuid' => $this->event->uuid,
                'label' => 'VAT',
                'percentage' => 12,
                'amount_type' => ActivityCompliance::AMOUNT_TYPE['PERCENTAGE'],
            ])
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Event not found for this organization',
            ]);

        $this->assertDatabaseCount('activity_compliances', 0);
    }

    #[Test]
    public function itReturns404WhenUpdatingUnknownCompliance(): void
    {
        $this->asComplianceAdmin()
            ->patchJson(self::BASE.'/'.Str::uuid(), ['label' => 'Renamed'])
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Activity compliance not found',
            ]);
    }

    #[Test]
    public function itRethrowsValidationErrorWhenUpdateExceedsActivePercentageTotal(): void
    {
        $this->createCompliance([
            'label' => 'Existing',
            'percentage' => 80,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
        $target = $this->createCompliance(['label' => 'Target', 'percentage' => 10]);

        $this->asComplianceAdmin()
            ->patchJson(self::BASE.'/'.$target->uuid, [
                'percentage' => 50,
                'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['percentage']);

        $this->assertDatabaseHas('activity_compliances', [
            'uuid' => $target->uuid,
            'percentage' => 10,
            'status' => GeneralConstants::GENERAL_STATUSES['INACTIVE'],
        ]);
    }

    #[Test]
    public function itUpdatesNonEventComplianceWithoutPercentageCheck(): void
    {
        $compliance = $this->createCompliance([
            'activityable_type' => 'venue',
            'activityable_id' => (string) Str::uuid(),
            'label' => 'Venue fee',
            'percentage' => 10,
        ]);

        $this->asComplianceAdmin()
            ->patchJson(self::BASE.'/'.$compliance->uuid, [
                'percentage' => 100,
                'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            ])
            ->assertOk()
            ->assertJsonPath('data.percentage', 100);

        $this->assertDatabaseHas('activity_compliances', [
            'uuid' => $compliance->uuid,
            'percentage' => 100,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
    }

    #[Test]
    public function itReturns404WhenDeletingUnknownCompliance(): void
    {
        $this->asComplianceAdmin()
            ->deleteJson(self::BASE.'/'.Str::uuid())
            ->assertNotFound()
            ->assertExactJson([
                'success' => false,
                'message' => 'Activity compliance not found',
            ]);
    }

    #[Test]
    public function itRefusesToDeleteComplianceLinkedToTransactions(): void
    {
        $compliance = $this->createCompliance();
        $transaction = $this->createGapPaidTransaction($this->event, $this->createGapCustomer());
        TransactionCompliance::query()->create([
            'transaction_uuid' => $transaction->uuid,
            'activity_compliance_uuid' => $compliance->uuid,
            'percentage' => 12,
            'amount' => 120,
        ]);

        $message = 'This compliance rule cannot be deleted because it is linked to completed transactions.';

        $this->asComplianceAdmin()
            ->deleteJson(self::BASE.'/'.$compliance->uuid)
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', $message)
            ->assertJsonPath('errors.activity_compliance.0', $message);

        $this->assertDatabaseHas('activity_compliances', ['uuid' => $compliance->uuid]);
    }

    #[Test]
    public function repositoryDeleteThrowsWhenNoRowWasRemoved(): void
    {
        $detached = new ActivityCompliance([
            'activityable_type' => 'event',
            'activityable_id' => $this->event->uuid,
            'label' => 'Ghost',
        ]);
        $detached->uuid = (string) Str::uuid();

        try {
            app(ActivityComplianceRepository::class)->delete($detached);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['activity_compliance' => ['Unable to delete this compliance rule.']],
                $e->errors(),
            );
        }
    }
}
