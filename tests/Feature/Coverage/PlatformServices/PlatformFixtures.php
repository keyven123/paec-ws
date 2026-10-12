<?php

namespace Tests\Feature\Coverage\PlatformServices;

use App\Constants\GeneralConstants;
use App\Models\Dataset;
use App\Models\Event;
use App\Models\EventSection;
use App\Models\Organization;
use App\Models\Schedule;
use App\Models\Transaction;
use App\Models\TransactionCommission;
use App\Models\TransactionOrder;
use App\Services\AffiliateCommissionAvailabilityService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shared fixture builders for the Services/Platform reporting tests.
 */
trait PlatformFixtures
{
    /**
     * The P&L services use MySQL's DATE_FORMAT(); SQLite has no equivalent,
     * so the in-memory test connection gets a small userland implementation.
     */
    protected function registerMysqlDateFormat(): void
    {
        DB::connection()->getPdo()->sqliteCreateFunction(
            'DATE_FORMAT',
            static function ($value, $format) {
                if ($value === null) {
                    return null;
                }
                $phpFormat = strtr((string) $format, [
                    '%Y' => 'Y',
                    '%m' => 'm',
                    '%d' => 'd',
                    '%H' => 'H',
                    '%i' => 'i',
                    '%s' => 's',
                ]);

                return date($phpFormat, strtotime((string) $value));
            },
            2
        );
    }

    protected function tz(): string
    {
        return AffiliateCommissionAvailabilityService::timezone();
    }

    protected function at(string $datetime): Carbon
    {
        return Carbon::parse($datetime, $this->tz());
    }

    protected function seedRates(float|string $merchantPercent = 10): void
    {
        Dataset::create(['name' => 'merchant_commission_percentage', 'value' => (string) $merchantPercent]);
        Dataset::create([
            'name' => 'paymongo_payment_rates',
            'value' => json_encode([
                ['name' => 'qr_ph', 'value' => 1.5],
                ['name' => 'card', 'value' => 3.0],
                ['name' => 'gcash', 'value' => 2.0],
                ['name' => 'dob', 'value' => 1.0],
                ['name' => 'dob_fixed_minimum', 'value' => 15],
            ]),
        ]);
        Dataset::create([
            'name' => 'paypal_payment_rates',
            'value' => json_encode([
                ['name' => 'paypal_fee', 'value' => 4.0],
                ['name' => 'additional_fee', 'value' => 10],
            ]),
        ]);
    }

    protected function makeOrg(string $name, float|int|null $commission = null): Organization
    {
        return Organization::create([
            'name' => $name,
            'representative_first_name' => 'Rep',
            'representative_last_name' => 'Resentative',
            'email' => Str::slug($name) . '-' . Str::random(5) . '@example.com',
            'status' => GeneralConstants::ORGANIZER_STATUSES['APPROVED'] ?? 'approved',
            'commission_percentage' => $commission,
        ]);
    }

    protected function makeSection(string $name): EventSection
    {
        return EventSection::create([
            'name' => $name,
            'title' => ucfirst($name),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    protected function makeEvent(?Organization $org, ?EventSection $section, array $attrs = []): Event
    {
        return Event::create(array_merge([
            'event_name' => 'Event ' . Str::random(4),
            'event_description' => 'Test',
            'contact_email' => 'e@example.com',
            'event_type' => Event::EVENT_TYPES['SINGLE'],
            'schedule_type' => Event::SCHEDULE_TYPES['SINGLE'],
            'event_config' => Event::EVENT_CONFIGS['OPEN_TICKET'],
            'tags' => [],
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'ticket_sold' => 0,
            'organization_uuid' => $org?->uuid,
            'event_section_uuid' => $section?->uuid,
        ], $attrs));
    }

    protected function makeSchedule(Event $event, string $dateTo): Schedule
    {
        return Schedule::create([
            'event_uuid' => $event->uuid,
            'date_from' => $dateTo,
            'date_to' => $dateTo,
            'status' => 'published',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    protected function makeTx(array $attrs = []): Transaction
    {
        return Transaction::create(array_merge([
            'user_uuid' => (string) Str::uuid(),
            'event_uuid' => (string) Str::uuid(),
            'organization_uuid' => null,
            'order_number' => 'ORD-PLAT-' . Str::random(12),
            'sub_total' => 1000,
            'tax_amount' => 0,
            'discount' => 0,
            'total_amount' => 1000,
            'status' => Transaction::STATUS['ACTIVE'],
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
            'payment_provider' => 'paypal',
            'paid_at' => now(),
        ], $attrs));
    }

    protected function addOrder(Transaction $tx, int $quantity, float $total): TransactionOrder
    {
        return TransactionOrder::create([
            'user_uuid' => $tx->user_uuid,
            'transaction_uuid' => $tx->uuid,
            'event_ticket_uuid' => (string) Str::uuid(),
            'quantity' => $quantity,
            'price' => $quantity > 0 ? $total / $quantity : $total,
            'total_amount' => $total,
            'discount' => 0,
        ]);
    }

    /**
     * Writes timestamps directly (bypassing Eloquent's automatic touch).
     *
     * @param  array<string, mixed>  $columns
     */
    protected function setTxColumns(Transaction $tx, array $columns): void
    {
        DB::table('transactions')->where('uuid', $tx->uuid)->update($columns);
    }

    /**
     * Ledger rows are written with forceCreate because markup_amount,
     * tax_and_fees and original_paid_at are not mass assignable.
     *
     * @param  array<string, mixed>  $attrs
     */
    protected function ledgerRow(array $attrs): TransactionCommission
    {
        $uuid = (string) Str::uuid();

        return TransactionCommission::forceCreate(array_merge([
            'accountable_type' => Transaction::class,
            'accountable_id' => $uuid,
            'transaction_uuid' => $uuid,
            'gross_amount' => 1000,
            'markup_amount' => 0,
            'tax_and_fees' => 0,
            'net_amount' => 900,
            'ticketoc_commission_percent' => 10,
            'ticketoc_commission' => 100,
            'ticketoc_net_commission' => 100,
            'agent_commission_percent' => 0,
            'agent_commission' => 0,
            'payment_provider' => 'paypal',
            'payment_method' => null,
            'payment_gateway_commission_percent' => 0,
            'payment_gateway_fixed_fee' => 0,
            'payment_gateway_commission' => 0,
            'currency' => 'PHP',
            'transaction_type' => TransactionCommission::TYPE['TRANSACTION'],
            'date_paid' => now(),
        ], $attrs));
    }
}
