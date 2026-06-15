<?php

namespace App\Constants;

final class GeneralConstants
{
    const ROLES = [
        'SUPER_ADMIN' => ['name' => 'superadmin', 'is_admin' => true],
        'ADMIN' => ['name' => 'admin', 'is_admin' => true],
        'ORGANIZER' => ['name' => 'organizer', 'is_admin' => false],
        'SCANNER' => ['name' => 'scanner', 'is_admin' => false],
        'CUSTOMER' => ['name' => 'customer', 'is_admin' => false],
    ];

    const PERMISSION_LABEL = [
        'r' => 'view',
        'w' => 'create',
        'u' => 'update',
        'd' => 'delete',
        'e' => 'export',
        'i' => 'import',
        'x' => 'execute',
        'a' => 'add',
    ];

    const TIMEZONE = 'Asia/Manila';

    const PLACES = [
        'NEWPORT' => 'newport',
        'BSK_CLUB' => 'bsk-club',
        'NA' => 'na',
    ];

    const GENERAL_STATUSES = [
        'ACTIVE' => 'active',
        'INACTIVE' => 'inactive',
    ];

    const EVENT_STATUSES = [
        'DRAFT' => 'draft',
        'PENDING' => 'pending',
        'APPROVED' => 'approved',
        'PUBLISHED' => 'published',
        'CANCELLED' => 'cancelled',
        'COMPLETED' => 'completed',
        'REJECTED' => 'rejected',
    ];

    const TICKET_STATUSES = [
        'PENDING' => 'pending',
        'ACTIVE' => 'active',
        'INACTIVE' => 'inactive',
        'USED' => 'used',
        'EXPIRED' => 'expired',
        'TRANSFERRED' => 'transferred',
        'CANCELLED' => 'cancelled',
    ];

    const TICKET_COUPON_STATUSES = [
        'PENDING' => 'pending',
        'INACTIVE' => 'inactive',
        'ACTIVE' => 'active',
        'USED' => 'used',
        'CLAIMED' => 'claimed',
        'CANCELLED' => 'cancelled',
    ];

    const SCHEDULE_STATUSES = [
        'PUBLISHED' => 'published',
        'DRAFT' => 'draft',
        'CANCELLED' => 'cancelled',
    ];

    const ORGANIZER_STATUSES = [
        'PENDING' => 'pending',
        'APPROVED' => 'approved',
        'ONBOARDED' => 'onboarded',
        'REJECTED' => 'rejected',
    ];

    const AFFILIATE_STATUSES = [
        'NONE' => 'none',
        'APPROVED' => 'approved',
        'SUSPENDED' => 'suspended',
    ];

    const PAYMENT_PROVIDERS = [
        'PAYPAL' => 'paypal',
        'PAYMONGO' => 'paymongo',
        'FREE' => 'free',
    ];

    const MODEL = [
        'USER' => 'User',
        'ADMIN_USER' => 'AdminUser',
        'EVENT' => 'Event',
        'SCHEDULE' => 'Schedule',
        'ORGANIZATION' => 'Organization',
        'VENUE_LISTING' => 'VenueListing',
    ];

    const DISCOUNT_TYPES = [
        'PERCENTAGE' => 'percentage',
        'AMOUNT' => 'amount',
    ];
}
