<?php

namespace Tests\Feature\Coverage\OrganizerServices;

use App\Constants\GeneralConstants;
use App\Models\AffiliateConversion;
use App\Models\Event;
use App\Models\MerchantPayoutRequest;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\TransactionCommission;
use App\Models\TransactionOrder;
use App\Services\AffiliateCommissionAvailabilityService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesMerchantPayoutTestData;

/**
 * Shared fixture builders for the Services/Organizer tests.
 */
trait OrganizerServiceFixtures
{
    use CreatesMerchantPayoutTestData;

    protected function tz(): string
    {
        return AffiliateCommissionAvailabilityService::timezone();
    }

    protected function at(string $datetime): Carbon
    {
        return Carbon::parse($datetime, $this->tz());
    }

    protected function freezeAt(string $datetime): void
    {
        Carbon::setTestNow($this->at($datetime));
    }

    protected function makeOrg(string $name, float|int|null $commission = 10): Organization
    {
        return Organization::create([
            'name' => $name,
            'representative_first_name' => 'Rep',
            'representative_last_name' => 'Resentative',
            'email' => Str::slug($name) . '-' . Str::random(5) . '@example.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'],
            'commission_percentage' => $commission,
        ]);
    }

    protected function makeEvent(Organization $org, string $name): Event
    {
        return Event::create([
            'event_name' => $name,
            'event_description' => 'Test',
            'contact_email' => 'e@example.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'organization_uuid' => $org->uuid,
        ]);
    }

    /**
     * Paid transaction without order lines (legacy export line: sub_total − discounts).
     *
     * @param  array<string, mixed>  $attrs
     */
    protected function makePaidTx(Organization $org, ?Event $event, float $subTotal, string $paidAt, array $attrs = []): Transaction
    {
        return Transaction::create(array_merge([
            'user_uuid' => (string) Str::uuid(),
            'event_uuid' => $event?->uuid,
            'organization_uuid' => $org->uuid,
            'order_number' => 'ORD-ORG-' . Str::random(12),
            'sub_total' => $subTotal,
            'tax_amount' => 0,
            'discount' => 0,
            'total_amount' => $subTotal,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
            'paid_at' => $this->at($paidAt),
        ], $attrs));
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    protected function addOrder(Transaction $tx, float $price, int $quantity, array $attrs = []): TransactionOrder
    {
        return TransactionOrder::create(array_merge([
            'user_uuid' => $tx->user_uuid,
            'transaction_uuid' => $tx->uuid,
            'event_ticket_uuid' => (string) Str::uuid(),
            'quantity' => $quantity,
            'price' => $price,
            'discount' => 0,
            'markup' => 0,
            'markup_discount' => 0,
            'total_amount' => round($price * $quantity, 2),
        ], $attrs));
    }

    protected function addAffiliateCommission(Transaction $tx, float $amount): AffiliateConversion
    {
        return AffiliateConversion::create([
            'partner_user_uuid' => (string) Str::uuid(),
            'transaction_uuid' => $tx->uuid,
            'entry_type' => AffiliateConversion::ENTRY_TYPE_CREDIT,
            'event_uuid' => $tx->event_uuid,
            'order_total' => $tx->total_amount,
            'commission_percent' => 10,
            'commission_amount' => $amount,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    protected function makePayout(Organization $org, ?Event $event, float $amount, string $status, array $attrs = []): MerchantPayoutRequest
    {
        return MerchantPayoutRequest::query()->create($this->merchantPayoutAttributes($org, array_merge([
            'event_uuid' => $event?->uuid,
            'amount_requested' => $amount,
            'status' => $status,
        ], $attrs)));
    }

    /**
     * Ledger rows are written with forceCreate because tax_and_fees is not mass assignable.
     *
     * @param  array<string, mixed>  $attrs
     */
    protected function ledgerRow(Organization $org, ?Event $event, string $datePaid, float $gross, float $commission, float $net, float $taxAndFees = 0, array $attrs = []): TransactionCommission
    {
        $uuid = (string) Str::uuid();

        return TransactionCommission::forceCreate(array_merge([
            'accountable_type' => Transaction::class,
            'accountable_id' => $uuid,
            'transaction_uuid' => $uuid,
            'event_uuid' => $event?->uuid,
            'organization_uuid' => $org->uuid,
            'gross_amount' => $gross,
            'markup_amount' => 0,
            'tax_and_fees' => $taxAndFees,
            'net_amount' => $net,
            'ticketoc_commission_percent' => 10,
            'ticketoc_commission' => $commission,
            'ticketoc_net_commission' => $commission,
            'agent_commission_percent' => 0,
            'agent_commission' => 0,
            'payment_provider' => 'paypal',
            'payment_gateway_commission_percent' => 0,
            'payment_gateway_fixed_fee' => 0,
            'payment_gateway_commission' => 0,
            'currency' => 'PHP',
            'transaction_type' => TransactionCommission::TYPE['TRANSACTION'],
            'date_paid' => $this->at($datePaid),
        ], $attrs));
    }

    /**
     * @param  array<string, mixed>  $columns
     */
    protected function setTxColumns(Transaction $tx, array $columns): void
    {
        DB::table('transactions')->where('uuid', $tx->uuid)->update($columns);
    }
}
