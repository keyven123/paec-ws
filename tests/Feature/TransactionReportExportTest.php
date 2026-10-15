<?php

namespace Tests\Feature;

use App\Constants\GeneralConstants;
use App\Models\AdminUser;
use App\Models\Event;
use App\Models\EventTicket;
use App\Models\Organization;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\Transaction;
use App\Models\User;
use App\Services\XlsxWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Feature\Coverage\GapCheckout\GapCheckoutFixtures;
use Tests\TestCase;
use ZipArchive;

class TransactionReportExportTest extends TestCase
{
    use GapCheckoutFixtures;
    use RefreshDatabase;

    private const EXPORT = '/api/v1/transactions/export';

    private string $adminToken;

    private string $organizerToken;

    private Organization $merchantA;

    private Organization $merchantB;

    private Event $waterPark;

    private Event $arcade;

    private EventTicket $dayPass;

    private EventTicket $arcadePass;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 13:00:00');

        $this->merchantA = $this->createOrganization();
        $this->merchantB = $this->createOrganization();

        $superadminRole = Role::create([
            'name' => 'Super Admin',
            'code' => GeneralConstants::ROLES['SUPER_ADMIN']['name'],
            'is_admin' => true,
        ]);
        // fromUser() issues a token without signing in, so each request is authenticated by its own token.
        $this->adminToken = auth('admin')->fromUser($this->adminUser($superadminRole, null, 'admin@test.com'));

        $organizerRole = Role::create([
            'name' => 'Organizer',
            'code' => GeneralConstants::ROLES['ORGANIZER']['name'],
            'is_admin' => false,
        ]);
        $permission = Permission::create(['name' => 'Transactions', 'code' => 'transactions', 'available_access' => ['view']]);
        RolePermission::create([
            'role_uuid' => $organizerRole->uuid,
            'permission_uuid' => $permission->uuid,
            'access' => 'transactions-view',
        ]);
        $this->organizerToken = auth('admin')->fromUser($this->adminUser($organizerRole, $this->merchantA, 'organizer@test.com'));

