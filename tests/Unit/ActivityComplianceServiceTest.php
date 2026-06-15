<?php

namespace Tests\Unit;

use App\Constants\GeneralConstants;
use App\Models\ActivityCompliance;
use App\Models\Dataset;
use App\Models\Event;
use App\Models\TransactionCompliance;
use App\Services\ActivityComplianceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ActivityComplianceServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_apply_to_checkout_adds_percentage_fees_on_net_subtotal(): void
    {
        $event = Event::factory()->create();

        ActivityCompliance::query()->create([
            'activityable_type' => 'event',
            'activityable_id' => $event->uuid,
            'label' => 'VAT',
            'percentage' => 12,
            'amount_type' => ActivityCompliance::AMOUNT_TYPE['PERCENTAGE'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $result = ActivityComplianceService::applyToCheckoutAmounts($event, [
            'sub_total' => 699,
            'discount' => 0,
            'promo_code_discount' => 0,
        ]);

        $this->assertSame(83.88, $result['tax_amount']);
        $this->assertSame(782.88, $result['total_amount']);
        $this->assertSame('Included in price: VAT 12%', $result['included_note']);
    }

    public function test_stacked_active_percentages_sum_on_same_base(): void
    {
        $event = Event::factory()->create();

        foreach ([
            ['label' => 'City Tax', 'percentage' => 3],
            ['label' => 'Service Charge', 'percentage' => 10],
            ['label' => 'VAT', 'percentage' => 12],
        ] as $row) {
            ActivityCompliance::query()->create([
                'activityable_type' => 'event',
                'activityable_id' => $event->uuid,
                'label' => $row['label'],
                'percentage' => $row['percentage'],
                'amount_type' => ActivityCompliance::AMOUNT_TYPE['PERCENTAGE'],
                'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            ]);
        }

        $result = ActivityComplianceService::applyToCheckoutAmounts($event, [
            'sub_total' => 100,
            'discount' => 0,
            'promo_code_discount' => 0,
        ]);

        $this->assertSame(25.0, $result['tax_amount']);
        $this->assertSame(125.0, $result['total_amount']);
        $this->assertStringContainsString('City Tax 3%', $result['included_note']);
        $this->assertStringContainsString('Service Charge 10%', $result['included_note']);
        $this->assertStringContainsString('VAT 12%', $result['included_note']);
    }

    public function test_included_note_uses_fixed_amount_for_fixed_rules(): void
    {
        $event = Event::factory()->create();

        ActivityCompliance::query()->create([
            'activityable_type' => 'event',
            'activityable_id' => $event->uuid,
            'label' => 'Service Fee',
            'percentage' => 0,
            'fixed_amount' => 50,
            'amount_type' => ActivityCompliance::AMOUNT_TYPE['FIXED'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        ActivityCompliance::query()->create([
            'activityable_type' => 'event',
            'activityable_id' => $event->uuid,
            'label' => 'VAT',
            'percentage' => 12,
            'amount_type' => ActivityCompliance::AMOUNT_TYPE['PERCENTAGE'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $result = ActivityComplianceService::applyToCheckoutAmounts($event, [
            'sub_total' => 100,
            'discount' => 0,
            'promo_code_discount' => 0,
        ]);

        $this->assertStringContainsString('Service Fee 50', $result['included_note']);
        $this->assertStringContainsString('VAT 12%', $result['included_note']);
        $this->assertStringNotContainsString('Service Fee 0%', $result['included_note']);
    }

    public function test_inactive_rules_do_not_affect_totals(): void
    {
        $event = Event::factory()->create();

        ActivityCompliance::query()->create([
            'activityable_type' => 'event',
            'activityable_id' => $event->uuid,
            'label' => 'VAT',
            'percentage' => 12,
            'amount_type' => ActivityCompliance::AMOUNT_TYPE['PERCENTAGE'],
            'status' => GeneralConstants::GENERAL_STATUSES['INACTIVE'],
        ]);

        $result = ActivityComplianceService::applyToCheckoutAmounts($event, [
            'sub_total' => 699,
            'discount' => 0,
            'promo_code_discount' => 0,
        ]);

        $this->assertSame(0.0, $result['tax_amount']);
        $this->assertSame(699.0, $result['total_amount']);
        $this->assertNull($result['included_note']);
    }

    public function test_assert_active_percentage_total_within_limit(): void
    {
        $event = Event::factory()->create();

        ActivityCompliance::query()->create([
            'activityable_type' => 'event',
            'activityable_id' => $event->uuid,
            'label' => 'VAT',
            'percentage' => 60,
            'amount_type' => ActivityCompliance::AMOUNT_TYPE['PERCENTAGE'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        ActivityCompliance::query()->create([
            'activityable_type' => 'event',
            'activityable_id' => $event->uuid,
            'label' => 'Service Charge',
            'percentage' => 50,
            'amount_type' => ActivityCompliance::AMOUNT_TYPE['PERCENTAGE'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
        ]);

        $this->expectException(ValidationException::class);
        ActivityComplianceService::assertActivePercentageTotalWithinLimit($event);
    }

    public function test_included_note_from_snapshots_dedupes_merchandise_and_markup_rows(): void
    {
        $event = Event::factory()->create();

        $rules = collect([
            ActivityCompliance::query()->create([
                'activityable_type' => 'event',
                'activityable_id' => $event->uuid,
                'label' => 'City Tax',
                'percentage' => 3,
                'amount_type' => ActivityCompliance::AMOUNT_TYPE['PERCENTAGE'],
                'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            ]),
            ActivityCompliance::query()->create([
                'activityable_type' => 'event',
                'activityable_id' => $event->uuid,
                'label' => 'VAT',
                'percentage' => 12,
                'amount_type' => ActivityCompliance::AMOUNT_TYPE['PERCENTAGE'],
                'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            ]),
        ]);

        $snapshots = $rules->flatMap(function (ActivityCompliance $rule) {
            return collect([
                TransactionCompliance::APPLIES_TO_MERCHANDISE,
                TransactionCompliance::APPLIES_TO_MARKUP,
            ])->map(function (string $appliesTo) use ($rule) {
                $row = new TransactionCompliance([
                    'activity_compliance_uuid' => $rule->uuid,
                    'percentage' => $rule->percentage,
                    'amount' => 10,
                    'applies_to' => $appliesTo,
                ]);
                $row->setRelation('activityCompliance', $rule);

                return $row;
            });
        });

        $note = ActivityComplianceService::buildIncludedNoteFromSnapshots($snapshots);

        $this->assertSame('Included in price: City Tax 3%, VAT 12%', $note);
    }

    public function test_provision_defaults_from_dataset(): void
    {
        Dataset::query()->create([
            'name' => 'activity_compliance',
            'description' => 'Compliance taxes and fees',
            'value' => json_encode([
                ['label' => 'VAT', 'percentage' => 12, 'amount_type' => 'percentage', 'status' => 'inactive'],
                ['label' => 'City Tax', 'percentage' => 0, 'amount_type' => 'percentage', 'status' => 'inactive'],
            ]),
        ]);

        $event = Event::factory()->create();
        ActivityComplianceService::provisionDefaultsForEvent($event);

        $this->assertDatabaseHas('activity_compliances', [
            'activityable_type' => 'event',
            'activityable_id' => $event->uuid,
            'label' => 'VAT',
            'percentage' => 12,
        ]);
        $this->assertDatabaseHas('activity_compliances', [
            'activityable_type' => 'event',
            'activityable_id' => $event->uuid,
            'label' => 'City Tax',
        ]);
    }
}
