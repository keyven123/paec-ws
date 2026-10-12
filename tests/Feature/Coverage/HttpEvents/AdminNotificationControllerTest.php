<?php

namespace Tests\Feature\Coverage\HttpEvents;

use App\Models\AdminUser;
use App\Models\PlatformNotification;
use PHPUnit\Framework\Attributes\Test;

class AdminNotificationControllerTest extends HttpEventsTestCase
{
    private function notificationFor(AdminUser $admin, array $overrides = []): PlatformNotification
    {
        return PlatformNotification::create(array_merge([
            'notifiable_type' => AdminUser::class,
            'notifiable_uuid' => $admin->uuid,
            'type' => 'event_submitted',
            'title' => 'Event submitted',
            'body' => 'An event needs review',
            'read_at' => null,
        ], $overrides));
    }

    #[Test]
    public function markReadSetsReadAtOnOwnNotification(): void
    {
        [$admin, $token] = $this->createAdmin();
        $notification = $this->notificationFor($admin);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/v1/admin/notifications/' . $notification->uuid . '/read')
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    #[Test]
    public function markReadReturns404ForAnotherAdminsNotification(): void
    {
        [$admin, $token] = $this->createAdmin();
        [$other] = $this->createAdmin();
        $foreign = $this->notificationFor($other);

        auth('admin')->login($admin);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/v1/admin/notifications/' . $foreign->uuid . '/read')
            ->assertNotFound();

        $this->assertNull($foreign->fresh()->read_at);
    }

    #[Test]
    public function markAllReadOnlyMarksTheCurrentAdminsUnreadNotifications(): void
    {
        [$other] = $this->createAdmin();
        [$admin, $token] = $this->createAdmin();
        $first = $this->notificationFor($admin);
        $second = $this->notificationFor($admin, ['title' => 'Second']);
        $foreign = $this->notificationFor($other);

        $this->withHeaders($this->bearer($token))
            ->postJson('/api/v1/admin/notifications/read-all')
            ->assertOk()
            ->assertExactJson(['success' => true]);

        $this->assertNotNull($first->fresh()->read_at);
        $this->assertNotNull($second->fresh()->read_at);
        $this->assertNull($foreign->fresh()->read_at);

        $this->withHeaders($this->bearer($token))
            ->getJson('/api/v1/admin/notifications/unread-count')
            ->assertOk()
            ->assertJsonPath('unread_count', 0);
    }
}
