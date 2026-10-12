<?php

namespace Tests\Feature\Coverage\MiscControllers;

use App\Exceptions\NoScheduleTimeFoundException;
use App\Http\Repositories\ScheduleTimeRepository;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScheduleTimeControllerCoverageTest extends TestCase
{
    use MiscFixtures;
    use RefreshDatabase;

    private Schedule $schedule;

    private ScheduleTime $scheduleTime;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schedule = $this->createSchedule($this->createEvent());
        $this->scheduleTime = $this->createScheduleTime($this->schedule);
    }

    #[Test]
    public function showReturnsScheduleTime(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->getJson("/api/v1/schedule-times/{$this->scheduleTime->uuid}")
            ->assertOk()
            ->assertJsonPath('data.uuid', $this->scheduleTime->uuid)
            ->assertJsonPath('data.schedule_uuid', $this->schedule->uuid);
    }

    #[Test]
    public function showReturns404ForUnknownScheduleTime(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->getJson('/api/v1/schedule-times/00000000-0000-0000-0000-000000000000')
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Schedule time not found']);
    }

    #[Test]
    public function updateChangesScheduleTime(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->putJson("/api/v1/schedule-times/{$this->scheduleTime->uuid}", [
                'time_start' => '13:00:00',
                'time_end' => '15:00:00',
                'status' => 'draft',
            ])
            ->assertOk()
            ->assertJsonPath('data.uuid', $this->scheduleTime->uuid)
            ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('schedule_times', [
            'uuid' => $this->scheduleTime->uuid,
            'time_start' => '13:00:00',
            'time_end' => '15:00:00',
            'status' => 'draft',
        ]);
    }

    #[Test]
    public function updateReturns404WhenScheduleTimeDisappearsAfterValidation(): void
    {
        $this->mock(ScheduleTimeRepository::class, function ($mock) {
            $mock->shouldReceive('fetchOrThrow')->once()->andThrow(new NoScheduleTimeFoundException());
            $mock->shouldNotReceive('update');
        });

        $this->withHeaders($this->adminHeaders())
            ->putJson("/api/v1/schedule-times/{$this->scheduleTime->uuid}", ['status' => 'draft'])
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Schedule time not found']);
    }

    #[Test]
    public function destroySoftDeletesScheduleTime(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->deleteJson("/api/v1/schedule-times/{$this->scheduleTime->uuid}")
            ->assertNoContent();

        $this->assertSoftDeleted('schedule_times', ['uuid' => $this->scheduleTime->uuid]);
    }

    #[Test]
    public function destroyReturns404ForUnknownScheduleTime(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->deleteJson('/api/v1/schedule-times/00000000-0000-0000-0000-000000000000')
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Schedule time not found']);
    }

    #[Test]
    public function destroyReturns403WhenScheduleTimeHasTransactions(): void
    {
        $this->createTransaction($this->createCustomer(), $this->schedule->event, [
            'schedule_uuid' => $this->schedule->uuid,
            'schedule_time_uuid' => $this->scheduleTime->uuid,
        ]);

        $this->withHeaders($this->adminHeaders())
            ->deleteJson("/api/v1/schedule-times/{$this->scheduleTime->uuid}")
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'Cannot delete schedule time with associated transactions.',
            ]);

        $this->assertDatabaseHas('schedule_times', ['uuid' => $this->scheduleTime->uuid, 'deleted_at' => null]);
    }
}
