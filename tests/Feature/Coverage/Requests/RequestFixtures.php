<?php

namespace Tests\Feature\Coverage\Requests;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\EventSection;
use App\Models\EventTicket;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Schedule;
use App\Models\ScheduleTime;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Venue;
use App\Models\VenueSeat;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

trait RequestFixtures
{
    protected ?FormRequest $lastRequest = null;

    /**
     * Resolve a form request the same way the container does for a controller action
     * (prepareForValidation, authorize, rules, withValidator/after) and return the errors.
     *
     * @param  class-string<FormRequest>  $class
     * @param  array<string, mixed>  $routeParameters
     * @return array<string, list<string>>
     */
    protected function validateFormRequest(
        string $class,
        array $data = [],
        array $routeParameters = [],
        array $files = [],
        string $method = 'POST',
    ): array {
        /** @var FormRequest $request */
        $request = $class::create('/coverage/requests', $method, $data, [], $files);
        $request->setContainer($this->app)->setRedirector($this->app->make(Redirector::class));

        $route = (new Route($method, 'coverage/requests', fn () => null))->bind($request);
        foreach ($routeParameters as $key => $value) {
            $route->setParameter($key, $value);
        }
        $request->setRouteResolver(fn () => $route);
        $request->setUserResolver(fn ($guard = null) => $this->app['auth']->guard($guard)->user());

        $this->lastRequest = $request;

        try {
            $request->validateResolved();
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    }

    protected function assertFormRequestPasses(string $class, array $data = [], array $routeParameters = [], array $files = [], string $method = 'POST'): FormRequest
    {
        $errors = $this->validateFormRequest($class, $data, $routeParameters, $files, $method);
        $this->assertSame([], $errors, 'Expected the form request to pass validation.');

        return $this->lastRequest;
    }

    protected function assertFormRequestError(array $errors, string $key, ?string $message = null): void
    {
        $this->assertArrayHasKey($key, $errors, 'Missing validation error for ' . $key . '. Got: ' . json_encode(array_keys($errors)));
        if ($message !== null) {
            $this->assertContains($message, $errors[$key]);
        }
    }

    protected function makeRole(string $code, bool $isAdmin = true, ?string $organizationUuid = null): Role
    {
        return Role::firstOrCreate(
            ['code' => $code, 'organization_uuid' => $organizationUuid],
            ['name' => Str::headline($code), 'is_admin' => $isAdmin],
        );
    }

    protected function customerRole(): Role
    {
        return $this->makeRole(GeneralConstants::ROLES['CUSTOMER']['name'], false);
    }

    protected function makeAdmin(array $attributes = []): AdminUser
    {
        return AdminUser::create(array_merge([
            'role_uuid' => $this->makeRole(GeneralConstants::ROLES['ADMIN']['name'])->uuid,
            'email' => 'admin-' . Str::lower(Str::random(8)) . '@test.com',
            'password' => 'password123',
            'first_name' => 'Req',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ], $attributes));
    }

    protected function makeCustomer(array $attributes = []): User
    {
        return User::create(array_merge([
            'role_uuid' => $this->customerRole()->uuid,
            'email' => 'customer-' . Str::lower(Str::random(8)) . '@test.com',
            'password' => 'password123',
            'first_name' => 'Req',
            'last_name' => 'Customer',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ], $attributes));
    }

    protected function makeOrganization(array $attributes = []): Organization
    {
        return Organization::factory()->create($attributes);
    }

    protected function makeEvent(array $attributes = []): Event
    {
        return Event::create(array_merge([
            'event_name' => 'Request Event ' . Str::random(6),
            'event_description' => 'Request coverage event',
            'contact_email' => 'event@test.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
        ], $attributes));
    }

    protected function amusementSection(): EventSection
    {
        return EventSection::firstOrCreate(
            ['name' => EventSection::AMUSEMENT_SECTION],
            ['title' => 'Amusements', 'description' => 'Amusements', 'display_order' => 1, 'is_hidden' => true],
        );
    }

    protected function makeAmusementEvent(array $attributes = []): Event
    {
        return $this->makeEvent(array_merge([
            'event_section_uuid' => $this->amusementSection()->uuid,
            'event_type' => Event::EVENT_TYPES['DAILY'],
            'schedule_type' => Event::SCHEDULE_TYPES['DAILY'],
        ], $attributes));
    }

    protected function makeEventTicket(Event $event, array $attributes = []): EventTicket
    {
        return EventTicket::create(array_merge([
            'event_uuid' => $event->uuid,
            'code' => 'T-' . Str::lower(Str::random(6)),
            'name' => 'General',
            'description' => 'General admission',
            'price' => 100,
            'max_ticket' => 100,
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ], $attributes));
    }

    protected function makeSchedule(Event $event, array $attributes = []): Schedule
    {
        return Schedule::create(array_merge([
            'event_uuid' => $event->uuid,
            'date_from' => now()->addDays(5)->toDateString(),
            'date_to' => now()->addDays(5)->toDateString(),
            'status' => GeneralConstants::SCHEDULE_STATUSES['PUBLISHED'],
        ], $attributes));
    }

    protected function makeScheduleTime(Schedule $schedule, array $attributes = []): ScheduleTime
    {
        return ScheduleTime::create(array_merge([
            'schedule_uuid' => $schedule->uuid,
            'time_start' => '10:00:00',
            'time_end' => '12:00:00',
            'status' => GeneralConstants::SCHEDULE_STATUSES['PUBLISHED'],
        ], $attributes));
    }

    protected function makeTransaction(User $user, Event $event, array $attributes = []): Transaction
    {
        return Transaction::create(array_merge([
            'user_uuid' => $user->uuid,
            'event_uuid' => $event->uuid,
            'payment_method' => 'card',
            'order_number' => 'ORD-' . Str::upper(Str::random(10)),
            'total_amount' => 100,
            'sub_total' => 100,
            'tax_amount' => 0,
            'discount' => 0,
            'status' => 'completed',
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => 'confirmed',
        ], $attributes));
    }

    protected function makeTicket(Transaction $transaction, EventTicket $eventTicket, array $attributes = []): Ticket
    {
        return Ticket::create(array_merge([
            'user_uuid' => $transaction->user_uuid,
            'transaction_uuid' => $transaction->uuid,
            'event_uuid' => $transaction->event_uuid,
            'event_ticket_uuid' => $eventTicket->uuid,
            'ticket_number' => 'T-' . Str::upper(Str::random(8)),
            'attendee_name' => 'Attendee',
            'attendee_email' => 'attendee@test.com',
            'qr_code' => 'QR-' . Str::upper(Str::random(10)),
            'status' => GeneralConstants::TICKET_STATUSES['ACTIVE'],
            'price' => 100,
            'discount' => 0,
        ], $attributes));
    }

    protected function makeVenue(array $attributes = []): Venue
    {
        return Venue::create(array_merge([
            'name' => 'Request Venue ' . Str::random(4),
            'code' => 'venue-' . Str::lower(Str::random(6)),
            'type' => Venue::TYPES['OTHERS'],
        ], $attributes));
    }

    protected function makeVenueSeat(Venue $venue, array $attributes = []): VenueSeat
    {
        return VenueSeat::create(array_merge([
            'venue_uuid' => $venue->uuid,
            'col' => 1,
            'row' => 'A',
            'seat_no' => 1,
            'category' => 'gold',
            'color' => 'gold',
            'status' => 'active',
        ], $attributes));
    }
}
