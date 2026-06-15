<?php

namespace App\Http\Controllers;

use App\Exceptions\NoOrganizationPlatformComFoundException;
use App\Http\Repositories\OrganizationPlatformComRepository;
use App\Http\Requests\OrganizationPlatformCom\ListOrganizationPlatformComRequest;
use App\Http\Resources\OrganizationPlatformComResource;
use Illuminate\Http\JsonResponse;

class OrganizationPlatformComController extends Controller
{
    public function __construct(
        protected OrganizationPlatformComRepository $organizationPlatformComRepository
    ) {
    }

    /**
     * List platform commission change logs.
     */
    public function index(ListOrganizationPlatformComRequest $request): JsonResponse
    {
        $perPage = $request->integer('per_page', 15);
        $filters = $request->validated();

        if ($request->boolean('platform_default')) {
            $filters['organization_uuid'] = null;
        }

        unset($filters['platform_default']);

        $list = $this->organizationPlatformComRepository->getAll($filters);

        return OrganizationPlatformComResource::collection($list->paginate($perPage))->response();
    }

    /**
     * Display a single commission log entry.
     */
    public function show(string $uuid): JsonResponse
    {
        try {
            $row = $this->organizationPlatformComRepository->fetchOrThrow('uuid', $uuid);

            return (new OrganizationPlatformComResource($row))->response();
        } catch (NoOrganizationPlatformComFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Organization platform commission log not found',
            ], 404);
        }
    }
}
