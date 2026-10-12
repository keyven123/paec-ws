<?php

namespace Tests\Feature\Coverage\Helpers;

use App\Exceptions\InvalidTokenException;
use App\Exceptions\TokenExpiredException;
use App\Exceptions\UnauthorizedException;
use App\Helpers\CsvHelper;
use App\Helpers\OrganizationHelper;
use App\Helpers\TokenParserHelper;
use App\Models\AdminUser;
use App\Models\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SmallHelpersCoverageTest extends TestCase
{
    private ?string $csvPath = null;

    protected function tearDown(): void
    {
        if ($this->csvPath !== null && is_file($this->csvPath)) {
            unlink($this->csvPath);
        }

        parent::tearDown();
    }

    #[Test]
    public function csvWithHeaderIsKeyedBySnakeCasedColumnNames(): void
    {
        $rows = $this->csvReader()->csvToArray($this->writeCsv("Full Name,City\nAna,Manila\nBen\n"));

        $this->assertSame([
            ['full_name' => 'Ana', 'city' => 'Manila'],
            ['full_name' => 'Ben', 'city' => ''],
        ], $rows);
    }

    #[Test]
    public function csvWithoutHeaderReturnsRawRows(): void
    {
        $rows = $this->csvReader()->csvToArray($this->writeCsv("Ana,Manila\n\"Ben, Jr.\",Cebu\n"), false);

        $this->assertSame([
            ['Ana', 'Manila'],
            ['Ben, Jr.', 'Cebu'],
        ], $rows);
    }

    #[Test]
    public function tenantOrganizationScopesQueriesToTheAdminsOrganization(): void
    {
        $this->actingAs((new AdminUser())->forceFill(['uuid' => 'admin-1', 'organization_uuid' => 'org-123']), 'admin');

        $query = OrganizationHelper::tenantOrganization(Event::query());

        $this->assertStringContainsString('"organization_uuid" = ?', $query->toSql());
        $this->assertSame(['org-123'], $query->getBindings());
    }

    #[Test]
    public function tenantOrganizationLeavesPlatformAdminQueriesUnscoped(): void
    {
        $this->actingAs((new AdminUser())->forceFill(['uuid' => 'admin-2', 'organization_uuid' => null]), 'admin');

        $query = OrganizationHelper::tenantOrganization(Event::query());

        $this->assertStringNotContainsString('organization_uuid', $query->toSql());
    }

    #[Test]
    public function tokenClaimsAreDecodedFromTheJwtPayload(): void
    {
        $claims = TokenParserHelper::getClaims($this->jwt(['sub' => 'user-1', 'exp' => time() + 600]));

        $this->assertSame('user-1', $claims->sub);
    }

    #[Test]
    public function aMissingTokenIsUnauthorized(): void
    {
        $this->expectException(UnauthorizedException::class);

        TokenParserHelper::getClaims(null);
    }

    #[Test]
    public function anUndecodableTokenIsInvalid(): void
    {
        $this->expectException(InvalidTokenException::class);

        TokenParserHelper::getClaims('not-a-jwt');
    }

    #[Test]
    public function anExpiredTokenIsRejected(): void
    {
        $this->expectException(TokenExpiredException::class);

        TokenParserHelper::getClaims($this->jwt(['sub' => 'user-1', 'exp' => time() - 60]));
    }

    private function jwt(array $payload): string
    {
        return 'header.' . base64_encode(json_encode($payload)) . '.signature';
    }

    private function csvReader(): object
    {
        return new class {
            use CsvHelper;
        };
    }

    private function writeCsv(string $contents): string
    {
        $this->csvPath = tempnam(sys_get_temp_dir(), 'paec-csv-');
        file_put_contents($this->csvPath, $contents);

        return $this->csvPath;
    }
}
