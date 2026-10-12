<?php

namespace Tests\Feature\Coverage\MiscControllers;

use App\Exceptions\NoScheduleFoundException;
use App\Http\Repositories\ScheduleRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScheduleControllerCoverageTest extends TestCase
{
    use MiscFixtures;
    use RefreshDatabase;

    #[Test]
    public function updateReturns404WhenScheduleDisappearsAfterValidation(): void
    {
        $schedule = $this->createSchedule($this->createEvent());

        $this->mock(ScheduleRepository::class, function ($mock) {
            $mock->shouldReceive('fetchOrThrow')->once()->andThrow(new NoScheduleFoundException());
            $mock->shouldNotReceive('update');
        });

        $this->withHeaders($this->adminHeaders())
            ->putJson("/api/v1/schedules/{$schedule->uuid}", ['status' => 'draft'])
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Schedule not found']);
    }

    #[Test]
    public function destroyReturns404ForUnknownSchedule(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->deleteJson('/api/v1/schedules/00000000-0000-0000-0000-000000000000')
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Schedule not found']);
    }

    #[Test]
    public function destroyReturns403WhenScheduleHasTransactions(): void
    {
        $event = $this->createEvent();
        $schedule = $this->createSchedule($event);
        $this->createTransaction($this->createCustomer(), $event, ['schedule_uuid' => $schedule->uuid]);

        $this->withHeaders($this->adminHeaders())
            ->deleteJson("/api/v1/schedules/{$schedule->uuid}")
            ->assertForbidden()
            ->assertJson([
                'success' => false,
                'message' => 'Cannot delete schedule with associated transactions.',
            ]);

        $this->assertDatabaseHas('schedules', ['uuid' => $schedule->uuid, 'deleted_at' => null]);
    }
}
