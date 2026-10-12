<?php

namespace Tests\Feature\Coverage\AnalyticsRepository;

use App\Http\Repositories\AnalyticsRepository;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

/**
 * csvRowToLine() is private and has no caller inside AnalyticsRepository, so it is exercised via reflection.
 */
class CsvRowToLineTest extends AnalyticsRepositoryTestCase
{
    #[Test]
    public function quotesEveryCellAndEscapesEmbeddedDoubleQuotes(): void
    {
        $method = new ReflectionMethod(AnalyticsRepository::class, 'csvRowToLine');

        $line = $method->invoke($this->repository(), ['Say "hi"', 'a,b', 12.5, null, true]);

        $this->assertSame('"Say ""hi""","a,b","12.5","","1"'."\n", $line);
    }
}
