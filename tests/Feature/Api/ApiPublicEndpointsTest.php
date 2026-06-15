<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Concerns\AuthenticatesApiUsers;
use Tests\TestCase;

class ApiPublicEndpointsTest extends TestCase
{
    use AuthenticatesApiUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedApiDatabase();
    }

    public function test_health_endpoint(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonStructure(['status', 'database', 'timestamp']);
    }

    public function test_api_info_endpoint(): void
    {
        $this->getJson('/api')
            ->assertOk()
            ->assertJsonPath('name', 'PAEC');
    }

    public function test_public_marketplace_endpoints(): void
    {
        $this->getJson('/api/v1/public/categories')->assertOk();
        $this->getJson('/api/v1/public/places')->assertOk();
        $this->getJson('/api/v1/public/events')->assertOk();
        $this->getJson('/api/v1/public/events/browse-by-city')->assertOk();
    }

    public function test_public_cms_endpoints(): void
    {
        $this->getJson('/api/v1/public/cms/footer')->assertOk();
        $this->getJson('/api/v1/public/cms/pages')->assertOk();
    }

    public function test_site_visit_dataset_endpoints(): void
    {
        $this->getJson('/api/v1/datasets/site-visit')->assertOk();

        $this->postJson('/api/v1/datasets/site-visit/increment')
            ->assertOk();
    }
}
