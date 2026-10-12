<?php

namespace Tests\Feature\Coverage\Exceptions;

use App\Constants\GeneralConstants;
use App\Exceptions\AccountInactiveException;
use App\Exceptions\ActionNotAllowedException;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\GrantsAdminPermissions;
use Tests\TestCase;

class HttpExceptionsCoverageTest extends TestCase
{
    use GrantsAdminPermissions;
    use RefreshDatabase;

    #[Test]
    public function accountInactiveDefaultsToA400WithAGenericMessage(): void
    {
        $exception = new AccountInactiveException();

        $this->assertInstanceOf(HttpException::class, $exception);
        $this->assertSame(400, $exception->getStatusCode());
        $this->assertSame('Account is inactive', $exception->getMessage());
    }

    #[Test]
    public function accountInactiveAcceptsACustomMessageAndStatus(): void
    {
        $exception = new AccountInactiveException('Your account was suspended', 423);

        $this->assertSame(423, $exception->getStatusCode());
        $this->assertSame('Your account was suspended', $exception->getMessage());
    }

    #[Test]
    public function actionNotAllowedDefaultsToA403(): void
    {
        $exception = new ActionNotAllowedException();

        $this->assertInstanceOf(HttpException::class, $exception);
        $this->assertSame(403, $exception->getStatusCode());
        $this->assertSame('Action not allowed', $exception->getMessage());
    }

    #[Test]
    public function deletingATicketTypeWithSoldTicketsIsRejectedWith403(): void
    {
        $eventTicket = $this->makeEventTicket();
        DB::table('event_tickets')->where('uuid', $eventTicket->uuid)->update(['sold_ticket' => 3]);

        $this->withHeaders($this->adminHeaders())
            ->deleteJson('/api/v1/event-tickets/' . $eventTicket->uuid)
            ->assertStatus(403)
            ->assertJsonPath('message', 'Cannot delete event ticket with sold tickets.');

        $this->assertNotSoftDeleted('event_tickets', ['uuid' => $eventTicket->uuid]);
    }

    #[Test]
    public function deletingAnUnsoldTicketTypeSucceeds(): void
    {
        $eventTicket = $this->makeEventTicket();

        $this->withHeaders($this->adminHeaders())
            ->deleteJson('/api/v1/event-tickets/' . $eventTicket->uuid)
            ->assertNoContent();

        $this->assertSoftDeleted('event_tickets', ['uuid' => $eventTicket->uuid]);
    }

    /**
     * @return array<string, string>
     */
    private function adminHeaders(): array
    {
        $role = Role::create([
            'name' => 'Admin',
            'code' => GeneralConstants::ROLES['ADMIN']['name'],
            'is_admin' => true,
        ]);
        $this->grantRolePermissions($role, ['event-tickets' => ['delete']]);

        $admin = AdminUser::create([
            'role_uuid' => $role->uuid,
            'email' => 'exceptions-coverage@test.com',
            'password' => 'password123',
            'first_name' => 'Admin',
            'last_name' => 'Coverage',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);

        return ['Authorization' => 'Bearer ' . auth('admin')->login($admin)];
    }

    private function makeEventTicket(): EventTicket
    {
        $event = Event::create([
            'event_name' => 'Exceptions Coverage Event',
            'event_description' => 'Ticket type deletion',
            'contact_email' => 'event@example.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'tags' => [],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
        ]);

        return EventTicket::create([
            'event_uuid' => $event->uuid,
            'code' => 'T-' . Str::uuid()->toString(),
            'name' => 'General',
            'description' => 'Ticket type',
            'price' => 100,
            'is_bundle' => false,
        ]);
    }
}
