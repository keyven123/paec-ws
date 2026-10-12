<?php

namespace Tests\Feature\Coverage\RServicesJobs;

use App\Constants\GeneralConstants;
use App\Models\AffiliateConversion;
use App\Models\Event;
use App\Models\User;
use App\Services\AffiliateAttributionService;
use App\Services\AffiliatePartnerStatsService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\SeedsUserAffiliate;
use Tests\Feature\Coverage\RServicesJobs\Concerns\CreatesRServicesJobsFixtures;
use Tests\TestCase;

class AffiliateServicesCoverageTest extends TestCase
{
    use CreatesRServicesJobsFixtures;
    use RefreshDatabase;
    use SeedsUserAffiliate;

    private User $partner;

    private User $buyer;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $this->partner = $this->createRsjUser('Partner');
        $this->seedApprovedAffiliate($this->partner, 'RSJPARTNER');
        $this->buyer = $this->createRsjUser('Buyer');
        $this->event = $this->createRsjEvent([
            'affiliate_enabled' => true,
            'affiliate_commission_percent' => 10,
        ]);
    }

    private function referredTransaction(array $attributes = [])
    {
        return $this->createRsjTransaction($this->event, $this->buyer, array_merge([
            'affiliate_partner_uuid' => $this->partner->uuid,
        ], $attributes));
    }

    #[Test]
    public function affiliateProgramIsInactiveWhenEventDisablesIt(): void
    {
        $this->event->update(['affiliate_enabled' => false]);

        $this->assertFalse(AffiliateAttributionService::isAffiliateProgramActiveAt($this->event, now()));
    }

    #[Test]
    public function conversionIsSkippedWhenPaidAfterProgramEnded(): void
    {
        $this->event->update(['affiliate_ends_at' => '2026-01-31']);
        $transaction = $this->referredTransaction(['paid_at' => Carbon::parse('2026-02-15 10:00:00')]);

        AffiliateAttributionService::recordConversionFromPaidTransaction($transaction);

        $this->assertDatabaseCount('affiliate_conversions', 0);
    }

    #[Test]
    public function conversionIsSkippedWithoutPositiveCommissionPercent(): void
    {
        $this->event->update(['affiliate_commission_percent' => 0]);
        $transaction = $this->referredTransaction();

        AffiliateAttributionService::recordConversionFromPaidTransaction($transaction);

        $this->assertDatabaseCount('affiliate_conversions', 0);
    }

    #[Test]
    public function conversionIsSkippedWhenPartnerIsNotApproved(): void
    {
        $this->partner->userAffiliate()->update(['affiliate_status' => GeneralConstants::AFFILIATE_STATUSES['SUSPENDED']]);
        $transaction = $this->referredTransaction();

        AffiliateAttributionService::recordConversionFromPaidTransaction($transaction);

        $this->assertDatabaseCount('affiliate_conversions', 0);
    }

    #[Test]
    public function conversionIsSkippedWhenNetSellingBaseIsZero(): void
    {
        $transaction = $this->referredTransaction(['total_amount' => 0, 'sub_total' => 0]);

        AffiliateAttributionService::recordConversionFromPaidTransaction($transaction);

        $this->assertDatabaseCount('affiliate_conversions', 0);
    }

    #[Test]
    public function conversionIsSkippedWhenCommissionRoundsToZero(): void
    {
        $transaction = $this->referredTransaction(['total_amount' => 0.04, 'sub_total' => 0.04]);

        AffiliateAttributionService::recordConversionFromPaidTransaction($transaction);

        $this->assertDatabaseCount('affiliate_conversions', 0);
    }

    #[Test]
    public function conversionPersistenceFailureIsLogged(): void
    {
        AffiliateConversion::creating(function () {
            throw new \RuntimeException('ledger offline');
        });
        Log::spy();
        $transaction = $this->referredTransaction();

        AffiliateAttributionService::recordConversionFromPaidTransaction($transaction);

        $this->assertDatabaseCount('affiliate_conversions', 0);
        Log::shouldHaveReceived('error')->once()->with('Affiliate conversion recording failed', [
            'transaction_uuid' => $transaction->uuid,
            'error' => 'ledger offline',
        ]);
    }

    #[Test]
    public function maturedCommissionNetForPartnerOnlyCountsMaturedRows(): void
    {
        $old = $this->referredTransaction();
        $recent = $this->referredTransaction();
        foreach ([[$old, 100, '2026-01-02 10:00:00'], [$recent, 50, '2026-03-20 10:00:00']] as [$tx, $amount, $createdAt]) {
            $row = AffiliateConversion::create([
                'partner_user_uuid' => $this->partner->uuid,
                'transaction_uuid' => $tx->uuid,
                'entry_type' => AffiliateConversion::ENTRY_TYPE_CREDIT,
                'event_uuid' => $this->event->uuid,
                'order_total' => $amount * 10,
                'commission_percent' => 10,
                'commission_amount' => $amount,
            ]);
            $row->forceFill(['created_at' => Carbon::parse($createdAt)])->saveQuietly();
        }

        $net = AffiliatePartnerStatsService::maturedCommissionNetForPartner(
            $this->partner->uuid,
            Carbon::parse('2026-03-25')->startOfDay(),
        );

        $this->assertSame(90.0, $net);
    }
}
