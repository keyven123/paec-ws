<?php

namespace App\Http\Controllers\Organizer;

use App\Http\Controllers\Controller;
use App\Services\Organizer\OrganizerContextService;
use App\Services\Organizer\OrganizerPermissionCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrganizerPermissionController extends Controller
{
    public function __construct(
        protected OrganizerContextService $organizerContext,
        protected OrganizerPermissionCatalogService $permissionCatalog,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $this->organizerContext->organizationUuidOrAbort();

        return response()->json([
            'success' => true,
            'data' => $this->permissionCatalog->getAssignablePermissions(),
        ]);
    }

    public function catalog(Request $request): JsonResponse
    {
        $this->organizerContext->organizationUuidOrAbort();

        return response()->json([
            'success' => true,
            'data' => $this->permissionCatalog->getCatalogRows(),
        ]);
    }
}
