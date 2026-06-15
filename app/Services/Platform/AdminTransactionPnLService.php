<?php

namespace App\Services\Platform;

use App\Models\Dataset;
use App\Models\Event;
use App\Models\EventSection;
use App\Models\Organization;
use App\Models\Transaction;
use App\Services\AffiliateCommissionAvailabilityService;
use Carbon\Carbon;

class AdminTransactionPnLService
{
    public const VIEW_EVENTS = 'events';
    public const VIEW_FUN = 'fun';

    /**
     * @return array<string, mixed>
     */
    public function build(
        string $view = self::VIEW_EVENTS,
        ?string $month = null,
        string $sort = 'revenue',
        int $page = 1,
        int $perPage = 10
    ): array {
        $tz = AffiliateCommissionAvailabilityService::timezone();
        $selectedMonth = $this->normalizeMonth($month, $tz);
        $start = Carbon::createFromFormat('Y-m-d H:i:s', $selectedMonth . '-01 00:00:00', $tz)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $platformDefault = Dataset::merchantCommissionPercent();
        $paymongoRates = Dataset::paymongoRates();
        $paypalRates = Dataset::paypalRates();

        $eventMeta = $this->eventMetaMap($tz);
        $refundByEvent = $this->refundAmountByEvent($start, $end);

        $groups = [];
        foreach ($eventMeta as $eventUuid => $meta) {
            if (! $this->isInView($meta['section'], $view)) {
                continue;
            }
            $groups[$eventUuid] = [
                'event_uuid' => $eventUuid,
                'name' => (string) $meta['name'],
                'organizer' => (string) $meta['organizer'],
                'tickets' => 0,
                'gmv' => 0.0,
                'commission' => 0.0,
                'gateway' => 0.0,
                'latest_paid_at' => $meta['date_hint'],
                'take_rate_acc_weight' => 0.0,
                'take_rate_acc_gmv' => 0.0,
            ];
        }

        $orgRateCache = [];

        $txQuery = Transaction::query()
            ->with(['event:uuid,event_name,organization_uuid', 'organization:uuid,name', 'transactionOrders'])
            ->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->whereRaw('COALESCE(paid_at, created_at) >= ?', [$start])
            ->whereRaw('COALESCE(paid_at, created_at) <= ?', [$end]);

        $estimator = new PlatformPnLGatewayFeeEstimator();

        foreach ($txQuery->cursor() as $tx) {
            /** @var Transaction $tx */
            $eventUuid = $tx->event_uuid;
            if (! $eventUuid || ! isset($eventMeta[$eventUuid])) {
                continue;
            }

            if (! $this->isInView($eventMeta[$eventUuid]['section'], $view)) {
                continue;
            }

            if (! isset($groups[$eventUuid])) {
                continue;
            }

            $gross = (float) $tx->total_amount;
            $groups[$eventUuid]['gmv'] += $gross;

            $rate = $this->effectiveRateForTransaction($tx, $orgRateCache, $platformDefault);
            $groups[$eventUuid]['take_rate_acc_weight'] += $rate * $gross;
            $groups[$eventUuid]['take_rate_acc_gmv'] += $gross;

            $lineSum = 0.0;
            $qty = 0;
            foreach ($tx->transactionOrders as $order) {
                $line = (float) $order->total_amount;
                $lineSum += $line;
                $qty += (int) $order->quantity;
                $groups[$eventUuid]['commission'] += round($line * ($rate / 100.0), 2);
            }
            $groups[$eventUuid]['tickets'] += $qty;
            if ($lineSum <= 0.0) {
                $groups[$eventUuid]['commission'] += round($gross * ($rate / 100.0), 2);
            }

            $groups[$eventUuid]['gateway'] += $estimator->estimate($tx, $paymongoRates, $paypalRates);

            $paidAt = $tx->paid_at ?? $tx->created_at;
            if ($paidAt && $groups[$eventUuid]['latest_paid_at']) {
                $paid = $paidAt instanceof Carbon ? $paidAt : Carbon::parse((string) $paidAt, $tz);
                $latest = $groups[$eventUuid]['latest_paid_at'] instanceof Carbon
                    ? $groups[$eventUuid]['latest_paid_at']
                    : Carbon::parse((string) $groups[$eventUuid]['latest_paid_at'], $tz);
                if ($paid->gt($latest)) {
                    $groups[$eventUuid]['latest_paid_at'] = $paidAt;
                }
            }
        }

        $rows = array_map(function (array $g) use ($refundByEvent, $tz): array {
            $eventUuid = (string) $g['event_uuid'];
            $gmv = round((float) $g['gmv'], 2);
            $refundAmount = (float) ($refundByEvent[$eventUuid] ?? 0.0);
            $refundPct = $gmv > 0 ? round(($refundAmount / $gmv) * 100.0, 1) : 0.0;
            $takeRate = ((float) $g['take_rate_acc_gmv']) > 0
                ? round(((float) $g['take_rate_acc_weight']) / (float) $g['take_rate_acc_gmv'], 2)
                : 0.0;
            $netRev = round((float) $g['commission'], 2);
            $margin = round($netRev - (float) $g['gateway'], 2);
            $latestPaid = $g['latest_paid_at'];
            $date = $latestPaid instanceof Carbon ? $latestPaid : ($latestPaid ? Carbon::parse((string) $latestPaid, $tz) : null);

            return [
                'id' => $eventUuid,
                'name' => (string) $g['name'],
                'organizer' => (string) $g['organizer'],
                'date_label' => $date ? $date->format('M j') : 'â€”',
                'tickets' => (int) $g['tickets'],
                'gmv' => $gmv,
                'refund_pct' => $refundPct,
                'take_rate' => $takeRate,
                'net_revenue' => $netRev,
                'margin' => $margin,
            ];
        }, array_values($groups));

        usort($rows, function (array $a, array $b) use ($sort): int {
            return match ($sort) {
                'gmv' => $b['gmv'] <=> $a['gmv'],
                'margin' => $b['margin'] <=> $a['margin'],
                default => $b['net_revenue'] <=> $a['net_revenue'],
            };
        });

        $total = count($rows);
        $perPage = max(1, min(100, $perPage));
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $lastPage));
        $offset = ($page - 1) * $perPage;
        $pagedRows = array_slice($rows, $offset, $perPage);

        return [
            'view' => $view,
            'month' => $selectedMonth,
            'sort' => in_array($sort, ['revenue', 'gmv', 'margin'], true) ? $sort : 'revenue',
            'available_months' => $this->availableMonths($tz),
            'rows' => $pagedRows,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    /** Sections that appear under VIEW_EVENTS. */
    private const EVENT_SECTIONS = [
        EventSection::FEATURED_SECTION,
        EventSection::OPEN_PASS_SECTION,
        EventSection::NEW_EVENT_SECTION,
    ];

    /** Sections that appear under VIEW_FUN. */
    private const FUN_SECTIONS = [
        EventSection::AMUSEMENT_SECTION,
    ];

    private function isInView(string $sectionName, string $view): bool
    {
        if ($view === self::VIEW_EVENTS) {
            return in_array($sectionName, self::EVENT_SECTIONS, true);
        }
        if ($view === self::VIEW_FUN) {
            return in_array($sectionName, self::FUN_SECTIONS, true);
        }
        return false;
    }

    /**
     * @return array<string, array{name: string, organizer: string, section: string, date_hint: Carbon|null}>
     */
    private function eventMetaMap(string $tz): array
    {
        $meta = [];
        $events = Event::query()
            ->select('uuid', 'event_name', 'organization_uuid', 'event_section_uuid', 'published_at', 'created_at')
            ->with(['organization:uuid,name'])
            ->with(['eventSection:uuid,name'])
            ->get();

        foreach ($events as $event) {
            $sectionName = (string) ($event->eventSection?->name ?? '');

            $dateHintRaw = $event->published_at ?? $event->created_at;
            $dateHint = null;
            if ($dateHintRaw !== null) {
                $dateHint = $dateHintRaw instanceof Carbon
                    ? $dateHintRaw->copy()->timezone($tz)
                    : Carbon::parse((string) $dateHintRaw, $tz);
            }

            $meta[$event->uuid] = [
                'name'      => (string) ($event->event_name ?: 'Untitled event'),
                'organizer' => (string) ($event->organization?->name ?? 'Unknown organizer'),
                'section'   => $sectionName,
                'date_hint' => $dateHint,
            ];
        }

        return $meta;
    }

    /**
     * @return array<string, float>
     */
    private function refundAmountByEvent(Carbon $start, Carbon $end): array
    {
        $map = [];
        $rows = Transaction::query()
            ->select('event_uuid')
            ->selectRaw('SUM(total_amount) as total')
            ->where('status', Transaction::STATUS['REFUNDED'])
            ->whereBetween('updated_at', [$start, $end])
            ->groupBy('event_uuid')
            ->get();

        foreach ($rows as $row) {
            if ($row->event_uuid) {
                $map[$row->event_uuid] = (float) $row->total;
            }
        }

        return $map;
    }

    private function normalizeMonth(?string $month, string $tz): string
    {
        if ($month && preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            return $month;
        }
        return Carbon::now($tz)->format('Y-m');
    }

    /**
     * @return list<string>
     */
    private function availableMonths(string $tz): array
    {
        $months = [];
        $rows = Transaction::query()
            ->selectRaw("DATE_FORMAT(COALESCE(paid_at, created_at), '%Y-%m') as month_key")
            ->where('payment_status', Transaction::PAYMENT_STATUS['PAID'])
            ->whereNotNull('event_uuid')
            ->groupBy('month_key')
            ->orderByDesc('month_key')
            ->limit(12)
            ->get();

        foreach ($rows as $row) {
            if ($row->month_key) {
                $months[] = (string) $row->month_key;
            }
        }
        if ($months === []) {
            $months[] = Carbon::now($tz)->format('Y-m');
        }
        return $months;
    }

    /**
     * @param  array<string, float>  $orgRateCache
     */
    private function effectiveRateForTransaction(Transaction $tx, array &$orgRateCache, float $platformDefault): float
    {
        $orgUuid = $tx->organization_uuid;
        if ($orgUuid === null) {
            return $platformDefault;
        }
        if (! array_key_exists($orgUuid, $orgRateCache)) {
            $org = $tx->organization ?? Organization::query()->where('uuid', $orgUuid)->first();
            $orgRateCache[$orgUuid] = ($org !== null && $org->commission_percentage !== null)
                ? (float) $org->commission_percentage
                : $platformDefault;
        }
        return $orgRateCache[$orgUuid];
    }
}
