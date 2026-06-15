<?php

namespace App\Support;

use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class VenueListingPackageHelper
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function basePackages(float|int|string $pricePerEvent = 0): array
    {
        $price = (float) $pricePerEvent;

        return self::normalizeList([
            [
                'id' => 'half-day-morning',
                'label' => 'Half day (Morning)',
                'priceFrom' => $price > 0 ? round($price * 0.55) : 0,
                'note' => 'Morning block · final rate varies by date & setup',
                'start_time' => '06:00',
                'end_time' => '15:00',
                'crosses_midnight' => false,
            ],
            [
                'id' => 'half-day-afternoon',
                'label' => 'Half day (Afternoon)',
                'priceFrom' => $price > 0 ? round($price * 0.55) : 0,
                'note' => 'Afternoon / evening block · final rate varies by date & setup',
                'start_time' => '17:00',
                'end_time' => '01:00',
                'crosses_midnight' => true,
            ],
            [
                'id' => 'full-day',
                'label' => 'Full-day (8 hrs)',
                'priceFrom' => $price,
                'note' => 'Full-day package · final rate varies by date & setup',
                'start_time' => '07:00',
                'end_time' => '21:00',
                'crosses_midnight' => false,
            ],
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $packages
     * @return array<int, array<string, mixed>>
     */
    public static function normalizeList(?array $packages): array
    {
        if (empty($packages)) {
            return [];
        }

        $normalized = [];
        foreach (array_values($packages) as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $normalized[] = self::normalizeItem($item, $index);
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    public static function normalizeItem(array $item, ?int $fallbackSort = null): array
    {
        $start = self::normalizeTime((string) ($item['start_time'] ?? '09:00'));
        $end = self::normalizeTime((string) ($item['end_time'] ?? '17:00'));
        $crossesMidnight = array_key_exists('crosses_midnight', $item)
            ? (bool) $item['crosses_midnight']
            : self::inferCrossesMidnight($start, $end);

        $id = trim((string) ($item['id'] ?? ''));
        if ($id === '') {
            $id = Str::slug((string) ($item['label'] ?? 'package')) . '-' . Str::random(6);
        }

        $priceFrom = $item['priceFrom'] ?? $item['price_from'] ?? 0;

        return [
            'id' => $id,
            'label' => trim((string) ($item['label'] ?? 'Package')),
            'priceFrom' => (float) $priceFrom,
            'note' => trim((string) ($item['note'] ?? '')),
            'start_time' => $start,
            'end_time' => $end,
            'crosses_midnight' => $crossesMidnight,
            'time_label' => self::formatTimeLabel($start, $end, $crossesMidnight),
            'sort_order' => isset($item['sort_order']) ? (int) $item['sort_order'] : ($fallbackSort ?? 0),
        ];
    }

    public static function normalizeTime(string $time): string
    {
        $time = trim($time);
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $time, $matches)) {
            return sprintf('%02d:%02d', (int) $matches[1], (int) $matches[2]);
        }

        throw ValidationException::withMessages([
            'packages' => ['Each package must use a valid start and end time (HH:MM).'],
        ]);
    }

    public static function inferCrossesMidnight(string $start, string $end): bool
    {
        return self::minutesFromMidnight($end) <= self::minutesFromMidnight($start);
    }

    public static function formatTimeLabel(string $start, string $end, bool $crossesMidnight): string
    {
        $suffix = $crossesMidnight ? ' (next day)' : '';

        return self::formatTime12h($start) . ' – ' . self::formatTime12h($end) . $suffix;
    }

    public static function formatTime12h(string $time): string
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $ampm = $hour >= 12 ? 'PM' : 'AM';
        $hour12 = $hour % 12;
        if ($hour12 === 0) {
            $hour12 = 12;
        }

        return sprintf('%d:%02d %s', $hour12, $minute, $ampm);
    }

    private static function minutesFromMidnight(string $time): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return ($hour * 60) + $minute;
    }
}
