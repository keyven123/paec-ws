<?php

namespace Tests\Unit;

use App\Services\Payments\PaymentStatusResolver;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaymentStatusResolverTest extends TestCase
{
    #[Test]
    public function it_resolves_success_statuses(): void
    {
        $this->assertSame(PaymentStatusResolver::RESOLUTION_PAID, PaymentStatusResolver::resolve('succeeded'));
        $this->assertSame(PaymentStatusResolver::RESOLUTION_PAID, PaymentStatusResolver::resolve('COMPLETED'));
        $this->assertSame(PaymentStatusResolver::RESOLUTION_PAID, PaymentStatusResolver::resolve('paid'));
    }

    #[Test]
    public function it_resolves_pending_statuses(): void
    {
        $this->assertSame(PaymentStatusResolver::RESOLUTION_PENDING, PaymentStatusResolver::resolve('pending'));
        $this->assertSame(PaymentStatusResolver::RESOLUTION_PENDING, PaymentStatusResolver::resolve('CREATED'));
        $this->assertSame(PaymentStatusResolver::RESOLUTION_PENDING, PaymentStatusResolver::resolve('APPROVED'));
        $this->assertSame(PaymentStatusResolver::RESOLUTION_PENDING, PaymentStatusResolver::resolve('active'));
    }

    #[Test]
    public function it_resolves_failed_statuses(): void
    {
        $this->assertSame(PaymentStatusResolver::RESOLUTION_FAILED, PaymentStatusResolver::resolve('failed'));
        $this->assertSame(PaymentStatusResolver::RESOLUTION_FAILED, PaymentStatusResolver::resolve('cancelled'));
        $this->assertSame(PaymentStatusResolver::RESOLUTION_FAILED, PaymentStatusResolver::resolve('expired'));
        $this->assertSame(PaymentStatusResolver::RESOLUTION_FAILED, PaymentStatusResolver::resolve('VOIDED'));
    }
}
