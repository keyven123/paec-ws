<?php

namespace App\Services;

use App\Constants\GeneralConstants;
use App\Models\Event;
use App\Models\Ticket;
use App\Models\Transaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Builds the admin dashboard: KPIs compared with the previous period, a revenue series, ticket
 * statuses, top attractions, upcoming visits and recent bookings. Organizer accounts only see
 * their own organization through the models' byOrganization scopes.
 */
class AdminDashboardOverviewService
{
    public const PERIODS = ['year', '30d', '7d'];

    private const TOP_ATTRACTIONS_LIMIT = 5;

    private const UPCOMING_VISITS_LIMIT = 6;

    private const UPCOMING_VISITS_DAYS = 60;

    private const RECENT_BOOKINGS_LIMIT = 6;

    /** Transferred originals and cancelled tickets would count the same seat twice or not at all. */
    private const UNSOLD_TICKET_STATUSES = [
        GeneralConstants::TICKET_STATUSES['TRANSFERRED'],
        GeneralConstants::TICKET_STATUSES['CANCELLED'],
    ];

    public function build(string $period): array
    {
        $now = CarbonImmutable::now();
        [$from, $previousFrom, $previousTo, $compareLabel] = $this->range($period, $now);

        $current = $this->paidTransactions($from, $now);
        $previous = $this->paidTransactions($previousFrom, $previousTo);
        $buckets = $this->buckets($period, $from, $now, $current);

        return [
            'period' => [
                'key' => $period,
                'from' => $from->toDateString(),
                'to' => $now->toDateString(),
                'compare_label' => $compareLabel,
            ],
            'kpis' => [
                'revenue' => $this->kpi(
                    (float) $current->sum('total_amount'),
                    (float) $previous->sum('total_amount'),
                    $buckets->pluck('revenue'),
                ),
                'orders' => $this->kpi($current->count(), $previous->count(), $buckets->pluck('orders')),
                'tickets_sold' => $this->kpi(
                    (int) $current->sum('sold_tickets_count'),
                    (int) $previous->sum('sold_tickets_count'),
                    $buckets->pluck('tickets'),
                ),
                'average_order_value' => $this->kpi(
                    $this->average($current),
                    $this->average($previous),
                    $buckets->map(fn (array $bucket) => $bucket['orders'] > 0 ? round($bucket['revenue'] / $bucket['orders'], 2) : 0),
                ),
                'attractions' => [
                    'total' => Event::query()->byOrganization()->count(),
                    'published' => Event::query()->byOrganization()
                        ->where('status', GeneralConstants::EVENT_STATUSES['PUBLISHED'])
                        ->count(),
                ],
            ],
            'series' => $buckets->values(),
            'ticket_statuses' => $this->ticketStatuses($from, $now),
            'top_attractions' => $this->topAttractions($current),
            'upcoming_visits' => $this->upcomingVisits($now),
            'recent_bookings' => $this->recentBookings(),
        ];
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable, 3: string}
     */
    private function range(string $period, CarbonImmutable $now): array
    {
        if ($period === 'year') {
            $from = $now->startOfYear();

            return [$from, $from->subYear(), $now->subYear(), 'vs ' . $from->subYear()->year];
        }

        $days = $period === '7d' ? 7 : 30;
        $from = $now->subDays($days - 1)->startOfDay();

        return [$from, $from->subDays($days), $from->subSecond(), "vs previous {$days} days"];
    }

    /**
     * @return Collection<int, Transaction>
     */
    private function paidTransactions(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return Transaction::query()
            ->byOrganization()
            ->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->whereBetween('created_at', [$from, $to])
            ->withCount(['tickets as sold_tickets_count' => fn ($query) => $query->whereNotIn('status', self::UNSOLD_TICKET_STATUSES)])
            ->get(['uuid', 'event_uuid', 'total_amount', 'created_at']);
    }

    /**
     * Monthly buckets for the year, daily buckets otherwise.
     *
     * @param  Collection<int, Transaction>  $transactions
     * @return Collection<string, array{label: string, date: string, revenue: float, orders: int, tickets: int}>
     */
    private function buckets(string $period, CarbonImmutable $from, CarbonImmutable $now, Collection $transactions): Collection
    {
        $monthly = $period === 'year';
        $keyFormat = $monthly ? 'Y-m' : 'Y-m-d';

        $buckets = collect();
        for ($cursor = $from; $cursor <= $now; $cursor = $monthly ? $cursor->addMonth() : $cursor->addDay()) {
            $buckets->put($cursor->format($keyFormat), [
                'label' => $cursor->format($monthly ? 'M' : 'M j'),
                'date' => $cursor->toDateString(),
                'revenue' => 0.0,
                'orders' => 0,
                'tickets' => 0,
            ]);
        }

        foreach ($transactions as $transaction) {
            $key = $transaction->created_at->format($keyFormat);
            $bucket = $buckets->get($key);
            $bucket['revenue'] = round($bucket['revenue'] + (float) $transaction->total_amount, 2);
            $bucket['orders']++;
            $bucket['tickets'] += $transaction->sold_tickets_count;
            $buckets->put($key, $bucket);
        }

        return $buckets;
    }

