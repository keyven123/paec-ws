<?php

namespace App\Http\Repositories;

use App\Exceptions\NoOrganizationPlatformComFoundException;
use App\Helpers\GeneralHelper;
use App\Models\OrganizationPlatformCom;
use Illuminate\Contracts\Database\Eloquent\Builder;

class OrganizationPlatformComRepository
{
    public function __construct(protected OrganizationPlatformCom $organizationPlatformCom)
    {
    }

    /**
     * @param array $filters
     * @return Builder
     */
    public function getAll(array $filters): Builder
    {
        return $this->organizationPlatformCom
            ->with(['creator', 'organization'])
            ->filters($filters)
            ->orderBy('created_at', 'desc');
    }

    /**
     * @param string $key
     * @param string $value
     * @return OrganizationPlatformCom
     * @throws NoOrganizationPlatformComFoundException
     */
    public function fetchOrThrow(string $key, string $value): OrganizationPlatformCom
    {
        $row = $this->organizationPlatformCom
            ->with(['creator', 'organization'])
            ->where($key, $value)
            ->first();

        if (is_null($row)) {
            throw new NoOrganizationPlatformComFoundException();
        }

        return $row;
    }

    /**
     * @param array $payload
     * @return OrganizationPlatformCom
     */
    public function create(array $payload): OrganizationPlatformCom
    {
        $rowPayload = GeneralHelper::unsetUnknownAndNullFields($payload, OrganizationPlatformCom::DATA);

        foreach (['previous_coms', 'organization_uuid', 'created_by'] as $nullableField) {
            if (array_key_exists($nullableField, $payload) && $payload[$nullableField] === null) {
                $rowPayload[$nullableField] = null;
            }
        }

        return $this->organizationPlatformCom->create($rowPayload);
    }
}
