<?php

namespace Tests\Unit\Coverage\RPayments;

use App\Services\Payments\PaymentGatewayEnvironmentDetector;
use App\Services\Payments\PaymentServiceFactory;
use App\Services\Payments\PaymentStatusResolver;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PaymentHelpersCoverageTest extends TestCase
{
    #[Test]
    public function factoryListsAvailableProviders(): void
    {
        $this->assertSame(['paypal', 'paymongo', 'free'], PaymentServiceFactory::getAvailableProviders());
    }

    #[Test]
    public function factoryChecksProviderSupportCaseInsensitively(): void
    {
        $this->assertTrue(PaymentServiceFactory::isProviderSupported('PayMongo'));
        $this->assertTrue(PaymentServiceFactory::isProviderSupported('paypal'));
        $this->assertFalse(PaymentServiceFactory::isProviderSupported('stripe'));
    }

    #[Test]
    public function resolverTreatsUnknownStatusAsPending(): void
    {
        $this->assertSame(PaymentStatusResolver::RESOLUTION_PENDING, PaymentStatusResolver::resolve('something_new'));
    }

    #[Test]
    public function resolverPredicatesMatchResolution(): void
    {
        $this->assertTrue(PaymentStatusResolver::isPaid(' COMPLETED '));
        $this->assertFalse(PaymentStatusResolver::isPaid('pending'));

        $this->assertTrue(PaymentStatusResolver::isPending('awaiting_next_action'));
        $this->assertFalse(PaymentStatusResolver::isPending('paid'));

        $this->assertTrue(PaymentStatusResolver::isFailed('Canceled'));
        $this->assertFalse(PaymentStatusResolver::isFailed('succeeded'));
    }

    #[Test]
    public function paypalDetectionReturnsNullWhenSandboxHasServerErrorAndLiveRejects(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'api-m.sandbox.paypal.com/v1/oauth2/token' => Http::response(['name' => 'INTERNAL_SERVICE_ERROR'], 503),
            'api-m.paypal.com/v1/oauth2/token' => Http::response(['error' => 'invalid_client'], 401),
        ]);

        $this->assertNull((new PaymentGatewayEnvironmentDetector())->detect('paypal', 'client-id', 'secret'));
        Http::assertSentCount(2);
    }
}
