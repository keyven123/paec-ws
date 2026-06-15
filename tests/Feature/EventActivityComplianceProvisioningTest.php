<?php

namespace Tests\Feature;

use App\Models\ActivityCompliance;
use App\Models\Dataset;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EventActivityComplianceProvisioningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Dataset::query()->create([
            'name' => 'activity_compliance',
            'description' => 'Compliance taxes and fees',
            'value' => json_encode([
                ['label' => 'VAT', 'percentage' => 12, 'amount_type' => 'percentage', 'status' => 'inactive'],
                ['label' => 'City Tax', 'percentage' => 0, 'amount_type' => 'percentage', 'status' => 'inactive'],
                ['label' => 'Service Charge', 'percentage' => 0, 'amount_type' => 'percentage', 'status' => 'inactive'],
            ]),
        ]);
    }

    public function test_new_event_receives_default_activity_compliances(): void
    {
        $event = Event::factory()->create();

        $this->assertSame(3, ActivityCompliance::query()
            ->where('activityable_type', 'event')
            ->where('activityable_id', $event->uuid)
            ->count());

        $this->assertDatabaseHas('activity_compliances', [
            'activityable_type' => 'event',
            'activityable_id' => $event->uuid,
            'label' => 'VAT',
            'percentage' => 12,
            'status' => 'inactive',
        ]);
    }
}
