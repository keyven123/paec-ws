<?php

namespace Tests\Feature\Coverage\GapAuthMisc;

use App\Models\ChatThread;
use App\Models\PlatformNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ChatAndNotificationGapTest extends TestCase
{
    use GapAuthMiscFixtures;
    use RefreshDatabase;

    private function makeThread(array $attributes = []): ChatThread
    {
        return ChatThread::create(array_merge([
            'venue_inquiry_uuid' => (string) Str::uuid(),
            'venue_listing_uuid' => (string) Str::uuid(),
        ], $attributes));
    }

    private function makeNotification(User $user, array $attributes = []): PlatformNotification
    {
        return PlatformNotification::create(array_merge([
            'notifiable_type' => User::class,
            'notifiable_uuid' => $user->uuid,
            'type' => 'general',
            'title' => 'Hello',
            'body' => 'Body',
        ], $attributes));
    }

    #[Test]
    public function customerMarksOwnChatThreadRead(): void
    {
        $customer = $this->makeCustomer();
        $thread = $this->makeThread(['customer_uuid' => $customer->uuid]);

        $this->withHeaders($this->customerHeaders($customer))
            ->postJson("/api/v1/customer/chat/threads/{$thread->uuid}/read")
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $this->assertNotNull($thread->fresh()->customer_last_read_at);
        $this->assertNull($thread->fresh()->merchant_last_read_at);
    }

    #[Test]
    public function customerCannotMarkAnotherCustomersThreadRead(): void
    {
        $customer = $this->makeCustomer();
        $thread = $this->makeThread(['customer_uuid' => $this->makeCustomer()->uuid]);

        $this->withHeaders($this->customerHeaders($customer))
            ->postJson("/api/v1/customer/chat/threads/{$thread->uuid}/read")
            ->assertNotFound()
            ->assertExactJson(['message' => 'Chat thread not found.']);

        $this->assertNull($thread->fresh()->customer_last_read_at);
    }

    #[Test]
    public function customerMarkReadReturnsNotFoundForMissingThread(): void
    {
        $this->withHeaders($this->customerHeaders($this->makeCustomer()))
            ->postJson('/api/v1/customer/chat/threads/' . Str::uuid() . '/read')
            ->assertNotFound();
    }

    #[Test]
    public function platformAdminMarksAnyThreadReadAsMerchant(): void
    {
        $admin = $this->makeAdmin($this->makeRole(['venue-listings' => ['update']]));
        $thread = $this->makeThread(['organization_uuid' => $this->makeOrganization()->uuid]);

        $this->withHeaders($this->adminHeaders($admin))
            ->postJson("/api/v1/venue-listings/chat/threads/{$thread->uuid}/read")
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $this->assertNotNull($thread->fresh()->merchant_last_read_at);
        $this->assertNull($thread->fresh()->customer_last_read_at);
    }

    #[Test]
    public function merchantCannotMarkOtherOrganizationsThreadRead(): void
    {
        $organization = $this->makeOrganization();
        $admin = $this->makeAdmin(
            $this->makeRole(['venue-listings' => ['update']], false),
            ['organization_uuid' => $organization->uuid],
        );
        $thread = $this->makeThread(['organization_uuid' => $this->makeOrganization()->uuid]);

        $this->withHeaders($this->adminHeaders($admin))
            ->postJson("/api/v1/venue-listings/chat/threads/{$thread->uuid}/read")
            ->assertNotFound()
            ->assertExactJson(['message' => 'Chat thread not found.']);

        $this->assertNull($thread->fresh()->merchant_last_read_at);
    }

    #[Test]
    public function merchantMarkReadReturnsNotFoundForMissingThread(): void
    {
        $admin = $this->makeAdmin($this->makeRole(['venue-listings' => ['update']]));

        $this->withHeaders($this->adminHeaders($admin))
            ->postJson('/api/v1/venue-listings/chat/threads/' . Str::uuid() . '/read')
            ->assertNotFound();
    }

    #[Test]
    public function customerMarksOwnNotificationRead(): void
    {
        $customer = $this->makeCustomer();
        $notification = $this->makeNotification($customer);

        $this->withHeaders($this->customerHeaders($customer))
            ->postJson("/api/v1/customer/notifications/{$notification->uuid}/read")
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    #[Test]
    public function customerCannotMarkSomeoneElsesNotificationRead(): void
    {
        $customer = $this->makeCustomer();
        $notification = $this->makeNotification($this->makeCustomer());

        $this->withHeaders($this->customerHeaders($customer))
            ->postJson("/api/v1/customer/notifications/{$notification->uuid}/read")
            ->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
    }

    #[Test]
    public function customerMarksAllOwnNotificationsRead(): void
    {
        $customer = $this->makeCustomer();
        $mine = [$this->makeNotification($customer), $this->makeNotification($customer)];
        $other = $this->makeNotification($this->makeCustomer());

        $this->withHeaders($this->customerHeaders($customer))
            ->postJson('/api/v1/customer/notifications/read-all')
            ->assertOk()
            ->assertExactJson(['success' => true]);

        foreach ($mine as $notification) {
            $this->assertNotNull($notification->fresh()->read_at);
        }
        $this->assertNull($other->fresh()->read_at);
    }
}
