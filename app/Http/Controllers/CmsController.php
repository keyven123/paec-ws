<?php

namespace App\Http\Controllers;

use App\Models\CmsPage;
use App\Models\CmsSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CmsController extends Controller
{
    public function publicFooter(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => CmsSetting::footerPayload(),
        ]);
    }

    public function publicPages(): JsonResponse
    {
        $pages = CmsPage::query()
            ->where('status', CmsPage::STATUS_PUBLISHED)
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get(['uuid', 'title', 'slug', 'show_in_footer', 'footer_column']);

        return response()->json([
            'success' => true,
            'data' => $pages,
        ]);
    }

    public function publicShow(string $slug): JsonResponse
    {
        $page = CmsPage::query()
            ->where('slug', $slug)
            ->where('status', CmsPage::STATUS_PUBLISHED)
            ->first();

        if (!$page) {
            return response()->json([
                'success' => false,
                'message' => 'Page not found',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $page,
        ]);
    }

    public function indexPages(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in([CmsPage::STATUS_DRAFT, CmsPage::STATUS_PUBLISHED])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $perPage = min(100, max(1, (int) ($validated['per_page'] ?? 20)));

        $paginator = CmsPage::query()
            ->filters($validated)
            ->orderBy('sort_order')
            ->orderBy('title')
            ->paginate($perPage);

        return response()->json($paginator);
    }

    public function storePage(Request $request): JsonResponse
    {
        $validated = $this->validatePage($request);

        $page = CmsPage::query()->create([
            ...$this->pagePayload($request, $validated),
            'slug' => $this->uniqueSlug($validated['slug']),
            'created_by' => auth('admin')->user()?->uuid,
            'updated_by' => auth('admin')->user()?->uuid,
        ]);

        return response()->json(['data' => $page], 201);
    }

    public function showPage(string $uuid): JsonResponse
    {
        $page = CmsPage::query()->where('uuid', $uuid)->firstOrFail();

        return response()->json(['data' => $page]);
    }

    public function updatePage(Request $request, string $uuid): JsonResponse
    {
        $page = CmsPage::query()->where('uuid', $uuid)->firstOrFail();
        $validated = $this->validatePage($request, $page->uuid);

        $page->update([
            ...$this->pagePayload($request, $validated),
            'slug' => $this->uniqueSlug($validated['slug'], $page->uuid),
            'updated_by' => auth('admin')->user()?->uuid,
        ]);

        return response()->json(['data' => $page->fresh()]);
    }

    public function destroyPage(string $uuid): JsonResponse
    {
        $page = CmsPage::query()->where('uuid', $uuid)->firstOrFail();
        $page->delete();

        return response()->json([
            'success' => true,
            'message' => 'Page deleted successfully.',
        ]);
    }

    public function showFooterSettings(): JsonResponse
    {
        return response()->json([
            'data' => CmsSetting::footerPayload(),
        ]);
    }

    public function updateFooterSettings(Request $request): JsonResponse
    {
        $payload = [
            'company_description' => (string) $request->input('company_description', ''),
            'contact_email' => (string) $request->input('contact_email', ''),
            'contact_phone' => (string) $request->input('contact_phone', ''),
            'contact_address' => (string) $request->input('contact_address', ''),
            'copyright' => (string) $request->input('copyright', ''),
            'explore_links' => $this->sanitizeFooterLinks($request->input('explore_links', [])),
            'support_links' => $this->sanitizeFooterLinks($request->input('support_links', [])),
        ];

        $validated = validator($payload, [
            'company_description' => ['required', 'string', 'max:2000'],
            'contact_email' => ['required', 'string', 'max:255'],
            'contact_phone' => ['required', 'string', 'max:100'],
            'contact_address' => ['required', 'string', 'max:500'],
            'copyright' => ['required', 'string', 'max:255'],
            'explore_links' => ['present', 'array'],
            'explore_links.*.label' => ['required', 'string', 'max:120'],
            'explore_links.*.href' => ['required', 'string', 'max:500'],
            'support_links' => ['present', 'array'],
            'support_links.*.label' => ['required', 'string', 'max:120'],
            'support_links.*.href' => ['required', 'string', 'max:500'],
        ])->validate();

        CmsSetting::setValue('footer_company_description', $validated['company_description']);
        CmsSetting::setValue('footer_contact_email', $validated['contact_email']);
        CmsSetting::setValue('footer_contact_phone', $validated['contact_phone']);
        CmsSetting::setValue('footer_contact_address', $validated['contact_address']);
        CmsSetting::setValue('footer_copyright', $validated['copyright']);
        CmsSetting::setValue('footer_explore_links', $validated['explore_links']);
        CmsSetting::setValue('footer_support_links', $validated['support_links']);

        return response()->json([
            'data' => CmsSetting::footerPayload(),
            'message' => 'Footer settings updated successfully.',
        ]);
    }

    private function pagePayload(Request $request, array $validated): array
    {
        return [
            'title' => $validated['title'],
            'content' => $validated['content'] ?? null,
            'status' => $validated['status'],
            'show_in_footer' => $request->boolean('show_in_footer'),
            'footer_column' => $validated['footer_column'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
        ];
    }

    private function validatePage(Request $request, ?string $ignoreUuid = null): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'slug' => [
                'required',
                'string',
                'max:200',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('cms_pages', 'slug')->ignore($ignoreUuid, 'uuid'),
            ],
            'content' => ['nullable', 'string'],
            'status' => ['required', Rule::in([CmsPage::STATUS_DRAFT, CmsPage::STATUS_PUBLISHED])],
            'show_in_footer' => ['sometimes', 'boolean'],
            'footer_column' => ['nullable', Rule::in(CmsPage::FOOTER_COLUMNS)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:9999'],
        ]);
    }

    private function uniqueSlug(string $slug, ?string $ignoreUuid = null): string
    {
        $base = Str::slug($slug);
        $candidate = $base;
        $suffix = 1;

        while (
            CmsPage::query()
                ->where('slug', $candidate)
                ->when($ignoreUuid, fn ($q) => $q->where('uuid', '!=', $ignoreUuid))
                ->exists()
        ) {
            $candidate = $base . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function sanitizeFooterLinks(mixed $links): array
    {
        if (!is_array($links)) {
            return [];
        }

        return collect($links)
            ->map(function ($link) {
                if (!is_array($link)) {
                    return null;
                }

                $label = trim((string) ($link['label'] ?? ''));
                $href = trim((string) ($link['href'] ?? ''));

                if ($label === '') {
                    return null;
                }

                return [
                    'label' => $label,
                    'href' => $href !== '' ? $href : '#',
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
