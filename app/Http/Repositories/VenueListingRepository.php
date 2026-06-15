<?php

namespace App\Http\Repositories;

use App\Exceptions\NoVenueListingFoundException;
use App\Helpers\GeneralHelper;
use App\Models\VenueListing;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class VenueListingRepository
{
    public function __construct(protected VenueListing $venueListing)
    {
    }

    /**
     * @param array $filters
     * @return Builder
     */
    public function getAll(array $filters): Builder
    {
        return $this->venueListing
            ->with(['organization', 'featuredImage', 'gallery'])
            ->filters($filters)
            ->orderByDesc('updated_at');
    }

    /**
     * @param array $filters
     * @return Builder
     */
    public function getPublicListings(array $filters): Builder
    {
        unset($filters['status']);

        return $this->venueListing
            ->with(['featuredImage', 'gallery'])
            ->whereIn('status', [
                VenueListing::STATUSES['PUBLISHED'],
                VenueListing::STATUSES['APPROVED'],
            ])
            ->filters($filters)
            ->orderByDesc('is_featured')
            ->orderByDesc('rating')
            ->orderBy('name');
    }

    /**
     * @param string $key
     * @param string $value
     * @return VenueListing
     * @throws NoVenueListingFoundException
     */
    public function fetchOrThrow(string $key, string $value): VenueListing
    {
        $venueListing = $this->venueListing->where($key, $value)->first();

        if (is_null($venueListing)) {
            throw new NoVenueListingFoundException();
        }

        return $venueListing;
    }

    /**
     * @param string $slug
     * @return VenueListing
     * @throws NoVenueListingFoundException
     */
    public function fetchPublicBySlug(string $slug): VenueListing
    {
        $venueListing = $this->venueListing
            ->with(['featuredImage', 'gallery'])
            ->where('slug', $slug)
            ->whereIn('status', [
                VenueListing::STATUSES['PUBLISHED'],
                VenueListing::STATUSES['APPROVED'],
            ])
            ->first();

        if (is_null($venueListing)) {
            throw new NoVenueListingFoundException();
        }

        return $venueListing;
    }

    /**
     * @param array $payload
     * @return VenueListing
     */
    public function create(array $payload): VenueListing
    {
        $data = GeneralHelper::unsetUnknownAndNullFields($payload, VenueListing::DATA);

        if (empty($data['slug']) && !empty($data['name'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        return $this->venueListing->create($data);
    }

    /**
     * @param VenueListing $venueListing
     * @param array $payload
     * @return bool|VenueListing
     */
    public function update(VenueListing $venueListing, array $payload): bool|VenueListing
    {
        $data = GeneralHelper::unsetUnknownAndNullFields($payload, VenueListing::DATA);

        if (!empty($data['slug'])) {
            $data['slug'] = GeneralHelper::generateSlug($data['slug']);
        }

        return $venueListing->update($data);
    }

    /**
     * @param VenueListing $venueListing
     * @return void
     */
    public function delete(VenueListing $venueListing): void
    {
        $venueListing->delete();
    }

    /**
     * @param string|null $organizationUuid
     * @return array<string, int>
     */
    public function getAdminStats(?string $organizationUuid = null): array
    {
        $base = $this->venueListing->query();

        if ($organizationUuid) {
            $base->where('organization_uuid', $organizationUuid);
        }

        return [
            'total' => (clone $base)->count(),
            'published' => (clone $base)->where('status', VenueListing::STATUSES['PUBLISHED'])->count(),
            'pending' => (clone $base)->where('status', VenueListing::STATUSES['PENDING'])->count(),
            'total_inquiries' => (int) (clone $base)->sum('inquiries_count'),
        ];
    }
}
