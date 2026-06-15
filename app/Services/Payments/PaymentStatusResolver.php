<?php

namespace App\Services\Payments;

class PaymentStatusResolver
{
    private const SUCCESS_STATUSES = [
        'succeeded',
        'completed',
        'paid',
    ];

    private const FAILED_STATUSES = [
        'failed',
        'cancelled',
        'canceled',
        'expired',
        'voided',
        'denied',
        'unpaid',
    ];

    private const PENDING_STATUSES = [
        'pending',
        'created',
        'approved',
        'active',
        'processing',
        'awaiting_payment_method',
        'awaiting_next_action',
    ];

    public const RESOLUTION_PAID = 'paid';
    public const RESOLUTION_PENDING = 'pending';
    public const RESOLUTION_FAILED = 'failed';

    public static function resolve(string $status): string
    {
        $normalized = strtolower(trim($status));

        if (in_array($normalized, self::SUCCESS_STATUSES, true)) {
            return self::RESOLUTION_PAID;
        }

        if (in_array($normalized, self::FAILED_STATUSES, true)) {
            return self::RESOLUTION_FAILED;
        }

        if (in_array($normalized, self::PENDING_STATUSES, true)) {
            return self::RESOLUTION_PENDING;
        }

        return self::RESOLUTION_PENDING;
    }

    public static function isPaid(string $status): bool
    {
        return self::resolve($status) === self::RESOLUTION_PAID;
    }

    public static function isPending(string $status): bool
    {
        return self::resolve($status) === self::RESOLUTION_PENDING;
    }

    public static function isFailed(string $status): bool
    {
        return self::resolve($status) === self::RESOLUTION_FAILED;
    }
}
