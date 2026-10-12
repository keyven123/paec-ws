<?php

namespace Tests\Feature\Coverage\GapAuthMisc;

use App\Constants\GeneralConstants;
use App\Exceptions\NoAdminUserFoundException;
use App\Exceptions\NoCategoryFoundException;
use App\Http\Repositories\AdminUserRepository;
use App\Http\Repositories\CategoryRepository;
use App\Models\Category;
use App\Models\Event;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AdminUserAndCategoryGapTest extends TestCase
{
    use GapAuthMiscFixtures;
    use RefreshDatabase;

    #[Test]
    public function updateAdminUserReturnsNotFoundWhenRepositoryCannotFindIt(): void
    {
        $admin = $this->makeAdmin($this->makeRole(['admin-users' => ['update']]));
        $target = $this->makeAdmin($this->makeRole());

        $this->partialMock(AdminUserRepository::class, function ($mock) {
            $mock->shouldReceive('fetchOrThrow')->once()->andThrow(new NoAdminUserFoundException());
        });

        $this->withHeaders($this->adminHeaders($admin))
            ->putJson("/api/v1/admin-users/{$target->uuid}", ['first_name' => 'Renamed'])
            ->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'Admin user not found']);

        $this->assertDatabaseMissing('admin_users', ['uuid' => $target->uuid, 'first_name' => 'Renamed']);
    }

    #[Test]
    public function deleteAdminUserReturnsNotFoundForUnknownUuid(): void
    {
        $admin = $this->makeAdmin($this->makeRole(['admin-users' => ['delete']]));

        $this->withHeaders($this->adminHeaders($admin))
            ->deleteJson('/api/v1/admin-users/' . Str::uuid())
            ->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'Admin user not found']);
    }

    #[Test]
    public function deleteAdminUserForbidsSelfDeletion(): void
    {
        $admin = $this->makeAdmin($this->makeRole(['admin-users' => ['delete']]));

        $this->withHeaders($this->adminHeaders($admin))
            ->deleteJson("/api/v1/admin-users/{$admin->uuid}")
            ->assertStatus(403)
            ->assertJsonPath('message', 'Cannot delete your own account.');

        $this->assertDatabaseHas('admin_users', ['uuid' => $admin->uuid, 'deleted_at' => null]);
    }

    #[Test]
    public function fetchWithRoleOrThrowReturnsAdminWithRoleLoaded(): void
    {
        $admin = $this->makeAdmin($this->makeRole());

        $found = app(AdminUserRepository::class)->fetchWithRoleOrThrow('uuid', $admin->uuid);

        $this->assertSame($admin->uuid, $found->uuid);
        $this->assertTrue($found->relationLoaded('role'));
    }

    #[Test]
    public function fetchWithRoleOrThrowThrowsForUnknownAdmin(): void
    {
        $this->expectException(NoAdminUserFoundException::class);

        app(AdminUserRepository::class)->fetchWithRoleOrThrow('uuid', (string) Str::uuid());
    }

    #[Test]
    public function getByRoleFiltersAdminsByRoleCode(): void
    {
        $organizerRole = $this->makeRole([], false, 'gap-organizer');
        $organizer = $this->makeAdmin($organizerRole);
        $this->makeAdmin($this->makeRole());

        $uuids = app(AdminUserRepository::class)->getByRole('gap-organizer')->pluck('uuid')->all();

        $this->assertSame([$organizer->uuid], $uuids);
    }

    #[Test]
    public function updateCategoryReturnsNotFoundWhenRepositoryCannotFindIt(): void
    {
        $admin = $this->makeAdmin($this->makeRole(['categories' => ['update']]));
        $category = Category::create([
            'name' => 'Concerts',
            'code' => 'concerts',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $this->partialMock(CategoryRepository::class, function ($mock) {
            $mock->shouldReceive('fetchOrThrow')->once()->andThrow(new NoCategoryFoundException());
        });

        $this->withHeaders($this->adminHeaders($admin))
            ->putJson("/api/v1/categories/{$category->uuid}", ['name' => 'Gigs'])
            ->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'Category not found']);

        $this->assertDatabaseHas('categories', ['uuid' => $category->uuid, 'name' => 'Concerts']);
    }

    #[Test]
    public function deleteCategoryReturnsNotFoundForUnknownUuid(): void
    {
        $admin = $this->makeAdmin($this->makeRole(['categories' => ['delete']]));

        $this->withHeaders($this->adminHeaders($admin))
            ->deleteJson('/api/v1/categories/' . Str::uuid())
            ->assertStatus(404)
            ->assertExactJson(['success' => false, 'message' => 'Category not found']);
    }

    #[Test]
    public function deleteCategoryInUseByEventsIsForbidden(): void
    {
        $admin = $this->makeAdmin($this->makeRole(['categories' => ['delete']]));
        $category = Category::create([
            'name' => 'Festivals',
            'code' => 'festivals',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);
        Event::create([
            'category_uuid' => $category->uuid,
            'event_name' => 'Gap Festival',
            'event_description' => 'Uses the category',
            'contact_email' => 'event@test.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'tags' => [],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
        ]);

        $this->withHeaders($this->adminHeaders($admin))
            ->deleteJson("/api/v1/categories/{$category->uuid}")
            ->assertStatus(403)
            ->assertExactJson([
                'success' => false,
                'message' => 'Cannot delete category that is being used by events.',
            ]);

        $this->assertDatabaseHas('categories', ['uuid' => $category->uuid, 'deleted_at' => null]);
    }
}