    /**
     * @param  Collection<int, float|int>  $trend
     */
    private function kpi(float|int $value, float|int $previous, Collection $trend): array
    {
        return [
            'value' => $value,
            'previous' => $previous,
            'change' => $previous > 0 ? round((($value - $previous) / $previous) * 100, 1) : null,
            'trend' => $trend->values(),
        ];
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     */
    private function average(Collection $transactions): float
    {
        return $transactions->isEmpty() ? 0.0 : round((float) $transactions->sum('total_amount') / $transactions->count(), 2);
    }

    /**
     * @return array<string, int>
     */
    private function ticketStatuses(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $counts = Ticket::query()
            ->byOrganization()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return collect(GeneralConstants::TICKET_STATUSES)
            ->mapWithKeys(fn (string $status) => [$status => (int) ($counts[$status] ?? 0)])
            ->all();
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     */
    private function topAttractions(Collection $transactions): Collection
    {
        $ranked = $transactions
            ->whereNotNull('event_uuid')
            ->groupBy('event_uuid')
            ->map(fn (Collection $group, string $eventUuid) => [
                'event_uuid' => $eventUuid,
                'revenue' => round((float) $group->sum('total_amount'), 2),
                'orders' => $group->count(),
                'tickets' => (int) $group->sum('sold_tickets_count'),
            ])
            ->sortByDesc('revenue')
            ->take(self::TOP_ATTRACTIONS_LIMIT);

        $events = $this->eventsByUuid($ranked->pluck('event_uuid'));

        return $ranked
            ->map(fn (array $row) => $this->withEvent($row, $events->get($row['event_uuid'])))
            ->values();
    }

    /**
     * Visit dates still to come, grouped per attraction and day.
     */
    private function upcomingVisits(CarbonImmutable $now): Collection
    {
        $tickets = Ticket::query()
            ->byOrganization()
            ->where('status', GeneralConstants::TICKET_STATUSES['ACTIVE'])
            ->whereNull('used_at')
            ->whereNotNull('event_uuid')
            ->whereBetween('valid_until', [$now->startOfDay(), $now->addDays(self::UPCOMING_VISITS_DAYS)->endOfDay()])
            ->whereHas('transaction', fn ($query) => $query->where('payment_status', Transaction::PAYMENT_STATUS['PAID']))
            ->get(['event_uuid', 'transaction_uuid', 'valid_until']);

        $visits = $tickets
            ->groupBy(fn (Ticket $ticket) => $ticket->event_uuid . '|' . $ticket->valid_until->toDateString())
            ->map(fn (Collection $group) => [
                'event_uuid' => $group->first()->event_uuid,
                'date' => $group->first()->valid_until->toDateString(),
                'tickets' => $group->count(),
                'orders' => $group->pluck('transaction_uuid')->unique()->count(),
            ])
            ->sortBy([['date', 'asc'], ['tickets', 'desc']])
            ->take(self::UPCOMING_VISITS_LIMIT);

        $events = $this->eventsByUuid($visits->pluck('event_uuid'));

        return $visits
            ->map(fn (array $row) => $this->withEvent($row, $events->get($row['event_uuid'])))
            ->values();
    }

    private function recentBookings(): Collection
    {
        return Transaction::query()
            ->byOrganization()
            ->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->with(['user:uuid,first_name,last_name,email', 'event:uuid,event_name'])
            ->withCount(['tickets as sold_tickets_count' => fn ($query) => $query->whereNotIn('status', self::UNSOLD_TICKET_STATUSES)])
            ->latest()
            ->limit(self::RECENT_BOOKINGS_LIMIT)
            ->get()
            ->map(fn (Transaction $transaction) => [
                'uuid' => $transaction->uuid,
                'order_number' => $transaction->order_number,
                'customer_name' => trim(($transaction->user?->first_name ?? '') . ' ' . ($transaction->user?->last_name ?? '')) ?: 'Guest',
                'customer_email' => $transaction->user?->email,
                'attraction' => $transaction->event?->event_name,
                'total_amount' => (float) $transaction->total_amount,
                'tickets' => $transaction->sold_tickets_count,
                'payment_provider' => $transaction->payment_provider,
                'created_at' => $transaction->created_at->toIso8601String(),
            ]);
    }

    /**
     * @param  Collection<int, string>  $uuids
     * @return Collection<string, Event>
     */
    private function eventsByUuid(Collection $uuids): Collection
    {
        return Event::query()
            ->with(['featuredImage', 'portraitImage'])
            ->whereIn('uuid', $uuids->unique()->values())
            ->get(['uuid', 'event_name', 'slug', 'city', 'address', 'featured_image_uuid', 'portrait_image_uuid'])
            ->keyBy('uuid');
    }

    private function withEvent(array $row, ?Event $event): array
    {
        return $row + [
            'name' => $event?->event_name ?? 'Attraction',
            'slug' => $event?->slug,
            'location' => $event?->city ?: $event?->address,
            'image_url' => $event?->featuredImage?->url ?? $event?->portraitImage?->url,
        ];
    }
}
