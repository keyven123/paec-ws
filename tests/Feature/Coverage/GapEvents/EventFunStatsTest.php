<?php

namespace Tests\Feature\Coverage\GapEvents;

use PHPUnit\Framework\Attributes\Test;

class EventFunStatsTest extends GapEventsTestCase
{
    #[Test]
    public function funStatsAreZeroWhenNoCatalogSectionsExist(): void
    {
        [, $token] = $this->createAdmin(['events' => ['view']]);
        $this->createEvent('Uncatalogued Event');

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/v1/events/fun-stats')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.total_published', 0)
            ->assertJsonPath('data.total_pending', 0)
            ->assertJsonPath('data.total_transaction_amount', 0)
            ->assertJsonPath('data.total_tickets_sold', 0);
    }
}
