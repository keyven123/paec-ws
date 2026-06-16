<?php

namespace App\Http\Repositories;

use App\Constants\GeneralConstants;
use App\Exceptions\NoEventFoundException;
use App\Exceptions\UnauthorizedException;
use App\Models\Event;
use App\Models\EventLocation;
use App\Helpers\GeneralHelper;
use App\Helpers\OrganizationHelper;
use App\Models\EventSection;
use App\Models\Ticket;
use App\Models\Transaction;
use App\Models\TransactionOrder;
use App\Services\Organizer\OrganizerAccountingBalanceService;
use App\Services\TicketPurchasePricingService;
use Carbon\Carbon;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EventRepository
{
    /**
     * @param Event $event
     */
    public function __construct(protected Event $event)
    {
    }

    /**
     * @param array $filters
     * @return Builder
     */
    public function getAll(array $filters): Builder
    {
        $event = $this->event->query();
        if (!request()->user('admin')->role->is_admin) {
            $event = $event->where('organization_uuid', request()->user('admin')->organization_uuid);
        }
        return $event->filters($filters)
            ->with(['organization', 'schedules', 'eventTickets', 'featuredImage', 'portraitImage'])
            ->orderBy('created_at', 'desc');
    }

    /**
     * Get public events
     * @param array $filters
     * @return Builder
     */
    public function getPublicEvents(array $filters): Builder
    {
        $events = $this->event->published()
            ->active()
            ->filters($filters)
            ->where('event_config', '!=', Event::EVENT_CONFIGS['PRIVATE_EVENT'])
            ->with(['logo', 'portraitImage', 'featuredImage', 'category', 'schedules', 'schedules.scheduleTimes', 'eventSection', 'eventTickets', 'eventLocations']);

        if (isset($filters['type']) && !is_null($filters['type'])) {
            $section = EventSection::where('name', $filters['type'])->first();
            if (!$section) {
                return $events->whereRaw('1 = 0');
            }

            $events = $events->where('event_section_uuid', $section->uuid);

            if ($section->name === EventSection::FEATURED_SECTION) {
                // Events belong to the featured section by FK; do not require is_featured=true
                // (that hid most catalog rows when admins only set section, not the flag).
                $events = $events->orderByDesc('is_featured')->orderBy('featured_order');
            }

            if ($section->name === EventSection::UPCOMING_SECTION) {
                $events = $events->upcoming();
            }

            if ($section->name === EventSection::OPEN_PASS_SECTION) {
                $events = $events->openPass();
            }

            if ($section->name === EventSection::NEW_EVENT_SECTION) {
                $events = $events->newEvent();
            }

            if ($section->name === EventSection::AMUSEMENT_SECTION) {
                $events = $events->amusement();
            }
        }
        $events->orderBy($filters['sort_by'] ?? 'created_at', $filters['sort'] ?? 'desc');

        return $events->orderBy('created_at', 'desc');
    }

    /**
     * Distinct cities from published amusement event locations.
     *
     * @return array<int, string>
     */
    public function getBrowseByCityCities(string $type = EventSection::AMUSEMENT_SECTION): array
    {
        $section = EventSection::where('name', $type)->first();
        if (!$section) {
            return [];
        }

        return EventLocation::query()
            ->where('is_active', true)
            ->whereHas('event', function ($query) use ($section) {
                $query->published()
                    ->active()
                    ->where('event_section_uuid', $section->uuid)
                    ->where('event_config', '!=', Event::EVENT_CONFIGS['PRIVATE_EVENT']);
            })
            ->orderBy('city')
            ->pluck('city')
            ->unique()
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Active event locations for browse-by-city cards.
     */
    public function getBrowseByCityLocations(array $filters): Collection
    {
        $type = $filters['type'] ?? EventSection::AMUSEMENT_SECTION;
        $limit = (int) ($filters['limit'] ?? 12);
        $city = $filters['city'] ?? null;

        $section = EventSection::where('name', $type)->first();
        if (!$section) {
            return collect();
        }

        $query = EventLocation::query()
            ->where('is_active', true)
            ->whereHas('event', function ($builder) use ($section) {
                $builder->published()
                    ->active()
                    ->where('event_section_uuid', $section->uuid)
                    ->where('event_config', '!=', Event::EVENT_CONFIGS['PRIVATE_EVENT']);
            })
            ->with([
                'event.eventTickets',
                'event.featuredImage',
                'event.portraitImage',
                'event.logo',
            ])
            ->orderBy('city')
            ->orderBy('sort_order')
            ->orderBy('created_at', 'desc');

        if ($city !== null && $city !== '') {
            $query->where('city', 'like', '%' . $city . '%');
        }

        return $query->limit($limit)->get();
    }

    /**
     * Published ticket events eligible for affiliate links (excludes Fun / amusements).
     */
    public function getAffiliatePartnerTicketEvents(array $filters): Builder
    {
        $events = $this->event->published()
            ->active()
            ->filters($filters)
            ->with(['logo', 'portraitImage', 'featuredImage', 'category', 'eventSection']);

        $amusement = EventSection::where('name', EventSection::AMUSEMENT_SECTION)->first();
        if ($amusement) {
            $events->where('event_section_uuid', '!=', $amusement->uuid);
        }

        $events->where('event_config', '!=', Event::EVENT_CONFIGS['PRIVATE_EVENT']);
        $events->where('affiliate_enabled', true);
        $events->whereAffiliateProgramNotPastEndDate();
        $events->orderBy($filters['sort_by'] ?? 'created_at', $filters['sort'] ?? 'desc');

        return $events->orderBy('created_at', 'desc');
    }

    /**
     * Get upcoming events
     * @param array $filters
     * @return Builder
     */
    public function getUpcoming(array $filters): Builder
    {
        $section = EventSection::where('name', EventSection::UPCOMING_SECTION)->first();

        $event = $this->event->upcoming();
        if ($section) {
            $event = $event->where('event_section_uuid', $section->uuid);
        }

        return $event
            ->filters($filters)
            ->orderBy('created_at', 'desc');
    }

    /**
     * Fetch event or throw exception if not found.
     *
     * @param string $key
     * @param string $value
     * @return Event
     * @throws NoEventFoundException
     */
    public function fetchOrThrow(string $key, string $value): Event
    {
        $event = $this->event->with(['schedules', 'eventTickets', 'portraitImage', 'featuredImage', 'venue', 'organization', 'approvedBy', 'creator', 'updater', 'eventSection', 'category', 'eventLocations'])->where($key, $value)->first();

        if (is_null($event)) {
            throw new NoEventFoundException();
        }

        return $event;
    }

    /**
     * @param array $payload
     * @return Event
     */
    public function create(array $payload): Event
    {
        $eventPayload = GeneralHelper::unsetUnknownAndNullFields($payload, Event::DATA);
        return $this->event->create($eventPayload);
    }

    /**
     * @param Event $event
     * @param array $payload
     * @return bool|Event
     */
    public function update(Event $event, array $payload): bool|Event
    {
        if (!isset($payload['organization_uuid']) || empty($payload['organization_uuid'])) {
            $payload['organization_uuid'] = auth('admin')->user()->organization_uuid;
        }
        $eventPayload = GeneralHelper::unsetUnknownAndNullFields($payload, Event::DATA);
        return $event->update($eventPayload);
    }

    public function submitForApproval(Event $event): bool|Event
    {
        return $event->update(['status' => GeneralConstants::EVENT_STATUSES['PENDING']]);
    }

    public function requestForFeatured(Event $event): bool|Event
    {
        return $event->update(['is_request_for_featured' => true]);
    }

    public function cancelForFeatured(Event $event): bool|Event
    {
        return $event->update(['is_request_for_featured' => false]);
    }

    public function cancelForApproval(Event $event): bool|Event
    {
        return $event->update(['status' => GeneralConstants::EVENT_STATUSES['DRAFT']]);
    }

    /**
     * Approve an event
     * @param Event $event
     * @return bool|Event
     */
    public function approve(Event $event): bool|Event
    {
        return $event->update([
            'status' => GeneralConstants::EVENT_STATUSES['APPROVED'],
            'approved_at' => now(),
            'approved_by' => auth('admin')->user()->uuid,
        ]);
    }

    /**
     * Publish an event
     * @param Event $event
     * @return bool|Event
     */
    public function publish(Event $event): bool|Event
    {
        return $event->update([
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
            'published_at' => now()
        ]);
    }

    /**
     * Unpublish an event
     * @param Event $event
     * @return bool|Event
     */
    public function unpublish(Event $event): bool|Event
    {
        return $event->update([
            'status' => GeneralConstants::EVENT_STATUSES['PENDING'],
            'published_at' => null
        ]);
    }

    /**
     * Cancel an event
     * @param Event $event
     * @return bool|Event
     */
    public function cancel(Event $event): bool|Event
    {
        return $event->update(['cancelled_at' => now()]);
    }

    /**
     * Complete an event
     * @param Event $event
     * @return bool|Event
     */
    public function complete(Event $event): bool|Event
    {
        return $event->update(['completed_at' => now()]);
    }

    /**
     * Feature an event
     * @param Event $event
     * @param array $payload
     * @return bool|Event
     */
    public function feature(Event $event, array $payload = []): bool|Event
    {
        $eventSection = EventSection::where('name', EventSection::FEATURED_SECTION)->first();
        return $event->update([
            'event_section_uuid' => $eventSection?->uuid,
            'is_featured' => true,
            'featured_order' => $payload['featured_order'] ?? 0,
            'featured_from' => $payload['featured_from'] ?? null,
            'featured_until' => $payload['featured_until'] ?? null,
            'is_request_for_featured' => false,
        ]);
    }

    /**
     * Unfeature an event
     * @param Event $event
     * @return bool|Event
     */
    public function unfeature(Event $event): bool|Event
    {
        return $event->update([
            'is_featured' => false,
            'status' => GeneralConstants::EVENT_STATUSES['PUBLISHED'],
            'featured_order' => null,
            'featured_from' => null,
            'featured_until' => null,
            'published_at' => null,
        ]);
    }

    /**
     * @param Event $event
     * @return void
     * @throws UnauthorizedException
     */
    public function delete(Event $event): void
    {
        if ((int) $event->registration_count > 0 || $event->tickets()->exists()) {
            throw new UnauthorizedException('Cannot delete event with existing registrations.');
        }

        $event->delete();
    }

    /**
     * Net ticket sales: sum of purchasers-export line totals (affiliate + promo split, legacy export formula).
     */
    public function netRevenueAfterAffiliateFromTicketLines(string $eventUuid): float
    {
        return round((float) $this->getEventTickets($eventUuid)->sum('total_sold_amount'), 2);
    }

    /**
     * Legacy line net for ticket sales aggregation (excludes tax; export payout uses TicketPurchasePricingService).
     */
    private function purchasersExportLineTotalAmount(
        TransactionOrder $transactionOrder,
        Transaction $transaction,
        float $transactionOrdersSum,
        float $affiliateCommissionAmount,
    ): float {
        $orderTotal = (float) $transactionOrder->total_amount;
        $affiliateComs = $transactionOrdersSum > 0
            ? ($orderTotal / $transactionOrdersSum) * $affiliateCommissionAmount
            : 0.0;
        $discountPromoCode = $transactionOrdersSum > 0
            ? ($orderTotal / $transactionOrdersSum) * (float) $transaction->promo_code_discount
            : 0.0;

        return (float) $transactionOrder->total_amount - $affiliateComs - $discountPromoCode;
    }

    public function getEventTickets(string $uuid): Collection
    {
        $orders = TransactionOrder::query()
            ->whereHas('transaction', function ($q) use ($uuid) {
                $q->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
                    ->where('event_uuid', $uuid);
            })
            ->with([
                'eventTicket' => function ($query) {
                    $query->withTrashed();
                },
                'transaction.tickets',
                'transaction.affiliateConversion',
            ])
            ->get();

        if ($orders->isEmpty()) {
            return collect();
        }

        $transactionOrdersSumByTxnUuid = $orders
            ->groupBy('transaction_uuid')
            ->map(fn (Collection $txnOrders) => (float) $txnOrders->sum(fn (TransactionOrder $o) => (float) $o->total_amount));

        $byName = [];

        foreach ($orders as $order) {
            $transaction = $order->transaction;
            if (! $transaction || ! $order->eventTicket) {
                continue;
            }

            $name = $order->eventTicket->name;
            $isComplementary = $this->transactionIsComplementaryTickets($transaction);
            $txnSum = (float) ($transactionOrdersSumByTxnUuid->get($transaction->uuid) ?? 0.0);
            $coms = (float) ($transaction->affiliateConversion?->commission_amount ?? 0.0);
            $afterAffiliate = $isComplementary
                ? 0.0
                : round($this->purchasersExportLineTotalAmount($order, $transaction, $txnSum, $coms), 2);

            if (! isset($byName[$name])) {
                $byName[$name] = [
                    'total_sold_amount' => 0.0,
                    'total_sold_tickets' => 0,
                ];
            }

            $byName[$name]['total_sold_amount'] += $afterAffiliate;
            $byName[$name]['total_sold_tickets'] += (int) $order->quantity;
        }

        return collect($byName)
            ->map(fn (array $agg, string $name) => [
                'name' => $name,
                'total_sold_amount' => round($agg['total_sold_amount'], 2),
                'total_sold_tickets' => $agg['total_sold_tickets'],
            ])
            ->sortBy('name')
            ->values();
    }

    public function getEventStats(array $filters): Event
    {
        $isFeatured = $filters['is_featured'] ?? null;
        if (GeneralHelper::checkHasAccess('organizations-' . GeneralConstants::PERMISSION_LABEL['r'])) {
            $organizationUuid = $filters['organization_uuid'] ?? null;
        } else {
            $organizationUuid = auth('admin')->user()->organization_uuid;
        }

        $eventSection = EventSection::whereIn('name', [EventSection::OPEN_PASS_SECTION, EventSection::FEATURED_SECTION, EventSection::NEW_EVENT_SECTION])->get();

        $query = $this->event->filters($filters)->whereIn('event_section_uuid', $eventSection->pluck('uuid'));

        return OrganizationHelper::tenantOrganization($query)
            ->when($isFeatured !== null, fn($q) => $q->where('is_featured', $isFeatured))
            ->when($organizationUuid, fn($q) => $q->where('organization_uuid', $organizationUuid))
            ->whereNull('deleted_at')
            ->selectRaw("
                SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS total_published,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS total_pending,
                (
                    SELECT COALESCE(SUM(total_amount), 0)
                    FROM transactions
                    WHERE transactions.payment_status IN ('paid', 'paid-nr')
                    AND transactions.event_uuid IN (
                        SELECT uuid FROM events
                        WHERE
                            (? IS NULL OR events.organization_uuid = ?)
                            AND (? IS NULL OR events.is_featured = ?)
                    )
                ) AS total_transaction_amount,
                (
                    SELECT COUNT(*)
                    FROM tickets
                    WHERE tickets.transaction_uuid IN (
                        SELECT uuid FROM transactions
                        WHERE transactions.payment_status = 'paid'
                        AND transactions.payment_provider IS NOT NULL
                        AND transactions.event_uuid IN (
                            SELECT uuid FROM events
                            WHERE
                                (? IS NULL OR events.organization_uuid = ?)
                                AND (? IS NULL OR events.is_featured = ?)
                        )
                    )
                ) AS total_tickets_sold
            ", [
                $organizationUuid, $organizationUuid,
                $isFeatured, $isFeatured,
                $organizationUuid, $organizationUuid,
                $isFeatured, $isFeatured,
            ])
            ->first();
    }

    public function getFunStats(array $filters): Event
    {
        if (GeneralHelper::checkHasAccess('organizations-' . GeneralConstants::PERMISSION_LABEL['r'])) {
            $organizationUuid = $filters['organization_uuid'] ?? null;
        } else {
            $organizationUuid = auth('admin')->user()->organization_uuid;
        }

        $amusementSection = EventSection::where('name', EventSection::AMUSEMENT_SECTION)->first();

        if (! $amusementSection) {
            return tap(new Event(), function (Event $event) {
                $event->forceFill([
                    'total_published' => 0,
                    'total_pending' => 0,
                    'total_transaction_amount' => 0,
                    'total_tickets_sold' => 0,
                ]);
            });
        }

        $organizationUuidParam = $organizationUuid ?? null;
        $amusementUuidParam = $amusementSection->uuid;

        $query = $this->event->filters($filters)
            ->where('event_section_uuid', $amusementUuidParam);

        return OrganizationHelper::tenantOrganization($query)
            ->when($organizationUuid, fn ($q) => $q->where('organization_uuid', $organizationUuid))
            ->selectRaw("
                SUM(CASE WHEN status = 'published' THEN 1 ELSE 0 END) AS total_published,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS total_pending,
        
                (
                    SELECT COALESCE(SUM(total_amount), 0)
                    FROM transactions
                    WHERE transactions.payment_status IN ('paid', 'paid-nr')
                    AND transactions.event_uuid IN (
                        SELECT uuid FROM events
                        WHERE event_section_uuid = ?
                        AND (? IS NULL OR events.organization_uuid = ?)
                    )
                ) AS total_transaction_amount,
        
                (
                    SELECT COUNT(*)
                    FROM tickets
                    WHERE tickets.status IN ('used', 'active')
                    AND tickets.transaction_uuid IN (
                        SELECT uuid FROM transactions
                        WHERE transactions.event_uuid IN (
                            SELECT uuid FROM events
                            WHERE event_section_uuid = ?
                            AND (? IS NULL OR events.organization_uuid = ?)
                        )
                    )
                ) AS total_tickets_sold
            ", [
                // For subquery #1
                $amusementUuidParam,
                $organizationUuidParam, $organizationUuidParam,

                // For subquery #2
                $amusementUuidParam,
                $organizationUuidParam, $organizationUuidParam,
            ])
            ->first();
    }

    public function export(
        Event $event,
        bool $includeAdminOnlyColumns = true,
        ?Carbon $start = null,
        ?Carbon $end = null,
    ): string {
        $event->loadMissing(['organization', 'venue']);

        $transactions = $this->queryPurchasersExportTransactions(
            eventUuid: $event->uuid,
            organizationUuid: null,
            start: $start,
            end: $end,
        );

        $commissionRate = app(OrganizerAccountingBalanceService::class)
            ->commissionRate($event->organization);

        return $this->buildPurchasersExportCsv(
            $transactions,
            [$event->organization_uuid => $commissionRate],
            $event,
            $includeAdminOnlyColumns,
        );
    }

    /**
     * Paid transaction line export (same columns as event purchasers export), filtered by date range and organization.
     */
    public function exportPaidTransactionsInRange(
        ?string $organizationUuid,
        Carbon $start,
        Carbon $end,
        bool $includeAdminOnlyColumns = true,
    ): string {
        $transactions = $this->queryPurchasersExportTransactions(
            eventUuid: null,
            organizationUuid: $organizationUuid,
            start: $start,
            end: $end,
        );

        return $this->buildPurchasersExportCsv(
            $transactions,
            includeAdminOnlyColumns: $includeAdminOnlyColumns,
        );
    }

    /**
     * @return list<string>
     */
    private function purchasersExportHeaders(bool $includeAdminOnlyColumns = true): array
    {
        $headers = [
            'Order Number',
            'Purchase Date',
            'Payment Status',
            'Customer Name',
            'Customer Email',
            'Customer Phone',
            'Ticket Type',
            'Quantity',
            'Unit Price',
            'Discount',
            'Net Selling Price',
        ];

        if ($includeAdminOnlyColumns) {
            $headers = array_merge($headers, [
                'Markup',
                'Gross Selling Price',
                'Tax and Fees',
            ]);
        }

        $headers = array_merge($headers, [
            $includeAdminOnlyColumns ? 'Affiliate Commission' : 'Affiliate Commissions',
            'Platform Fee',
            'Total Payout',
        ]);

        if ($includeAdminOnlyColumns) {
            $headers[] = 'Gross Revenue';
        }

        return array_merge($headers, [
            'Payment Method',
            'Reference Number',
            'Transaction ID',
            'Event Name',
            'Event Schedule',
            'Venue',
            'Promo Code Used',
            'Type',
            'Affiliate Name',
        ]);
    }

    /**
     * @return Collection<int, Transaction>
     */
    private function queryPurchasersExportTransactions(
        ?string $eventUuid,
        ?string $organizationUuid,
        ?Carbon $start,
        ?Carbon $end,
    ): Collection {
        return Transaction::query()
            ->with([
                'user',
                'tickets',
                'promoCode',
                'affiliateConversion.partner',
                'schedule',
                'scheduleTime',
                'event.venue',
                'organization',
                'transactionOrders.eventTicket' => function ($query) {
                    $query->withTrashed();
                },
            ])
            ->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->when($eventUuid !== null, fn ($q) => $q->where('event_uuid', $eventUuid))
            ->when($organizationUuid !== null, fn ($q) => $q->where('organization_uuid', $organizationUuid))
            ->when($start !== null && $end !== null, fn ($q) => $q->whereBetween('created_at', [$start, $end]))
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @param  array<string, float>  $commissionRatesByOrganization
     */
    private function buildPurchasersExportCsv(
        Collection $transactions,
        array $commissionRatesByOrganization = [],
        ?Event $fixedEvent = null,
        bool $includeAdminOnlyColumns = true,
    ): string {
        $data = [$this->purchasersExportHeaders($includeAdminOnlyColumns)];
        $balanceService = app(OrganizerAccountingBalanceService::class);

        foreach ($transactions as $transaction) {
            $event = $fixedEvent ?? $transaction->event;
            if (! $event) {
                continue;
            }

            $organizationUuid = $transaction->organization_uuid;
            if (! isset($commissionRatesByOrganization[$organizationUuid])) {
                $transaction->loadMissing('organization');
                $commissionRatesByOrganization[$organizationUuid] = $balanceService->commissionRate(
                    $transaction->organization,
                );
            }

            $this->appendPurchasersExportRows(
                $data,
                $transaction,
                $event,
                $commissionRatesByOrganization[$organizationUuid],
                $includeAdminOnlyColumns,
            );
        }

        return $this->encodePurchasersExportCsv($data);
    }

    /**
     * @param  list<list<scalar>>  $data
     */
    private function appendPurchasersExportRows(
        array &$data,
        Transaction $transaction,
        Event $event,
        float $commissionRate,
        bool $includeAdminOnlyColumns = true,
    ): void {
        $transactionOrders = $transaction->transactionOrders;
        $coms = (float) ($transaction->affiliateConversion?->commission_amount ?? 0.0);
        $transactionOrdersSum = (float) $transactionOrders->sum('total_amount');
        $paymentTypeRefNo = GeneralHelper::getPaymentTypeRefNo(
            $transaction->payment_provider,
            $transaction->payment_data,
        );

        foreach ($transactionOrders as $transactionOrder) {
            $orderTotal = (float) $transactionOrder->total_amount;
            $quantity = max(1, (int) $transactionOrder->quantity);
            $unitPrice = (float) $transactionOrder->price;

            $affiliateComs = $transactionOrdersSum > 0
                ? ($orderTotal / $transactionOrdersSum) * $coms
                : 0.0;

            $amounts = TicketPurchasePricingService::lineAmountsForPaidOrder(
                $transaction,
                $transactionOrder,
                $commissionRate,
                $affiliateComs,
            );

            $ticketQuantity = ($etUuid = $transactionOrder->eventTicket?->uuid)
                ? $transaction->tickets->filter(
                    fn ($t) => $t->status !== GeneralConstants::TICKET_STATUSES['TRANSFERRED']
                        && $t->event_ticket_uuid === $etUuid,
                )->count()
                : 0;

            $row = [
                $transaction->order_number,
                Carbon::parse($transaction->paid_at)->format('Y/m/d H:i:s'),
                $transaction->payment_status,
                $transaction->user->full_name ?? 'N/A',
                $transaction->user->email ?? 'N/A',
                $transaction->user->phone_number ?? 'N/A',
                $transactionOrder->eventTicket->name ?? 'N/A',
                $ticketQuantity,
                number_format($unitPrice, 2),
                number_format($amounts['discount'], 2),
                number_format($amounts['net_selling_price'], 2),
            ];

            if ($includeAdminOnlyColumns) {
                $row = array_merge($row, [
                    number_format($amounts['markup'], 2),
                    number_format($amounts['gross_selling_price'], 2),
                    number_format($amounts['tax_and_fees'], 2),
                ]);
            }

            $row = array_merge($row, [
                number_format($affiliateComs, 2),
                number_format($amounts['platform_fee'], 2),
                number_format($amounts['total_payout'], 2),
            ]);

            if ($includeAdminOnlyColumns) {
                $row[] = number_format($amounts['gross_revenue'], 2);
            }

            $data[] = array_merge($row, [
                $transaction->payment_provider ?? 'N/A',
                $paymentTypeRefNo,
                $transaction->payment_order_id ?? 'N/A',
                $event->event_name,
                $transaction->schedule?->date_from
                    ? Carbon::parse($transaction->schedule->date_from)->format('Y/m/d')
                        . ($transaction->scheduleTime
                            ? ' - '
                                .Carbon::parse($transaction->scheduleTime->time_start)->format('H:i')
                                .' - '
                                .Carbon::parse($transaction->scheduleTime->time_end)->format('H:i')
                            : '')
                    : 'N/A',
                $event->venue?->name ?? $event->address ?? 'N/A',
                $transaction->promo_code_uuid
                    ? ($transaction->promoCode->code ?? 'N/A')
                    : ($transaction->discount > 0 ? 'EVENT TICKET PROMO' : 'N/A'),
                $transaction->tickets->first()?->type ?? 'N/A',
                $transaction->affiliateConversion?->partner?->full_name ?? 'N/A',
            ]);
        }
    }

    /**
     * @param  list<list<scalar>>  $data
     */
    private function encodePurchasersExportCsv(array $data): string
    {
        $csvContent = '';
        foreach ($data as $row) {
            $csvContent .= '"'.implode('","', $row).'"'."\n";
        }

        return $csvContent;
    }

    public function exportAttendeeRegistrationReport(
        Event $event,
        ?Carbon $start = null,
        ?Carbon $end = null,
    ): string {
        $tickets = $event->tickets()
            ->whereHas('transaction', function ($query) use ($start, $end) {
                $query->where('payment_status', Transaction::PAYMENT_STATUS['PAID']);
                if ($start !== null && $end !== null) {
                    $query->whereBetween('paid_at', [$start, $end]);
                }
            })
            ->with(['transaction', 'transaction.user', 'transaction.transactionOrders.eventTicket'])
            ->whereNull('transferred_at')
            ->get();

        $additionalHeaders = [];
        $data = [];

        // Add CSV headers
        $headers = [
            'Order Number',
            'Ticket Number',
            'QR Code',
            'Purchase Date',
            'Payment Status',
            'Customer Name',
            'Customer Email',
            'Customer Phone',
            'Access Type',
            'Event Name',
            'Ticket Status'
        ];


        if ($event->other_info && is_array($event->other_info)) {
            foreach ($event->other_info as $key => $field) {
                $additionalHeaders[] = $key;
            }
        }

        $headers = array_merge($headers, $additionalHeaders);


        $data[] = $headers;
        foreach ($tickets as $ticket) {
            $additionalFields = [];
            $fields = [
                $ticket->transaction->order_number,
                $ticket->ticket_number,
                $ticket->qr_code ?? '',
                Carbon::parse($ticket->transaction->paid_at)->format('Y/m/d H:i:s'),
                $ticket->transaction->payment_status,
                $ticket->attendee_name ?? 'N/A',
                $ticket->attendee_email ?? 'N/A',
                $ticket->attendee_contact ?? 'N/A',
                $ticket->eventTicket->name ?? 'N/A',
                $ticket->event->event_name ?? 'N/A',
                $ticket->status ?? 'N/A',
            ];

            if ($event->other_info && is_array($event->other_info)) {
                foreach ($ticket->other_info as $field) {
                    $additionalFields[] = $field ?? '';
                }
            }

            $fields = array_merge($fields, $additionalFields);

            $data[] = $fields;
        }

        // Generate CSV content
        $csvContent = '';
        foreach ($data as $row) {
            $csvContent .= '"' . implode('","', $row) . '"' . "\n";
        }

        return $csvContent;
    }

    public function arrangeFeaturedEvents(array $payload): void
    {
        DB::beginTransaction();
        $eventSection = EventSection::where('name', EventSection::FEATURED_SECTION)->first();
        $this->event->where('event_section_uuid', $eventSection->uuid)
            ->update(['is_featured' => false]);
        foreach ($payload['events'] as $order) {
            $event = $this->fetchOrThrow('uuid', $order['uuid']);
            $event->update([
                'event_section_uuid' => $eventSection->uuid,
                'featured_order' => $order['featured_order'] ?? null,
                'is_featured' => true,
            ]);
        }
        DB::commit();
    }

    public function isAmusementEvent(Event $event): bool
    {
        if (! $event->relationLoaded('eventSection')) {
            $event->load('eventSection');
        }

        return $event->eventSection?->name === EventSection::AMUSEMENT_SECTION;
    }

    public function hasPaidTicketsScheduledOnDate(string $eventUuid, string $date): bool
    {
        $paidTicketQuery = fn () => Ticket::query()
            ->where('event_uuid', $eventUuid)
            ->whereNull('deleted_at')
            ->whereNull('transferred_at')
            ->whereNotNull('valid_until')
            ->whereHas('transaction', function ($query) {
                $query->where('payment_status', Transaction::PAYMENT_STATUS['PAID']);
            });

        if ((clone $paidTicketQuery())
            ->where('visit_policy', 'priority')
            ->whereDate('valid_until', $date)
            ->exists()) {
            return true;
        }

        return (clone $paidTicketQuery())
            ->where('visit_policy', 'flexible')
            ->whereDate('created_at', '<=', $date)
            ->whereDate('valid_until', '>=', $date)
            ->exists();
    }

    /**
     * Sold ticket counts for a calendar month, grouped by valid_until and visit policy.
     *
     * Priority tickets are counted on their visit date (DATE(valid_until)).
     * Flexible tickets are counted on each day they remain valid (created_at through valid_until).
     *
     * @return array{
     *     year: int,
     *     month: int,
     *     date_from: string,
     *     date_to: string,
     *     month_summary: array{
     *         flexible_ticket_count: int,
     *         new_sales_count: int,
     *         redeemed_count: int,
     *         total_ticket_count: int
     *     },
     *     days: array<int, array{
     *         date: string,
     *         priority_ticket_count: int,
     *         flexible_ticket_count: int,
     *         redeemed_count: int
     *     }>
     * }
     */
    public function getEventTicketCalendar(string $eventUuid, int $year, int $month): array
    {
        $monthStart = Carbon::create($year, $month, 1)->startOfMonth();
        $monthEnd = Carbon::create($year, $month, 1)->endOfMonth();
        $startDate = $monthStart->toDateString();
        $endDate = $monthEnd->toDateString();

        $paidTicketQuery = fn () => Ticket::query()
            ->where('event_uuid', $eventUuid)
            ->whereNull('deleted_at')
            ->whereNull('transferred_at')
            ->whereNotNull('valid_until')
            ->whereHas('transaction', function ($query) {
                $query->where('payment_status', Transaction::PAYMENT_STATUS['PAID']);
            });

        $monthFlexibleTotal = (clone $paidTicketQuery())
            ->where('visit_policy', 'flexible')
            ->whereDate('valid_until', '>=', $startDate)
            ->whereDate('created_at', '<=', $endDate)
            ->count();

        $newSalesTotal = (clone $paidTicketQuery())
            ->where('created_at', '>=', Carbon::now()->subHours(24))
            ->count();

        $redeemedTotal = (clone $paidTicketQuery())
            ->where('status', GeneralConstants::TICKET_STATUSES['USED'])
            ->whereBetween('used_at', [$monthStart, $monthEnd])
            ->count();

        $totalTicketsInMonth = (clone $paidTicketQuery())
            ->whereBetween(DB::raw('DATE(created_at)'), [$startDate, $endDate])
            ->count();

        $dayCounts = [];

        $priorityRows = (clone $paidTicketQuery())
            ->where('visit_policy', 'priority')
            ->whereBetween(DB::raw('DATE(valid_until)'), [$startDate, $endDate])
            ->selectRaw('DATE(valid_until) as date, COUNT(*) as ticket_count')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        foreach ($priorityRows as $row) {
            $date = Carbon::parse($row->date)->toDateString();
            $dayCounts[$date] = [
                'priority_ticket_count' => (int) $row->ticket_count,
                'flexible_ticket_count' => $dayCounts[$date]['flexible_ticket_count'] ?? 0,
                'redeemed_count' => $dayCounts[$date]['redeemed_count'] ?? 0,
            ];
        }

        $flexibleTickets = (clone $paidTicketQuery())
            ->where('visit_policy', 'flexible')
            ->whereDate('valid_until', '>=', $startDate)
            ->whereDate('created_at', '<=', $endDate)
            ->get(['created_at', 'valid_until']);

        foreach ($flexibleTickets as $ticket) {
            $validFrom = Carbon::parse($ticket->created_at)->startOfDay();
            $validTo = Carbon::parse($ticket->valid_until)->startOfDay();
            $rangeStart = $validFrom->greaterThan($monthStart) ? $validFrom : $monthStart->copy();
            $rangeEnd = $validTo->lessThan($monthEnd) ? $validTo : $monthEnd->copy();

            if ($rangeStart->greaterThan($rangeEnd)) {
                continue;
            }

            for ($date = $rangeStart->copy(); $date->lessThanOrEqualTo($rangeEnd); $date->addDay()) {
                $dateKey = $date->toDateString();
                $dayCounts[$dateKey] = [
                    'priority_ticket_count' => $dayCounts[$dateKey]['priority_ticket_count'] ?? 0,
                    'flexible_ticket_count' => ($dayCounts[$dateKey]['flexible_ticket_count'] ?? 0) + 1,
                    'redeemed_count' => $dayCounts[$dateKey]['redeemed_count'] ?? 0,
                ];
            }
        }

        $redeemedRows = (clone $paidTicketQuery())
            ->whereNotNull('used_at')
            ->where('status', GeneralConstants::TICKET_STATUSES['USED'])
            ->whereBetween(DB::raw('DATE(used_at)'), [$startDate, $endDate])
            ->selectRaw('DATE(used_at) as date, COUNT(*) as ticket_count')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        foreach ($redeemedRows as $row) {
            $date = Carbon::parse($row->date)->toDateString();
            $dayCounts[$date] = [
                'priority_ticket_count' => $dayCounts[$date]['priority_ticket_count'] ?? 0,
                'flexible_ticket_count' => $dayCounts[$date]['flexible_ticket_count'] ?? 0,
                'redeemed_count' => (int) $row->ticket_count,
            ];
        }

        ksort($dayCounts);

        $days = [];
        foreach ($dayCounts as $date => $counts) {
            $days[] = [
                'date' => $date,
                'priority_ticket_count' => $counts['priority_ticket_count'] ?? 0,
                'flexible_ticket_count' => $counts['flexible_ticket_count'] ?? 0,
                'redeemed_count' => $counts['redeemed_count'] ?? 0,
            ];
        }

        return [
            'year' => $year,
            'month' => $month,
            'date_from' => $startDate,
            'date_to' => $endDate,
            'month_summary' => [
                'flexible_ticket_count' => $monthFlexibleTotal,
                'new_sales_count' => $newSalesTotal,
                'redeemed_count' => $redeemedTotal,
                'total_ticket_count' => $totalTicketsInMonth,
            ],
            'days' => $days,
        ];
    }

    private function transactionIsComplementaryTickets(Transaction $transaction): bool
    {
        return $transaction->tickets->contains('type', 'complementary');
    }
}
