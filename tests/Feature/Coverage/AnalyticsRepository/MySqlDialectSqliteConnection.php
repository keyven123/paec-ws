<?php

namespace Tests\Feature\Coverage\AnalyticsRepository;

use Closure;
use DateTimeImmutable;
use Illuminate\Database\SQLiteConnection;
use PDO;

/**
 * SQLite connection that understands the handful of MySQL date functions used by
 * AnalyticsRepository's weekly/monthly/yearly buckets, so those code paths can run
 * against the in-memory test database.
 *
 * - DATE_FORMAT, YEAR, WEEKDAY and DATE_SUB are registered as SQLite user functions
 *   with MySQL semantics (WEEKDAY: Monday = 0).
 * - SQLite cannot parse `INTERVAL <expr> DAY`, so it is rewritten to `<expr>` before
 *   execution; the DATE_SUB user function then subtracts that many days.
 */
class MySqlDialectSqliteConnection extends SQLiteConnection
{
    /**
     * When true, getDriverName() reports "mysql" so driver-specific SQL branches can be exercised.
     */
    public bool $reportMySqlDriver = false;

    public function getDriverName()
    {
        return $this->reportMySqlDriver ? 'mysql' : parent::getDriverName();
    }

    protected function run($query, $bindings, Closure $callback)
    {
        return parent::run(self::translate($query), $bindings, $callback);
    }

    public static function translate(string $query): string
    {
        return preg_replace('/INTERVAL\s+(WEEKDAY\([^()]*\))\s+DAY/i', '$1', $query);
    }

    public static function registerMySqlFunctions(PDO $pdo): void
    {
        $pdo->sqliteCreateFunction('DATE_FORMAT', static function ($value, $format) {
            if ($value === null) {
                return null;
            }

            $date = new DateTimeImmutable((string) $value);

            return preg_replace_callback('/%(.)/', static fn (array $m) => match ($m[1]) {
                'Y' => $date->format('Y'),
                'm' => $date->format('m'),
                'd' => $date->format('d'),
                'H' => $date->format('H'),
                'i' => $date->format('i'),
                's' => $date->format('s'),
                default => $m[1],
            }, (string) $format);
        }, 2);

        $pdo->sqliteCreateFunction('YEAR', static function ($value) {
            return $value === null ? null : (int) (new DateTimeImmutable((string) $value))->format('Y');
        }, 1);

        $pdo->sqliteCreateFunction('WEEKDAY', static function ($value) {
            return $value === null ? null : (int) (new DateTimeImmutable((string) $value))->format('N') - 1;
        }, 1);

        $pdo->sqliteCreateFunction('DATE_SUB', static function ($value, $days) {
            if ($value === null) {
                return null;
            }

            return (new DateTimeImmutable((string) $value))->modify('-'.(int) $days.' days')->format('Y-m-d');
        }, 2);
    }
}
