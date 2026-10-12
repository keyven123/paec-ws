<?php

namespace Tests\Feature\Coverage\RModelsCore;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\Role;
use Illuminate\Support\Str;
use Tests\Feature\Coverage\Commands\CommandFixtures;

trait RModelsCoreFixtures
{
    use CommandFixtures;

    protected function makeEvent(array $attributes = []): Event
    {
        return Event::create(array_merge([
            'event_name' => 'Models Core Event ' . Str::random(6),
            'event_description' => 'Model coverage',
            'contact_email' => 'event@example.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'tags' => [],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
        ], $attributes));
    }

    protected function makeAdmin(bool $isAdmin, ?string $organizationUuid = null): AdminUser
    {
        $role = Role::create([
            'name' => 'Models Core Role ' . Str::random(4),
            'code' => 'models-core-' . Str::lower(Str::random(6)),
            'is_admin' => $isAdmin,
        ]);

        return AdminUser::create([
            'role_uuid' => $role->uuid,
            'organization_uuid' => $organizationUuid,
            'email' => 'models-core-' . Str::lower(Str::random(8)) . '@test.com',
            'password' => 'password123',
            'first_name' => 'Models',
            'last_name' => 'Core',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);
    }

    /**
     * @param  iterable<\Illuminate\Database\Eloquent\Model>  $models
     * @return array<int, string>
     */
    protected function uuids(iterable $models): array
    {
        return collect($models)->pluck('uuid')->sort()->values()->all();
    }

    /**
     * @param  array<int, \Illuminate\Database\Eloquent\Model>  $expected
     * @param  iterable<\Illuminate\Database\Eloquent\Model>  $actual
     */
    protected function assertSameModels(array $expected, iterable $actual): void
    {
        $this->assertSame($this->uuids($expected), $this->uuids($actual));
    }
}
