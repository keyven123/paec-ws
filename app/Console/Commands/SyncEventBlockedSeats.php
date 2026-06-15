<?php

namespace App\Console\Commands;

use App\Models\Event;
use Illuminate\Console\Command;

class SyncEventBlockedSeats extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'event:sync-blocked-seats
                            {event : Event UUID or slug}
                            {--csv= : Path to CSV file with available seats (default: app/Console/data/beegees-available-seat.csv)}
                            {--log-rows : Log each CSV row and list any skipped or zero-seat rows}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Sync event blocked_seats: any venue seat not listed in the CSV is added to blocked_seats';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $eventIdentifier = $this->argument('event');
        $csvPath = $this->option('csv') ?? realpath(__DIR__ . '/../data/beegees-available-seat.csv') ?: base_path('app/Console/data/beegees-available-seat.csv');

        if (! is_file($csvPath)) {
            $this->error("CSV file not found: {$csvPath}");

            return self::FAILURE;
        }

        $event = Event::where('uuid', $eventIdentifier)
            ->orWhere('slug', $eventIdentifier)
            ->with(['venue.venueSeats'])
            ->first();

        if (! $event) {
            $this->error("Event not found: {$eventIdentifier}");

            return self::FAILURE;
        }

        $venue = $event->venue;
        if (! $venue) {
            $this->error('Event has no venue.');

            return self::FAILURE;
        }

        $result = $this->parseAvailableSeatsCsv($csvPath, (bool) $this->option('log-rows'));
        $availableKeys = $result['keys'];
        $this->info('Available seat keys from CSV (unique): ' . count($availableKeys));
        $this->info('Total seat entries from CSV (sum of all ranges): ' . $result['total_seat_entries']);

        if ($result['total_seat_entries'] !== count($availableKeys)) {
            $diff = $result['total_seat_entries'] - count($availableKeys);
            $this->warn(sprintf(
                'CSV seat count: %d total range seats vs %d unique keys (%d %s)',
                $result['total_seat_entries'],
                count($availableKeys),
                abs($diff),
                $diff > 0 ? 'duplicate seat positions in CSV' : 'keys not expanded from ranges'
            ));
        }
        $this->logCsvIssues($result);

        $venueSeats = $venue->venueSeats;
        $blockedUuids = [];

        foreach ($venueSeats as $seat) {
            $key = $this->seatKey($seat->category, $seat->row, (int) $seat->col);
            if (! isset($availableKeys[$key])) {
                $blockedUuids[] = $seat->uuid;
            }
        }

        $event->blocked_seats = array_values(array_unique($blockedUuids));
        $event->save();

        $this->info(sprintf(
            'Event "%s": %d venue seats, %d blocked (not in CSV), %d available.',
            $event->event_name,
            $venueSeats->count(),
            count($blockedUuids),
            $venueSeats->count() - count($blockedUuids)
        ));

        return self::SUCCESS;
    }

    /**
     * Parse CSV and return available seat keys plus debug info.
     * CSV columns: category, row, col (col can be a range e.g. "31-40" or "6.-15").
     *
     * @return array{keys: array<string, true>, total_seat_entries: int, skipped: array<int, string>, zero_seat_ranges: array<int, array{line: int, category: string, row: string, col: string}>}
     */
    private function parseAvailableSeatsCsv(string $csvPath, bool $verbose = false): array
    {
        $keys = [];
        $totalSeatEntries = 0;
        $skipped = [];
        $zeroSeatRanges = [];
        $lineNum = 1; // header

        $handle = fopen($csvPath, 'r');
        if ($handle === false) {
            return ['keys' => [], 'total_seat_entries' => 0, 'skipped' => [], 'zero_seat_ranges' => []];
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            return ['keys' => [], 'total_seat_entries' => 0, 'skipped' => [], 'zero_seat_ranges' => []];
        }

        // Strip BOM from first cell (Excel/Windows often saves UTF-8 CSV with BOM)
        $header = array_map(function ($cell) {
            return trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $cell));
        }, $header);

        $catIdx = array_search('category', $header, true);
        $rowIdx = array_search('row', $header, true);
        $colIdx = array_search('col', $header, true);
        if ($catIdx === false || $rowIdx === false || $colIdx === false) {
            fclose($handle);
            return ['keys' => [], 'total_seat_entries' => 0, 'skipped' => [], 'zero_seat_ranges' => []];
        }

        $maxIdx = max($catIdx, $rowIdx, $colIdx);

        while (($row = fgetcsv($handle)) !== false) {
            $lineNum++;

            if (count($row) <= $maxIdx) {
                $skipped[$lineNum] = 'Not enough columns (got ' . count($row) . ', need ' . ($maxIdx + 1) . ')';
                continue;
            }
            $category = trim((string) ($row[$catIdx] ?? ''));
            $rowName = trim((string) ($row[$rowIdx] ?? ''));
            $colValue = trim((string) ($row[$colIdx] ?? ''));

            if ($category === '' || $rowName === '' || $colValue === '') {
                $skipped[$lineNum] = sprintf('Empty field (category=%s, row=%s, col=%s)', var_export($category, true), var_export($rowName, true), var_export($colValue, true));
                continue;
            }

            $colNumbers = $this->parseColRange($colValue);
            if ($colNumbers === []) {
                $zeroSeatRanges[] = ['line' => $lineNum, 'category' => $category, 'row' => $rowName, 'col' => $colValue];
                continue;
            }

            $totalSeatEntries += count($colNumbers);
            if ($verbose) {
                $this->line(sprintf('  Line %d: %s | %s | %s => %d seats', $lineNum, $category, $rowName, $colValue, count($colNumbers)));
            }
            foreach ($colNumbers as $col) {
                $key = $this->seatKey($category, $rowName, $col);
                $keys[$key] = true;
            }
        }

        fclose($handle);

        return [
            'keys' => $keys,
            'total_seat_entries' => $totalSeatEntries,
            'skipped' => $skipped,
            'zero_seat_ranges' => $zeroSeatRanges,
        ];
    }

    /**
     * Output any CSV parsing issues (skipped lines, zero-seat ranges).
     *
     * @param array{keys: array<string, true>, total_seat_entries: int, skipped: array<int, string>, zero_seat_ranges: array<int, array{line: int, category: string, row: string, col: string}>} $result
     */
    private function logCsvIssues(array $result): void
    {
        if ($result['zero_seat_ranges'] !== []) {
            $this->warn('CSV rows with missing or invalid col range (0 seats added):');
            foreach ($result['zero_seat_ranges'] as $item) {
                $this->line(sprintf('  Line %d: category=%s, row=%s, col=%s', $item['line'], $item['category'], $item['row'], $item['col']));
            }
        }
        if ($result['skipped'] !== []) {
            $this->warn('CSV rows skipped:');
            foreach ($result['skipped'] as $line => $reason) {
                $this->line(sprintf('  Line %d: %s', $line, $reason));
            }
        }
    }

    /**
     * Parse col value: single number or range (e.g. "31-40", "6.-15").
     *
     * @return int[]
     */
    private function parseColRange(string $colValue): array
    {
        $colValue = str_replace('.', '', $colValue);
        if (str_contains($colValue, '-')) {
            [$start, $end] = array_map('intval', explode('-', $colValue, 2));
            if ($start > $end) {
                [$start, $end] = [$end, $start];
            }
            $result = [];
            for ($i = $start; $i <= $end; $i++) {
                $result[] = $i;
            }
            return $result;
        }

        $num = (int) $colValue;
        return $num > 0 ? [$num] : [];
    }

    /**
     * Normalized seat key for matching (case-insensitive category).
     */
    private function seatKey(?string $category, ?string $row, int $col): string
    {
        $cat = strtolower(trim((string) $category));
        $r = trim((string) $row);

        return "{$cat}|{$r}|{$col}";
    }
}