        $this->waterPark = $this->createEvent(['event_name' => 'Water Park', 'organization_uuid' => $this->merchantA->uuid]);
        $this->arcade = $this->createEvent(['event_name' => 'Arcade', 'organization_uuid' => $this->merchantB->uuid]);
        $this->dayPass = $this->createEventTicket($this->waterPark, ['name' => 'Day Pass', 'price' => 300]);
        $this->arcadePass = $this->createEventTicket($this->arcade, ['name' => '1-Hour Pass', 'price' => 160]);
        $this->customer = $this->createCustomer([
            'first_name' => 'Juan',
            'last_name' => 'Dela Cruz',
            'email' => 'juan@test.com',
            'phone_number' => '+639171234567',
        ]);
    }

    private function adminUser(Role $role, ?Organization $organization, string $email): AdminUser
    {
        return AdminUser::create([
            'role_uuid' => $role->uuid,
            'organization_uuid' => $organization?->uuid,
            'email' => $email,
            'password' => 'password123',
            'first_name' => 'Report',
            'last_name' => 'Admin',
            'status' => GeneralConstants::GENERAL_STATUSES['ACTIVE'],
            'email_verified_at' => now(),
        ]);
    }

    private function transaction(Event $event, EventTicket $ticket, array $attributes, string $createdAt, string $visitDate, int $quantity = 2): Transaction
    {
        $transaction = Transaction::create(array_merge([
            'user_uuid' => $this->customer->uuid,
            'event_uuid' => $event->uuid,
            'organization_uuid' => $event->organization_uuid,
            'order_number' => 'OR-' . Str::upper(Str::random(8)),
            'payment_provider' => 'paynamics',
            'sub_total' => $ticket->price * $quantity,
            'discount' => 0,
            'promo_code_discount' => 0,
            'tax_amount' => 0,
            'total_amount' => $ticket->price * $quantity,
            'status' => 'completed',
            'payment_status' => Transaction::PAYMENT_STATUS['PAID'],
            'order_status' => Transaction::ORDER_STATUS['CONFIRMED'],
            'paid_at' => $createdAt,
        ], $attributes));
        $transaction->created_at = $createdAt;
        $transaction->save();

        $transaction->transactionOrders()->create([
            'user_uuid' => $this->customer->uuid,
            'event_ticket_uuid' => $ticket->uuid,
            'quantity' => $quantity,
            'price' => $ticket->price,
            'total_amount' => $ticket->price * $quantity,
            'valid_until' => $visitDate,
        ]);

        return $transaction;
    }

    private function export(array $query = [], ?string $token = null): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . ($token ?? $this->adminToken)])
            ->get(self::EXPORT . ($query !== [] ? '?' . http_build_query($query) : ''));
    }

    /**
     * Reads the downloaded workbook as rows of cell text, keyed by column letter.
     *
     * @return array{rows: list<array<string, string>>, sheet: string, workbook: string}
     */
    private function workbook(TestResponse $response): array
    {
        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive();
        $zip->open($path);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $workbook = $zip->getFromName('xl/workbook.xml');
        $this->assertNotFalse($zip->getFromName('xl/styles.xml'));
        $this->assertNotFalse($zip->getFromName('[Content_Types].xml'));
        $zip->close();
        unlink($path);

        $rows = [];
        foreach (simplexml_load_string($sheet)->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $cell) {
                $column = preg_replace('/\d+/', '', (string) $cell['r']);
                $cells[$column] = isset($cell->is) ? (string) $cell->is->t : (string) $cell->v;
            }
            $rows[] = $cells;
        }

        return ['rows' => $rows, 'sheet' => $sheet, 'workbook' => $workbook];
    }

    #[Test]
    public function it_exports_matching_transactions_as_an_excel_workbook(): void
    {
        $first = $this->transaction($this->waterPark, $this->dayPass, [
            'sub_total' => 600,
            'discount' => 60,
            'promo_code_discount' => 40,
            'tax_amount' => 12,
            'total_amount' => 512,
        ], '2026-10-05 09:30:00', '2026-10-20');
        $second = $this->transaction($this->waterPark, $this->dayPass, [
            'payment_provider' => null,
            'paid_at' => null,
        ], '2026-10-10 18:00:00', '2026-10-22', 1);
        $this->transaction($this->waterPark, $this->dayPass, [
            'order_status' => Transaction::ORDER_STATUS['CANCELLED'],
            'payment_status' => Transaction::PAYMENT_STATUS['CANCELLED'],
        ], '2026-10-11 10:00:00', '2026-10-23');
        $this->transaction($this->arcade, $this->arcadePass, [], '2026-10-12 10:00:00', '2026-10-24');
        $this->transaction($this->waterPark, $this->dayPass, [], '2026-09-30 23:00:00', '2026-10-25');

        $response = $this->export([
            'event_uuid' => $this->waterPark->uuid,
            'order_status' => 'confirmed',
            'date_type' => 'purchase',
            'start_date' => '2026-10-01',
            'end_date' => '2026-10-31',
        ]);

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('transactions_report_2026-10-15_130000.xlsx', $response->headers->get('Content-Disposition'));

        ['rows' => $rows, 'sheet' => $sheet, 'workbook' => $workbook] = $this->workbook($response);

        $this->assertSame('PAEC Transactions Report', $rows[0]['A']);
        $this->assertSame('Attraction: Water Park   ·   Purchase date: Oct 1, 2026 – Oct 31, 2026   ·   Status: Confirmed', $rows[1]['A']);
        $this->assertSame('Generated Oct 15, 2026 1:00 PM   ·   2 transactions', $rows[2]['A']);
        $this->assertSame(['A' => 'Order #', 'B' => 'Customer', 'C' => 'Email'], array_slice($rows[4], 0, 3));
        $this->assertSame('Paid At', $rows[4]['R']);

        $this->assertSame($second->order_number, $rows[5]['A']);
        $this->assertSame('', $rows[5]['I'] ?? '');
        $this->assertSame('', $rows[5]['R'] ?? '');

        $firstRow = $rows[6];
        $this->assertSame($first->order_number, $firstRow['A']);
        $this->assertSame('Juan Dela Cruz', $firstRow['B']);
        $this->assertSame('juan@test.com', $firstRow['C']);
        $this->assertSame('+639171234567', $firstRow['D']);
        $this->assertSame('Water Park', $firstRow['E']);
        $this->assertSame('2 × Day Pass', $firstRow['F']);
        $this->assertSame('2', $firstRow['G']);
        $this->assertSame('46315', $firstRow['H']);
        $this->assertSame('Paynamics', $firstRow['I']);
        $this->assertSame('Paid', $firstRow['J']);
        $this->assertSame('Confirmed', $firstRow['K']);
        $this->assertSame(['L' => '600', 'M' => '60', 'N' => '40', 'O' => '12', 'P' => '512'], array_intersect_key($firstRow, array_flip(['L', 'M', 'N', 'O', 'P'])));
        $this->assertEqualsWithDelta(46300 + 9.5 / 24, (float) $firstRow['Q'], 0.0001);

        $totals = $rows[7];
        $this->assertSame('Total', $totals['A']);
        $this->assertSame('2 transactions', $totals['F']);
        $this->assertSame('3', $totals['G']);
        $this->assertSame('812', $totals['P']);
        $this->assertCount(8, $rows);

        $this->assertStringContainsString('<autoFilter ref="A5:R8"/>', $sheet);
        $this->assertStringContainsString('state="frozen"', $sheet);
        $this->assertStringContainsString("'Transactions'!\$A\$5:\$R\$8", $workbook);
    }

    #[Test]
    public function it_filters_by_date_of_visit(): void
    {
        $inRange = $this->transaction($this->waterPark, $this->dayPass, [], '2026-09-01 10:00:00', '2026-11-05');
        $this->transaction($this->waterPark, $this->dayPass, [], '2026-11-04 10:00:00', '2026-11-20');

        $rows = $this->workbook($this->export([
            'date_type' => 'visit',
            'start_date' => '2026-11-01',
            'end_date' => '2026-11-10',
        ])->assertOk())['rows'];

        $this->assertSame('Attraction: All attractions   ·   Date of visit: Nov 1, 2026 – Nov 10, 2026   ·   Status: All statuses', $rows[1]['A']);
        $this->assertSame($inRange->order_number, $rows[5]['A']);
        $this->assertSame('1 transaction', $rows[6]['F']);
    }

    #[Test]
    public function it_describes_open_ended_date_ranges(): void
    {
        $this->transaction($this->waterPark, $this->dayPass, [], '2026-10-05 10:00:00', '2026-10-20');

        $from = $this->workbook($this->export(['start_date' => '2026-10-01'])->assertOk())['rows'];
        $this->assertStringContainsString('Purchase date: From Oct 1, 2026', $from[1]['A']);

        $until = $this->workbook($this->export(['end_date' => '2026-10-31'])->assertOk())['rows'];
        $this->assertStringContainsString('Purchase date: Until Oct 31, 2026', $until[1]['A']);

        $any = $this->workbook($this->export()->assertOk())['rows'];
        $this->assertStringContainsString('Purchase date: Any date', $any[1]['A']);
        $this->assertSame('1 transaction', $any[6]['F']);
    }

    #[Test]
    public function an_empty_report_still_has_headers_and_totals(): void
    {
        ['rows' => $rows, 'sheet' => $sheet] = $this->workbook($this->export(['order_status' => 'pending'])->assertOk());

        $this->assertSame('Order #', $rows[4]['A']);
        $this->assertSame('0 transactions', $rows[5]['F']);
        $this->assertSame('0', $rows[5]['P']);
        $this->assertStringContainsString('<autoFilter ref="A5:R6"/>', $sheet);
    }

    #[Test]
    public function merchants_only_export_their_own_transactions_and_attractions(): void
    {
        $own = $this->transaction($this->waterPark, $this->dayPass, [], '2026-10-05 10:00:00', '2026-10-20');
        $this->transaction($this->arcade, $this->arcadePass, [], '2026-10-05 11:00:00', '2026-10-20');

        $rows = $this->workbook($this->export([], $this->organizerToken)->assertOk())['rows'];
        $this->assertSame($own->order_number, $rows[5]['A']);
        $this->assertSame('1 transaction', $rows[6]['F']);

        $this->withHeaders(['Authorization' => 'Bearer ' . $this->organizerToken])
            ->getJson(self::EXPORT . '/attractions')
            ->assertOk()
            ->assertExactJson(['data' => [['uuid' => $this->waterPark->uuid, 'name' => 'Water Park']]]);
    }

    #[Test]
    public function platform_admins_can_pick_any_attraction_with_transactions(): void
    {
        $this->transaction($this->waterPark, $this->dayPass, [], '2026-10-05 10:00:00', '2026-10-20');
        $this->transaction($this->arcade, $this->arcadePass, [], '2026-10-05 11:00:00', '2026-10-20');
        $this->createEvent(['event_name' => 'No Sales Yet']);

        $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
            ->getJson(self::EXPORT . '/attractions')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Arcade')
            ->assertJsonPath('data.1.name', 'Water Park');
    }

    #[Test]
    public function it_validates_the_filters(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer ' . $this->adminToken])
            ->getJson(self::EXPORT . '?' . http_build_query([
                'event_uuid' => (string) Str::uuid(),
                'order_status' => 'refunded',
                'date_type' => 'shipping',
                'start_date' => '2026-10-31',
                'end_date' => '2026-10-01',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('errors.event_uuid.0', 'The selected attraction no longer exists.')
            ->assertJsonPath('errors.end_date.0', 'The end date must be on or after the start date.')
            ->assertJsonValidationErrors(['order_status', 'date_type']);
    }

    #[Test]
    public function the_export_needs_a_signed_in_admin(): void
    {
        $this->getJson(self::EXPORT)->assertStatus(401);
        $this->getJson(self::EXPORT . '/attractions')->assertStatus(401);
    }

    #[Test]
    public function the_writer_handles_plain_sheets_and_unsafe_text(): void
    {
        $this->assertSame(['A', 'Z', 'AA', 'ZZ', 'AAA'], array_map(XlsxWriter::columnLetter(...), [0, 25, 26, 701, 702]));

        $writer = new XlsxWriter('[]:*?/\\');
        $writer->addRow(["Tab\tand bell\x07 <b>&</b>", null, 3.5]);
        $writer->addRow([[null, XlsxWriter::STYLE_HEADER]]);
        $path = tempnam(sys_get_temp_dir(), 'xlsx-test-');
        $writer->save($path);

        $zip = new ZipArchive();
        $zip->open($path);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $workbook = $zip->getFromName('xl/workbook.xml');
        $zip->close();
        unlink($path);

        $this->assertStringContainsString('<t xml:space="preserve">Tab' . "\t" . 'and bell &lt;b&gt;&amp;&lt;/b&gt;</t>', $sheet);
        $this->assertStringContainsString('<c r="C1"><v>3.5</v></c>', $sheet);
        $this->assertStringContainsString('<c r="A2" s="3"/>', $sheet);
        $this->assertStringContainsString('<sheetView workbookViewId="0"/>', $sheet);
        $this->assertStringNotContainsString('<cols>', $sheet);
        $this->assertStringNotContainsString('autoFilter', $sheet);
        $this->assertStringContainsString('<sheet name="Sheet1"', $workbook);
        $this->assertStringNotContainsString('definedNames', $workbook);
    }

    #[Test]
    public function the_writer_reports_a_file_it_cannot_create(): void
    {
        $this->expectException(RuntimeException::class);

        (new XlsxWriter('Report'))->save(sys_get_temp_dir());
    }
}
