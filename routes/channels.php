<?php

use App\Models\AdminUser;
use App\Models\ChatThread;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * Private chat channel, scoped to a single VenueInquiry thread.
 *
 * Authorization is intentionally type-aware because the app authenticates two
 * different models through separate JWT guards:
 *   - App\Models\User      -> the customer who owns the inquiry
 *   - App\Models\AdminUser -> a merchant belonging to the venue's organization
 */
Broadcast::channel('chat.thread.{threadUuid}', function ($user, string $threadUuid) {
    $thread = ChatThread::find($threadUuid);

    if (!$thread) {
        return false;
    }

    if ($user instanceof User) {
        return $thread->customer_uuid !== null
            && $thread->customer_uuid === $user->uuid;
    }

    if ($user instanceof AdminUser) {
        // Platform admins (no organization) can view every thread; merchants are
        // restricted to threads belonging to their own organization.
        if ($user->organization_uuid === null) {
            return true;
        }

        return $thread->organization_uuid !== null
            && $thread->organization_uuid === $user->organization_uuid;
    }

    return false;
});

/*
 * Personal notification channel for a customer. Receives a copy of every
 * message addressed to them so the inquiries list can update unread badges
 * in realtime without subscribing to each thread individually.
 */
Broadcast::channel('chat.user.{userUuid}', function ($user, string $userUuid) {
    return $user instanceof User && $user->uuid === $userUuid;
});

/*
 * Personal notification channel for a merchant organization. Any admin user
 * belonging to the organization (or a platform admin) may listen.
 */
Broadcast::channel('chat.org.{organizationUuid}', function ($user, string $organizationUuid) {
    if (!$user instanceof AdminUser) {
        return false;
    }

    return $user->organization_uuid === null
        || $user->organization_uuid === $organizationUuid;
});

/*
 * Personal in-app notification channel for an individual admin / merchant user.
 * Only the exact user may subscribe.
 */
Broadcast::channel('notifications.admin.{adminUserUuid}', function ($user, string $adminUserUuid) {
    return $user instanceof AdminUser && $user->uuid === $adminUserUuid;
});
