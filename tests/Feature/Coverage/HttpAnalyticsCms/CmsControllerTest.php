<?php

namespace Tests\Feature\Coverage\HttpAnalyticsCms;

use App\Models\CmsPage;
use App\Models\CmsSetting;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CmsControllerTest extends TestCase
{
    use CreatesPermittedAdmin;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAsPermittedAdmin(['cms' => ['view', 'create', 'update', 'delete']]);
    }

    private function createPage(array $attributes = []): CmsPage
    {
        return CmsPage::create(array_merge([
            'title' => 'About Us',
            'slug' => 'coverage-about',
            'content' => '<p>About</p>',
            'status' => CmsPage::STATUS_PUBLISHED,
            'show_in_footer' => true,
            'footer_column' => 'explore',
            'sort_order' => 1,
        ], $attributes));
    }

    private function footerPayload(array $overrides = []): array
    {
        return array_merge([
            'company_description' => 'PAEC makes events easy.',
            'contact_email' => 'hello@paec.com',
            'contact_phone' => '+63 900 000 0000',
            'contact_address' => 'Taguig City',
            'copyright' => '© 2026 PAEC',
            'explore_links' => [['label' => 'Events', 'href' => '/events']],
            'support_links' => [['label' => 'FAQ', 'href' => '/faq']],
        ], $overrides);
    }

    #[Test]
    public function publicShowReturnsPublishedPageBySlug(): void
    {
        $page = $this->createPage();

        $this->getJson('/api/v1/public/cms/pages/coverage-about')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.uuid', $page->uuid)
            ->assertJsonPath('data.title', 'About Us')
            ->assertJsonPath('data.content', '<p>About</p>');
    }

    #[Test]
    public function publicShowHidesDraftPages(): void
    {
        $this->createPage(['status' => CmsPage::STATUS_DRAFT]);

        $this->getJson('/api/v1/public/cms/pages/coverage-about')
            ->assertNotFound()
            ->assertExactJson(['success' => false, 'message' => 'Page not found']);
    }

    #[Test]
    public function storePageCreatesPageWithAuditColumnsAndDefaults(): void
    {
        $response = $this->withHeaders($this->adminHeaders())
            ->postJson('/api/v1/cms/pages', [
                'title' => 'Terms of Service',
                'slug' => 'coverage-terms',
                'status' => CmsPage::STATUS_DRAFT,
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'Terms of Service')
            ->assertJsonPath('data.slug', 'coverage-terms')
            ->assertJsonPath('data.content', null)
            ->assertJsonPath('data.show_in_footer', false)
            ->assertJsonPath('data.footer_column', null)
            ->assertJsonPath('data.sort_order', 0);

        $this->assertDatabaseHas('cms_pages', [
            'uuid' => $response->json('data.uuid'),
            'slug' => 'coverage-terms',
            'status' => CmsPage::STATUS_DRAFT,
            'created_by' => $this->admin->uuid,
            'updated_by' => $this->admin->uuid,
        ]);
    }

    #[Test]
    public function storePageStoresFooterPlacementFields(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->postJson('/api/v1/cms/pages', [
                'title' => 'Help Center',
                'slug' => 'coverage-help',
                'content' => '<p>Help</p>',
                'status' => CmsPage::STATUS_PUBLISHED,
                'show_in_footer' => true,
                'footer_column' => 'support',
                'sort_order' => 7,
            ])
            ->assertCreated()
            ->assertJsonPath('data.show_in_footer', true)
            ->assertJsonPath('data.footer_column', 'support')
            ->assertJsonPath('data.sort_order', 7);

        $this->assertDatabaseHas('cms_pages', [
            'slug' => 'coverage-help',
            'content' => '<p>Help</p>',
            'show_in_footer' => true,
            'footer_column' => 'support',
            'sort_order' => 7,
        ]);
    }

    #[Test]
    public function storePageRejectsDuplicateAndMalformedSlugs(): void
    {
        $this->createPage();
        $pagesBefore = CmsPage::query()->count();

        $this->withHeaders($this->adminHeaders())
            ->postJson('/api/v1/cms/pages', [
                'title' => 'Another About',
                'slug' => 'coverage-about',
                'status' => CmsPage::STATUS_DRAFT,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);

        $this->withHeaders($this->adminHeaders())
            ->postJson('/api/v1/cms/pages', [
                'title' => 'Bad Slug',
                'slug' => 'Bad Slug!',
                'status' => 'archived',
                'footer_column' => 'sidebar',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug', 'status', 'footer_column']);

        $this->assertSame($pagesBefore, CmsPage::query()->count());
    }

    #[Test]
    public function storePageAppendsSuffixWhenSlugIsTakenAfterValidation(): void
    {
        $inserted = false;

        // Simulate a concurrent request saving the same slug right after the unique rule passed.
        DB::listen(function (QueryExecuted $query) use (&$inserted) {
            if ($inserted || ! str_contains($query->sql, 'count(*) as aggregate from "cms_pages"')) {
                return;
            }

            $inserted = true;
            DB::table('cms_pages')->insert([
                ['uuid' => (string) Str::uuid(), 'title' => 'Race', 'slug' => 'coverage-race', 'status' => CmsPage::STATUS_DRAFT],
                ['uuid' => (string) Str::uuid(), 'title' => 'Race 1', 'slug' => 'coverage-race-1', 'status' => CmsPage::STATUS_DRAFT],
            ]);
        });

        $this->withHeaders($this->adminHeaders())
            ->postJson('/api/v1/cms/pages', [
                'title' => 'Race Winner',
                'slug' => 'coverage-race',
                'status' => CmsPage::STATUS_PUBLISHED,
            ])
            ->assertCreated()
            ->assertJsonPath('data.slug', 'coverage-race-2');

        $this->assertTrue($inserted);
        $this->assertDatabaseHas('cms_pages', ['title' => 'Race Winner', 'slug' => 'coverage-race-2']);
    }

    #[Test]
    public function updatePageAppendsSuffixWhenSlugIsTakenAfterValidation(): void
    {
        $page = $this->createPage();
        $inserted = false;

        DB::listen(function (QueryExecuted $query) use (&$inserted) {
            if ($inserted || ! str_contains($query->sql, 'count(*) as aggregate from "cms_pages"')) {
                return;
            }

            $inserted = true;
            DB::table('cms_pages')->insert([
                'uuid' => (string) Str::uuid(),
                'title' => 'Race',
                'slug' => 'coverage-renamed',
                'status' => CmsPage::STATUS_DRAFT,
            ]);
        });

        $this->withHeaders($this->adminHeaders())
            ->putJson('/api/v1/cms/pages/' . $page->uuid, [
                'title' => 'Renamed',
                'slug' => 'coverage-renamed',
                'status' => CmsPage::STATUS_PUBLISHED,
            ])
            ->assertOk()
            ->assertJsonPath('data.slug', 'coverage-renamed-1');

        $this->assertDatabaseHas('cms_pages', ['uuid' => $page->uuid, 'slug' => 'coverage-renamed-1']);
    }

    #[Test]
    public function storePageRejectsSlugOfSoftDeletedPage(): void
    {
        $this->createPage()->delete();

        $this->withHeaders($this->adminHeaders())
            ->postJson('/api/v1/cms/pages', [
                'title' => 'About Us Again',
                'slug' => 'coverage-about',
                'status' => CmsPage::STATUS_PUBLISHED,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    }

    #[Test]
    public function showPageReturnsPageOr404(): void
    {
        $page = $this->createPage();

        $this->withHeaders($this->adminHeaders())
            ->getJson('/api/v1/cms/pages/' . $page->uuid)
            ->assertOk()
            ->assertJsonPath('data.uuid', $page->uuid)
            ->assertJsonPath('data.slug', 'coverage-about');

        $this->withHeaders($this->adminHeaders())
            ->getJson('/api/v1/cms/pages/550e8400-e29b-41d4-a716-446655440000')
            ->assertNotFound();
    }

    #[Test]
    public function updatePageKeepsOwnSlugAndRecordsUpdater(): void
    {
        $page = $this->createPage(['created_by' => null, 'updated_by' => null]);

        $this->withHeaders($this->adminHeaders())
            ->putJson('/api/v1/cms/pages/' . $page->uuid, [
                'title' => 'About PAEC',
                'slug' => 'coverage-about',
                'content' => '<p>Updated</p>',
                'status' => CmsPage::STATUS_DRAFT,
                'show_in_footer' => false,
                'footer_column' => null,
                'sort_order' => 3,
            ])
            ->assertOk()
            ->assertJsonPath('data.title', 'About PAEC')
            ->assertJsonPath('data.slug', 'coverage-about')
            ->assertJsonPath('data.status', CmsPage::STATUS_DRAFT)
            ->assertJsonPath('data.show_in_footer', false)
            ->assertJsonPath('data.sort_order', 3);

        $this->assertDatabaseHas('cms_pages', [
            'uuid' => $page->uuid,
            'title' => 'About PAEC',
            'content' => '<p>Updated</p>',
            'updated_by' => $this->admin->uuid,
            'created_by' => null,
        ]);
    }

    #[Test]
    public function updatePageRejectsSlugOwnedByAnotherPage(): void
    {
        $this->createPage();
        $contact = $this->createPage(['title' => 'Contact', 'slug' => 'coverage-contact']);

        $this->withHeaders($this->adminHeaders())
            ->putJson('/api/v1/cms/pages/' . $contact->uuid, [
                'title' => 'Contact',
                'slug' => 'coverage-about',
                'status' => CmsPage::STATUS_PUBLISHED,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);

        $this->assertDatabaseHas('cms_pages', ['uuid' => $contact->uuid, 'slug' => 'coverage-contact']);
    }

    #[Test]
    public function updatePageReturns404ForUnknownPage(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->putJson('/api/v1/cms/pages/550e8400-e29b-41d4-a716-446655440000', [
                'title' => 'Ghost',
                'slug' => 'ghost',
                'status' => CmsPage::STATUS_DRAFT,
            ])
            ->assertNotFound();
    }

    #[Test]
    public function destroyPageSoftDeletesPage(): void
    {
        $page = $this->createPage();

        $this->withHeaders($this->adminHeaders())
            ->deleteJson('/api/v1/cms/pages/' . $page->uuid)
            ->assertOk()
            ->assertExactJson(['success' => true, 'message' => 'Page deleted successfully.']);

        $this->assertSoftDeleted('cms_pages', ['uuid' => $page->uuid]);

        $this->withHeaders($this->adminHeaders())
            ->deleteJson('/api/v1/cms/pages/' . $page->uuid)
            ->assertNotFound();
    }

    #[Test]
    public function updateFooterSettingsPersistsAllSettings(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->putJson('/api/v1/cms/footer', $this->footerPayload())
            ->assertOk()
            ->assertJsonPath('message', 'Footer settings updated successfully.')
            ->assertJsonPath('data.company_description', 'PAEC makes events easy.')
            ->assertJsonPath('data.contact_email', 'hello@paec.com')
            ->assertJsonPath('data.contact_phone', '+63 900 000 0000')
            ->assertJsonPath('data.contact_address', 'Taguig City')
            ->assertJsonPath('data.copyright', '© 2026 PAEC')
            ->assertJsonPath('data.explore_links', [['label' => 'Events', 'href' => '/events']])
            ->assertJsonPath('data.support_links', [['label' => 'FAQ', 'href' => '/faq']]);

        $this->assertSame('hello@paec.com', CmsSetting::getValue('footer_contact_email'));
        $this->assertSame([['label' => 'FAQ', 'href' => '/faq']], CmsSetting::getValue('footer_support_links'));
    }

    #[Test]
    public function updateFooterSettingsOverwritesExistingValues(): void
    {
        CmsSetting::setValue('footer_copyright', 'Old copyright');

        $this->withHeaders($this->adminHeaders())
            ->putJson('/api/v1/cms/footer', $this->footerPayload(['copyright' => 'New copyright']))
            ->assertOk()
            ->assertJsonPath('data.copyright', 'New copyright');

        $this->assertSame(1, CmsSetting::query()->where('key', 'footer_copyright')->count());
        $this->assertSame('New copyright', CmsSetting::getValue('footer_copyright'));
    }

    #[Test]
    public function updateFooterSettingsSanitizesLinks(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->putJson('/api/v1/cms/footer', $this->footerPayload([
                'explore_links' => [
                    ['label' => '  Events  ', 'href' => '  /events  '],
                    ['label' => 'No Href'],
                    ['label' => '   ', 'href' => '/blank-label'],
                    ['href' => '/missing-label'],
                    'not-an-array',
                ],
                'support_links' => 'not-a-list',
            ]))
            ->assertOk()
            ->assertJsonPath('data.explore_links', [
                ['label' => 'Events', 'href' => '/events'],
                ['label' => 'No Href', 'href' => '#'],
            ])
            ->assertJsonPath('data.support_links', []);

        $this->assertSame([], CmsSetting::getValue('footer_support_links'));
    }

    #[Test]
    public function updateFooterSettingsRequiresTextFields(): void
    {
        $this->withHeaders($this->adminHeaders())
            ->putJson('/api/v1/cms/footer', [
                'company_description' => '',
                'contact_email' => str_repeat('a', 256),
                'explore_links' => [['label' => str_repeat('x', 121), 'href' => '/x']],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'company_description',
                'contact_email',
                'contact_phone',
                'contact_address',
                'copyright',
                'explore_links.0.label',
            ]);

        $this->assertSame('inquire@paec.ph', CmsSetting::getValue('footer_contact_email'));
    }
}
